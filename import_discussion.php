<?php
/**
 * Импорт комментариев из экспорта группы обсуждений Telegram Desktop.
 * Положи discussion_export.json рядом со скриптом и открой в браузере.
 * После успешного импорта удали оба файла с сервера.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$isCli = PHP_SAPI === 'cli';
$nl    = $isCli ? "\n" : "<br>\n";

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>\n";
}

function out(string $s): void {
    global $nl;
    echo $s . $nl;
}

// ─── Путь к файлу экспорта ────────────────────────────────────────────────────
$jsonFile = PHP_SAPI === 'cli'
    ? ($argv[1] ?? __DIR__ . '/discussion_export.json')
    : __DIR__ . '/discussion_export.json';

if (!file_exists($jsonFile)) {
    out("Файл не найден: $jsonFile");
    out("Загрузи discussion_export.json в папку блога на сервере.");
    exit;
}

$raw  = file_get_contents($jsonFile);
$data = json_decode($raw, true);
if (!$data || empty($data['messages'])) {
    die("Не удалось разобрать JSON или нет сообщений.\n");
}

$groupId = (int)$data['id']; // 1598585079
out("Группа: {$data['name']} (id=$groupId)");
out("Сообщений в экспорте: " . count($data['messages']));
out("");

// ─── Вспомогательные функции ──────────────────────────────────────────────────

/** Извлекает числовой user_id из строки вида "user123456" или "channel123456" */
function parseFromId(string $fromId): ?int {
    if (preg_match('/^user(\d+)$/', $fromId, $m))    return (int)$m[1];
    if (preg_match('/^channel(\d+)$/', $fromId, $m)) return (int)$m[1];
    return null;
}

/** Склеивает текст из массива сегментов (или возвращает строку как есть) */
function extractText($text): string {
    if (is_string($text)) return $text;
    if (!is_array($text))  return '';
    $result = '';
    foreach ($text as $part) {
        if (is_string($part))          $result .= $part;
        elseif (is_array($part))       $result .= $part['text'] ?? '';
    }
    return $result;
}

/** Конвертирует text_entities экспорта в формат Bot API entities (для хранения) */
function convertEntities(array $textEntities, string $plainText): ?string {
    // Экспорт уже содержит текстовые сущности, но без offset/length.
    // Вычисляем offset/length по позиции в тексте.
    $entities = [];
    $pos = 0;
    foreach ($textEntities as $ent) {
        $entText = $ent['text'] ?? '';
        $type    = $ent['type'] ?? 'plain';
        if ($type === 'plain' || $entText === '') {
            $pos += mb_strlen($entText);
            continue;
        }
        $offset = mb_strpos($plainText, $entText, $pos);
        if ($offset === false) {
            $pos += mb_strlen($entText);
            continue;
        }
        $apiType = match($type) {
            'bold'          => 'bold',
            'italic'        => 'italic',
            'underline'     => 'underline',
            'strikethrough' => 'strikethrough',
            'code'          => 'code',
            'pre'           => 'pre',
            'link'          => 'url',
            'text_link'     => 'text_link',
            'mention'       => 'mention',
            'hashtag'       => 'hashtag',
            default         => null,
        };
        if ($apiType) {
            $e = ['offset' => $offset, 'length' => mb_strlen($entText), 'type' => $apiType];
            if ($type === 'text_link' && !empty($ent['href'])) $e['url'] = $ent['href'];
            $entities[] = $e;
        }
        $pos = $offset + mb_strlen($entText);
    }
    return $entities ? json_encode($entities, JSON_UNESCAPED_UNICODE) : null;
}

// ─── Строим индекс всех сообщений и определяем "якоря" ───────────────────────

$pdo      = db();
$messages = [];   // id → message
$anchors  = [];   // id → post_id (для авто-пересланных постов канала)

$channelIdStr = 'channel' . CHANNEL_ID; // "channel1665934953"

// ─── Диагностика БД ───────────────────────────────────────────────────────────
out("Диагностика:");
out("  CHANNEL_ID = " . CHANNEL_ID);
out("  channelIdStr = $channelIdStr");
$diagStmt = $pdo->query("SELECT COUNT(*) FROM tg_posts WHERE channel_id = " . (int)CHANNEL_ID);
out("  Постов в БД для этого канала: " . $diagStmt->fetchColumn());
$diagStmt2 = $pdo->query("SELECT DISTINCT channel_id FROM tg_posts LIMIT 10");
out("  channel_id в БД: " . implode(', ', $diagStmt2->fetchAll(PDO::FETCH_COLUMN)));
$diagStmt3 = $pdo->query("SELECT MIN(post_date), MAX(post_date) FROM tg_posts WHERE channel_id = " . (int)CHANNEL_ID);
$row = $diagStmt3->fetch(PDO::FETCH_NUM);
out("  Диапазон дат постов: {$row[0]} — {$row[1]}");

