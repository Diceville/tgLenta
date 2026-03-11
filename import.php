<?php

/**
 * Импорт истории канала из экспорта Telegram Desktop (result.json)
 * Использование: http://localhost:8888/tgLenta/import.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');

$jsonFile = __DIR__ . '/result.json';

if (!file_exists($jsonFile)) {
    die('<b style="color:red">Файл result.json не найден.</b><br>
         Экспортируйте историю канала из Telegram Desktop и положите result.json в папку tgLenta/');
}

$raw  = file_get_contents($jsonFile);
$data = json_decode($raw, true);

if (!$data || empty($data['messages'])) {
    die('<b style="color:red">Не удалось прочитать result.json или список сообщений пуст.</b>');
}

$messages  = $data['messages'];
$channelId = $data['id'] ?? 0;
$total     = count($messages);

echo "<h3>Импорт: найдено $total сообщений</h3><pre>";
flush();

$pdo = db();

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO tg_posts
        (tg_message_id, channel_id, text, media_type, media_file_id, media_url, thumb_url, post_date)
    VALUES
        (:tg_message_id, :channel_id, :text, :media_type, :media_file_id, :media_url, :thumb_url, :post_date)
");

$imported = 0;
$skipped  = 0;

foreach ($messages as $msg) {
    // Пропускаем служебные сообщения
    if (($msg['type'] ?? '') !== 'message') {
        $skipped++;
        continue;
    }

    $messageId = (int)($msg['id'] ?? 0);
    if (!$messageId) { $skipped++; continue; }

    // Дата
    $postDate = date('Y-m-d H:i:s', strtotime($msg['date'] ?? 'now'));

    // Текст: может быть строкой или массивом сущностей
    $text = extractText($msg['text'] ?? '');

    // Медиа
    [$mediaType, $mediaUrl, $thumbUrl] = extractMedia($msg);

    try {
        $insertStmt->execute([
            ':tg_message_id' => $messageId,
            ':channel_id'    => $channelId,
            ':text'          => $text ?: null,
            ':media_type'    => $mediaType,
            ':media_file_id' => null, // локальный экспорт не даёт file_id
            ':media_url'     => $mediaUrl,
            ':thumb_url'     => $thumbUrl,
            ':post_date'     => $postDate,
        ]);

        if ($insertStmt->rowCount() > 0) {
            $imported++;
            echo "✓ #{$messageId} " . mb_substr($text ?? '[медиа]', 0, 60) . "\n";
        } else {
            $skipped++;
        }
    } catch (PDOException $e) {
        echo "✗ #{$messageId} Ошибка: " . $e->getMessage() . "\n";
    }
}

echo "</pre>";
echo "<hr>";
echo "<b style='color:green'>Импортировано: $imported</b> &nbsp; Пропущено/дубли: $skipped<br><br>";
echo "<a href='index.html'>Открыть ленту →</a>";

// ─── Вспомогательные функции ──────────────────────────────────────────────────

function extractText(mixed $text): string {
    if (is_string($text)) {
        return $text;
    }
    if (is_array($text)) {
        // Массив сущностей — склеиваем текстовые части
        return implode('', array_map(function($part) {
            if (is_string($part)) return $part;
            if (is_array($part))  return $part['text'] ?? '';
            return '';
        }, $text));
    }
    return '';
}

function extractMedia(array $msg): array {
    $mediaType = 'none';
    $mediaUrl  = null;
    $thumbUrl  = null;

    // Фото
    if (!empty($msg['photo'])) {
        $mediaType = 'photo';
        $mediaUrl  = localMediaUrl($msg['photo']);
    }
    // Файл (видео, документ, анимация)
    elseif (!empty($msg['file'])) {
        $mime = $msg['mime_type'] ?? '';
        if (str_starts_with($mime, 'video/')) {
            $mediaType = 'video';
        } elseif ($mime === 'image/gif' || str_ends_with($msg['file'], '.gif')) {
            $mediaType = 'animation';
        } else {
            $mediaType = 'document';
        }
        $mediaUrl = localMediaUrl($msg['file']);
        if (!empty($msg['thumbnail'])) {
            $thumbUrl = localMediaUrl($msg['thumbnail']);
        }
    }
    // Стикер
    elseif (!empty($msg['sticker'])) {
        $mediaType = 'document';
        $mediaUrl  = localMediaUrl($msg['sticker']);
    }

    return [$mediaType, $mediaUrl, $thumbUrl];
}

function localMediaUrl(string $path): ?string {
    if (!$path) return null;
    $full = __DIR__ . '/' . ltrim($path, './');
    if (file_exists($full)) {
        return BASE_URL . '/' . ltrim($path, './');
    }
    return null;
}
