<?php

/**
 * Отдаёт аватар канала, проксируя через Telegram Bot API.
 * file_id кешируется в файле на час, чтобы не дёргать getChat каждый раз.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$cacheFile = sys_get_temp_dir() . '/tglenta_avatar_file_id.txt';
$cacheTtl  = 3600; // секунд

// Читаем file_id из кеша
$fileId = null;
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $fileId = trim(file_get_contents($cacheFile));
}

if (!$fileId) {
    // Получаем channel_id из БД
    $pdo = db();
    $channelId = $pdo->query("SELECT channel_id FROM tg_posts ORDER BY id LIMIT 1")->fetchColumn();

    // Bot API требует -100XXXXXXXXXX
    if ($channelId > 0) {
        $channelId = '-100' . $channelId;
    }

    $chatIdParam = CHANNEL_TG_USERNAME ? ('@' . CHANNEL_TG_USERNAME) : $channelId;

    $proxyOpts = [];
    if (SOCKS5_PROXY) {
        $proxyOpts = [
            CURLOPT_PROXY        => SOCKS5_PROXY,
            CURLOPT_PROXYTYPE    => CURLPROXY_SOCKS5,
            CURLOPT_PROXYUSERPWD => SOCKS5_AUTH,
        ];
    }

    $ch = curl_init(TELEGRAM_API_BASE . '/getChat?chat_id=' . urlencode($chatIdParam));
    curl_setopt_array($ch, $proxyOpts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    $fileId = $data['result']['photo']['big_file_id'] ?? null;

    if ($fileId) {
        file_put_contents($cacheFile, $fileId);
    }
}

if (!$fileId) {
    http_response_code(404);
    exit('Avatar not available');
}

// Перенаправляем на media.php, который уже умеет проксировать
header('Location: ' . BASE_URL . '/media.php?file_id=' . urlencode($fileId));
