<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Предотвращаем параллельный запуск
$lockFile = sys_get_temp_dir() . '/tglenta_sync.lock';
$lock = fopen($lockFile, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(json_encode(['error' => 'Already running']));
}

header('Content-Type: application/json');

// ─── Вспомогательные функции ──────────────────────────────────────────────────

function tgRequest(string $method, array $params = []): ?array {
    $url = TELEGRAM_API_BASE . '/' . $method;
    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if (SOCKS5_PROXY) {
        $opts[CURLOPT_PROXY]        = SOCKS5_PROXY;
        $opts[CURLOPT_PROXYTYPE]    = CURLPROXY_SOCKS5;
        $opts[CURLOPT_PROXYUSERPWD] = SOCKS5_AUTH;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("tgRequest curl error: $error");
        return null;
    }

    $data = json_decode($response, true);
    return ($data && $data['ok']) ? $data['result'] : null;
}

function getFileUrl(string $fileId): ?string {
    $file = tgRequest('getFile', ['file_id' => $fileId]);
    if (!$file || empty($file['file_path'])) {
        return null;
    }
    return TELEGRAM_FILE_BASE . '/' . $file['file_path'];
}

function downloadMedia(string $fileId): ?string {
    $url = getFileUrl($fileId);
    if (!$url) return null;

    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }

    $ext      = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $filename = $fileId . ($ext ? '.' . $ext : '');
    $dest     = UPLOADS_DIR . '/' . $filename;

    if (file_exists($dest)) {
        return UPLOADS_URL . '/' . $filename;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $data = curl_exec($ch);
    curl_close($ch);

    if ($data && file_put_contents($dest, $data)) {
        return UPLOADS_URL . '/' . $filename;
    }

    return null;
}

function extractMedia(array $msg): array {
    $mediaType   = 'none';
    $mediaFileId = null;
    $thumbFileId = null;

    if (!empty($msg['photo'])) {
        // Берём фото с наибольшим размером
        $photo       = end($msg['photo']);
        $mediaType   = 'photo';
        $mediaFileId = $photo['file_id'];

    } elseif (!empty($msg['video'])) {
        $mediaType   = 'video';
        $mediaFileId = $msg['video']['file_id'];
        $thumbFileId = $msg['video']['thumbnail']['file_id'] ?? null;

    } elseif (!empty($msg['animation'])) {
        $mediaType   = 'animation';
        $mediaFileId = $msg['animation']['file_id'];
        $thumbFileId = $msg['animation']['thumbnail']['file_id'] ?? null;

    } elseif (!empty($msg['document'])) {
        $mediaType   = 'document';
        $mediaFileId = $msg['document']['file_id'];
    }

    return [$mediaType, $mediaFileId, $thumbFileId];
}

/**
 * Находит post_id для комментария из группы обсуждений.
 * Стратегия:
 *  1. reply_to_message — автоматически пересланный пост канала → forward_from_message_id
 *  2. message_thread_id — ищем другой комментарий в этом треде с уже известным post_id
 */
