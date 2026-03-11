<?php

/**
 * Миграция: получение file_id для постов импортированных из Telegram Desktop-экспорта.
 *
 * Для каждого поста без file_id бот пересылает сообщение из канала в указанный чат,
 * извлекает file_id из ответа и удаляет пересланное сообщение.
 *
 * Можно запускать локально или на Beget.
 *
 * Настройка:
 *   1. Задайте MIGRATION_CHAT_ID — ваш личный Telegram user_id или ID приватного чата с ботом.
 *      Узнать: напишите боту любое сообщение, затем откройте:
 *      https://api.telegram.org/bot<TOKEN>/getUpdates
 *      и найдите "chat":{"id": XXXXXXX}
 *
 * Запуск: http://localhost:8888/tgLenta/migrate_file_ids.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ─── Настройка ────────────────────────────────────────────────────────────────

const MIGRATION_CHAT_ID = 27359701;

// Пауза между запросами чтобы не превысить лимиты Telegram API
const REQUEST_DELAY = 0.5;

// ─────────────────────────────────────────────────────────────────────────────

set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

if (!MIGRATION_CHAT_ID) {
    die('<b style="color:red">Укажите MIGRATION_CHAT_ID в начале скрипта.</b>');
}

// Прокси-опции (если заданы в конфиге)
$proxyOpts = [];
if (SOCKS5_PROXY) {
    $proxyOpts = [
        CURLOPT_PROXY        => SOCKS5_PROXY,
        CURLOPT_PROXYTYPE    => CURLPROXY_SOCKS5,
        CURLOPT_PROXYUSERPWD => SOCKS5_AUTH,
    ];
}

function tgCall(string $method, array $params, array $proxyOpts): mixed {
    $ch = curl_init(TELEGRAM_API_BASE . '/' . $method);
    curl_setopt_array($ch, $proxyOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!$data || !$data['ok']) return null;
    return $data['result'];
}

$pdo = db();

// Посты с локальным media_url и без file_id
$posts = $pdo->query("
    SELECT id, tg_message_id, channel_id, media_type
    FROM tg_posts
    WHERE media_file_id IS NULL
      AND media_url IS NOT NULL
      AND media_url NOT LIKE 'http%'
    ORDER BY id ASC
    LIMIT 10000
")->fetchAll();

$total   = count($posts);
$updated = 0;
$skipped = 0;

echo "<h3>Миграция file_id: найдено постов — $total</h3><pre>";
flush();

$updateStmt = $pdo->prepare("
    UPDATE tg_posts
    SET media_file_id = :file_id,
        media_url     = NULL
    WHERE id = :id
");

foreach ($posts as $post) {
    // import.php сохраняет channel_id без префикса -100, Bot API требует -100XXXXXXXXXX
    $channelId = (int)$post['channel_id'];
    if ($channelId > 0) {
        $channelId = (int)('-100' . $channelId);
    }

    // Пересылаем оригинальный пост из канала в наш чат
    $forwarded = tgCall('forwardMessage', [
        'chat_id'              => MIGRATION_CHAT_ID,
        'from_chat_id'         => $channelId,
        'message_id'           => (int)$post['tg_message_id'],
        'disable_notification' => true,
    ], $proxyOpts);

    if (!$forwarded) {
        // Повторный запрос для получения текста ошибки
        $ch2 = curl_init(TELEGRAM_API_BASE . '/forwardMessage');
        curl_setopt_array($ch2, $proxyOpts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'chat_id'      => MIGRATION_CHAT_ID,
                'from_chat_id' => $channelId,
                'message_id'   => (int)$post['tg_message_id'],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT    => 15,
        ]);
        $raw = curl_exec($ch2);
        curl_close($ch2);
        $errData = json_decode($raw, true);
        $errMsg  = $errData['description'] ?? $raw;
        echo "✗ #{$post['tg_message_id']} ошибка: $errMsg\n";
        $skipped++;
        flush();
        usleep((int)(REQUEST_DELAY * 1_000_000));
        continue;
    }

    // Извлекаем file_id из пересланного сообщения
    $fileId = match($post['media_type']) {
        'photo'     => end($forwarded['photo'])['file_id'] ?? null,
        'video'     => $forwarded['video']['file_id'] ?? null,
        'animation' => $forwarded['animation']['file_id'] ?? $forwarded['document']['file_id'] ?? null,
        default     => $forwarded['document']['file_id'] ?? null,
    };

    // Удаляем пересланное сообщение
    tgCall('deleteMessage', [
        'chat_id'    => MIGRATION_CHAT_ID,
        'message_id' => $forwarded['message_id'],
    ], $proxyOpts);

    if (!$fileId) {
        echo "⚠ #{$post['tg_message_id']} переслано, но file_id не найден (тип: {$post['media_type']})\n";
        $skipped++;
        flush();
        usleep((int)(REQUEST_DELAY * 1_000_000));
        continue;
    }

    $updateStmt->execute([':file_id' => $fileId, ':id' => $post['id']]);

    echo "✓ #{$post['tg_message_id']} [{$post['media_type']}] → $fileId\n";
    $updated++;
    flush();

    usleep((int)(REQUEST_DELAY * 1_000_000));
}

echo "</pre><hr>";
echo "<b style='color:green'>Обновлено: $updated</b> &nbsp; Пропущено: $skipped<br><br>";
echo "<a href='index.php'>Открыть ленту →</a>";