// Подсчитаем сколько якорей есть в экспорте
$anchorCount = 0;
foreach ($data['messages'] as $msg) {
    if (($msg['type'] ?? '') !== 'message') continue;
    $fwd = $msg['forwarded_from_id'] ?? null;
    $fid = $msg['from_id'] ?? '';
    if ($fwd === $channelIdStr && !preg_match('/^user/', $fid)) $anchorCount++;
}
out("  Якорей в экспорте (авто-пересланных из канала): $anchorCount");
out("");

foreach ($data['messages'] as $msg) {
    if (($msg['type'] ?? '') !== 'message') continue;
    $messages[(int)$msg['id']] = $msg;
}

out("Ищем посты-якоря (авто-пересланные из канала)...");

foreach ($messages as $msgId => $msg) {
    $fwdFromId = $msg['forwarded_from_id'] ?? null;
    // Якорь = авто-переслан из нашего канала (но не от бота)
    if ($fwdFromId !== $channelIdStr) continue;
    // Пропускаем пересылки от бота (TGLenta) — они дубликаты
    $fromId = $msg['from_id'] ?? '';
    if (preg_match('/^user/', $fromId)) continue; // бот тоже user*, пропускаем

    $date = (int)$msg['date_unixtime'];
    $stmt = $pdo->prepare(
        "SELECT id FROM tg_posts WHERE channel_id = ? AND ABS(UNIX_TIMESTAMP(post_date) - ?) <= 30 LIMIT 1"
    );
    $stmt->execute([CHANNEL_ID, $date]);
    $postId = $stmt->fetchColumn();

    if ($postId) {
        $anchors[$msgId] = (int)$postId;
        out("  Якорь msg $msgId → post_id $postId (дата " . $msg['date'] . ")");
    } else {
        out("  Якорь msg $msgId НЕ НАЙДЕН в БД (дата " . $msg['date'] . ")");
    }
}

out("");
out("Якорей найдено: " . count($anchors));
out("");

// ─── Функция: найти post_id для комментария, поднимаясь по цепочке ответов ────

function findPostId(int $msgId, array $messages, array $anchors, int $depth = 0): ?int {
    if ($depth > 20) return null; // защита от зацикливания
    if (isset($anchors[$msgId])) return $anchors[$msgId];
    $replyTo = isset($messages[$msgId]) ? (int)($messages[$msgId]['reply_to_message_id'] ?? 0) : 0;
    if (!$replyTo) return null;
    return findPostId($replyTo, $messages, $anchors, $depth + 1);
}

// ─── Импорт комментариев ──────────────────────────────────────────────────────

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO tg_comments
        (post_id, discussion_group_id, tg_message_id, message_thread_id,
         user_id, user_name, user_username, text, entities, post_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$imported = 0;
$skipped  = 0;
$noPost   = 0;

foreach ($messages as $msgId => $msg) {
    // Пропускаем якоря (авто-пересланные посты)
    if (isset($anchors[$msgId])) { $skipped++; continue; }

    // Нужен reply_to_message_id
    $replyTo = (int)($msg['reply_to_message_id'] ?? 0);
    if (!$replyTo) { $skipped++; continue; }

    // Ищем post_id через цепочку ответов
    $postId = findPostId($replyTo, $messages, $anchors);
    if (!$postId) { $noPost++; continue; }

    // Текст
    $text = extractText($msg['text'] ?? '');
    // Пропускаем сообщения без текста (только фото)
    if ($text === '') { $skipped++; continue; }

    // Автор
    $fromId   = $msg['from_id'] ?? '';
    $userId   = parseFromId($fromId);
    $userName = $msg['from'] ?? null;
    // Если это пост от имени канала/группы — from_id вида "channel..."
    $userUsername = null; // в экспорте username не экспортируется

    // Entities
    $entities = convertEntities($msg['text_entities'] ?? [], $text);

    // Дата
    $postDate = date('Y-m-d H:i:s', (int)$msg['date_unixtime']);

    // message_thread_id = ID якоря этого треда
    $threadRoot = $replyTo;
    // Поднимаемся до якоря чтобы получить thread root
    $cur = $replyTo;
    while (isset($messages[$cur]) && !isset($anchors[$cur])) {
        $up = (int)($messages[$cur]['reply_to_message_id'] ?? 0);
        if (!$up) break;
        $cur = $up;
    }
    $threadRoot = $cur;

    try {
        $insertStmt->execute([
            $postId,
            $groupId,
            $msgId,
            $threadRoot ?: null,
            $userId,
            $userName,
            $userUsername,
            $text,
            $entities,
            $postDate,
        ]);
        if ($insertStmt->rowCount() > 0) {
            $imported++;
        } else {
            $skipped++; // уже есть в БД (INSERT IGNORE)
        }
    } catch (PDOException $e) {
        out("  Ошибка msg $msgId: " . $e->getMessage());
    }
}

out("─────────────────────────────");
out("Импортировано комментариев: $imported");
out("Пропущено (нет текста/якоря/дубликаты): $skipped");
out("Не найден пост для комментария: $noPost");
out("");
out("Готово. Удали discussion_export.json с сервера.");

if (!$isCli) echo "</pre>\n";