function findPostIdForComment(PDO $pdo, array $msg): ?int {
    // 1. Прямой ответ на пересланный пост канала
    $reply = $msg['reply_to_message'] ?? null;
    if ($reply && !empty($reply['forward_from_chat'])) {
        $rawFwdId  = (int)$reply['forward_from_chat']['id'];
        // Нормализуем: Telegram отдаёт -1001234567890, в БД хранится 1234567890
        $fwdChatId = $rawFwdId < 0 ? (int)substr((string)abs($rawFwdId), 3) : $rawFwdId;
        $fwdMsgId  = (int)($reply['forward_from_message_id'] ?? 0);
        // Ищем по fwdChatId из самого форварда — любой sync.php найдёт пост любого канала
        if ($fwdChatId && $fwdMsgId) {
            $stmt = $pdo->prepare(
                "SELECT id FROM tg_posts WHERE tg_message_id = ? AND channel_id = ? LIMIT 1"
            );
            $stmt->execute([$fwdMsgId, $fwdChatId]);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        }
    }

    // 2. По message_thread_id — ищем уже сохранённый комментарий в этом треде
    if (!empty($msg['message_thread_id'])) {
        $stmt = $pdo->prepare(
            "SELECT post_id FROM tg_comments
             WHERE discussion_group_id = ? AND message_thread_id = ?
             LIMIT 1"
        );
        $stmt->execute([DISCUSSION_GROUP_ID, (int)$msg['message_thread_id']]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
    }

    return null;
}

// ─── Основная логика синхронизации ────────────────────────────────────────────

$pdo = db();

$channelId = CHANNEL_ID;

// Глобальный offset бота хранится с channel_id = 0 —
// один для всех блогов, чтобы не терять апдейты при работе нескольких блогов на одном боте
$stmt = $pdo->prepare("SELECT `value` FROM tg_state WHERE channel_id = 0 AND `key` = 'last_update_id'");
$stmt->execute();
$lastUpdateId = (int)($stmt->fetchColumn() ?? 0);

// Запрашиваем новые обновления
$allowedUpdates = ['channel_post'];
if (DISCUSSION_GROUP_ID) {
    $allowedUpdates[] = 'message';
}

$updates = tgRequest('getUpdates', [
    'offset'          => $lastUpdateId + 1,
    'limit'           => 100,
    'timeout'         => 0,
    'allowed_updates' => $allowedUpdates,
]);

if ($updates === null) {
    flock($lock, LOCK_UN);
    echo json_encode(['error' => 'Telegram API request failed']);
    exit;
}

$synced   = 0;
$errors   = [];
$comments = 0;
$maxUpdate = $lastUpdateId;

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO tg_posts
        (tg_message_id, channel_id, text, media_type, media_file_id, media_url, thumb_url, post_date, views, entities, media_group_id)
    VALUES
        (:tg_message_id, :channel_id, :text, :media_type, :media_file_id, :media_url, :thumb_url, :post_date, :views, :entities, :media_group_id)
");

foreach ($updates as $update) {
    $updateId = (int)$update['update_id'];
    if ($updateId > $maxUpdate) $maxUpdate = $updateId;

    // ─── Пост из канала ───────────────────────────────────────────────────────
    $channelPost = $update['channel_post'] ?? null;
    if ($channelPost) {
        if (empty($channelPost['text']) && empty($channelPost['caption'])
            && empty($channelPost['photo']) && empty($channelPost['video'])
            && empty($channelPost['animation']) && empty($channelPost['document'])) {
            // служебное сообщение — пропускаем
        } else {
            $text         = $channelPost['text'] ?? $channelPost['caption'] ?? null;
            $msgChannelId = $channelPost['chat']['id'];
            // Нормализуем channel_id: Telegram возвращает -1001234567890,
            // а импорт хранит 1234567890 (без префикса -100)
            $storeChannelId = $msgChannelId < 0
                ? (int)substr((string)abs($msgChannelId), 3)
                : $msgChannelId;
            // Сохраняем посты из ЛЮБОГО канала — фильтр по channel_id только в api.php.
            // Это важно когда несколько блогов используют одного бота: один блог не должен
            // "съедать" апдейты другого канала не сохранив их.
            {
                $messageId    = (int)$channelPost['message_id'];
                $postDate     = date('Y-m-d H:i:s', $channelPost['date']);
                $views        = isset($channelPost['views']) ? (int)$channelPost['views'] : null;
                $rawEntities  = $channelPost['entities'] ?? $channelPost['caption_entities'] ?? null;
                $entities     = $rawEntities ? json_encode($rawEntities, JSON_UNESCAPED_UNICODE) : null;
                $mediaGroupId = $channelPost['media_group_id'] ?? null;
                [$mediaType, $mediaFileId, $thumbFileId] = extractMedia($channelPost);

                try {
                    $insertStmt->execute([
                        ':tg_message_id'  => $messageId,
                        ':channel_id'     => $storeChannelId,
                        ':text'           => $text,
                        ':media_type'     => $mediaType,
                        ':media_file_id'  => $mediaFileId,
                        ':media_url'      => null,
                        ':thumb_url'      => null,
                        ':post_date'      => $postDate,
                        ':views'          => $views,
                        ':entities'       => $entities,
                        ':media_group_id' => $mediaGroupId,
                    ]);
                    if ($insertStmt->rowCount() > 0) $synced++;
                } catch (PDOException $e) {
                    $errors[] = "DB error for message $messageId: " . $e->getMessage();
                }
            }
        }
    }

    // ─── Комментарий из группы обсуждений ────────────────────────────────────
    $groupMsg = $update['message'] ?? null;
    if ($groupMsg && DISCUSSION_GROUP_ID
        && (int)($groupMsg['chat']['id'] ?? 0) === DISCUSSION_GROUP_ID
        && empty($groupMsg['is_automatic_forward'])
        && (!empty($groupMsg['text']) || !empty($groupMsg['caption']))
    ) {
        $postId = findPostIdForComment($pdo, $groupMsg);
        if ($postId) {
            $from         = $groupMsg['from'] ?? [];
            $userName     = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: null;
            $rawEnt       = $groupMsg['entities'] ?? $groupMsg['caption_entities'] ?? null;
            $ent          = $rawEnt ? json_encode($rawEnt, JSON_UNESCAPED_UNICODE) : null;

            try {
                $pdo->prepare("
                    INSERT IGNORE INTO tg_comments
                        (post_id, discussion_group_id, tg_message_id, message_thread_id,
                         user_id, user_name, user_username, text, entities, post_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $postId,
                    DISCUSSION_GROUP_ID,
                    (int)$groupMsg['message_id'],
                    isset($groupMsg['message_thread_id']) ? (int)$groupMsg['message_thread_id'] : null,
                    $from['id'] ?? null,
                    $userName,
                    $from['username'] ?? null,
                    $groupMsg['text'] ?? $groupMsg['caption'] ?? null,
                    $ent,
                    date('Y-m-d H:i:s', $groupMsg['date']),
                ]);
                $comments++;
            } catch (PDOException $e) {
                $errors[] = 'Comment error: ' . $e->getMessage();
            }
        }
    }
}

// Сохраняем глобальный offset бота (channel_id = 0)
if ($maxUpdate > $lastUpdateId) {
    $pdo->prepare("
        INSERT INTO tg_state (channel_id, `key`, `value`) VALUES (0, 'last_update_id', ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ")->execute([$maxUpdate]);
}

flock($lock, LOCK_UN);

$result = [
    'synced'   => $synced,
    'comments' => $comments,
    'updates'  => count($updates),
    'errors'   => $errors,
];
if (!empty($_GET['debug'])) {
    $result['raw_updates'] = $updates;
}
echo json_encode($result, JSON_UNESCAPED_UNICODE);
