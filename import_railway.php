<?php

/**
 * Импорт текстовых постов в Railway MySQL (без медиафайлов)
 * 1. Заполните config.local.php реквизитами Railway MySQL
 * 2. Запустите: http://localhost:8888/tgLenta/import_railway.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');

$jsonFile = __DIR__ . '/result.json';

if (!file_exists($jsonFile)) {
    die('<b style="color:red">Файл result.json не найден.</b>');
}

$raw  = file_get_contents($jsonFile);
$data = json_decode($raw, true);

if (!$data || empty($data['messages'])) {
    die('<b style="color:red">Не удалось прочитать result.json.</b>');
}

// Проверяем что подключились к Railway, а не к локальной БД
$host = DB_HOST;
echo "<h3>Подключение к: <code>$host</code></h3>";
if ($host === '127.0.0.1' || $host === 'localhost') {
    die('<b style="color:red">Ошибка: всё ещё подключён к локальной БД. Обновите config.local.php!</b>');
}

$messages  = $data['messages'];
$channelId = $data['id'] ?? 0;
$total     = count($messages);

echo "<h3>Найдено сообщений: $total</h3><pre>";
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
    if (($msg['type'] ?? '') !== 'message') {
        $skipped++;
        continue;
    }

    $messageId = (int)($msg['id'] ?? 0);
    if (!$messageId) { $skipped++; continue; }

    $postDate = date('Y-m-d H:i:s', strtotime($msg['date'] ?? 'now'));
    $text     = extractText($msg['text'] ?? '');

    // Определяем тип медиа, но URL не сохраняем (файлов нет на Railway)
    $mediaType = 'none';
    if (!empty($msg['photo']))   $mediaType = 'photo';
    elseif (!empty($msg['file'])) {
        $mime = $msg['mime_type'] ?? '';
        if (str_starts_with($mime, 'video/'))    $mediaType = 'video';
        elseif ($mime === 'image/gif')            $mediaType = 'animation';
        else                                      $mediaType = 'document';
    }

    // Пропускаем посты без текста и без медиа (нечего показывать)
    if (!$text && $mediaType === 'none') {
        $skipped++;
        continue;
    }

    try {
        $insertStmt->execute([
            ':tg_message_id' => $messageId,
            ':channel_id'    => $channelId,
            ':text'          => $text ?: null,
            ':media_type'    => $mediaType,
            ':media_file_id' => null,
            ':media_url'     => null, // нет медиа на Railway
            ':thumb_url'     => null,
            ':post_date'     => $postDate,
        ]);

        if ($insertStmt->rowCount() > 0) {
            $imported++;
            if ($imported % 50 === 0) {
                echo "... импортировано $imported\n";
                flush();
            }
        } else {
            $skipped++;
        }
    } catch (PDOException $e) {
        echo "✗ #{$messageId} Ошибка: " . $e->getMessage() . "\n";
    }
}

echo "</pre><hr>";
echo "<b style='color:green'>Импортировано: $imported</b> &nbsp; Пропущено/дубли: $skipped<br>";
echo "<p>Посты без текста (только медиа) сохранены без фото. Новые посты с фото появятся через sync.php</p>";

function extractText(mixed $text): string {
    if (is_string($text)) return $text;
    if (is_array($text)) {
        return implode('', array_map(function($part) {
            if (is_string($part)) return $part;
            if (is_array($part))  return $part['text'] ?? '';
            return '';
        }, $text));
    }
    return '';
}
