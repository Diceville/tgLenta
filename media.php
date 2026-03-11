<?php

/**
 * Прокси для медиафайлов Telegram.
 * Использование: /media.php?file_id=XXX
 * Получает файл с серверов Telegram и стримит браузеру без сохранения на диск.
 * Запросы к Telegram идут через SOCKS5-прокси (если задан SOCKS5_PROXY).
 */

require_once __DIR__ . '/config.php';

$fileId = $_GET['file_id'] ?? '';
if (!$fileId) {
    http_response_code(400);
    exit('Missing file_id');
}

// Опции прокси — добавляются к запросам только если прокси настроен
$proxyOpts = [];
if (SOCKS5_PROXY) {
    $proxyOpts = [
        CURLOPT_PROXY        => SOCKS5_PROXY,
        CURLOPT_PROXYTYPE    => CURLPROXY_SOCKS5,
        CURLOPT_PROXYUSERPWD => SOCKS5_AUTH,
    ];
}

// Получаем путь к файлу через Telegram API
$ch = curl_init(TELEGRAM_API_BASE . '/getFile?file_id=' . urlencode($fileId));
curl_setopt_array($ch, $proxyOpts + [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);
if (!$data || !$data['ok'] || empty($data['result']['file_path'])) {
    $desc = $data['description'] ?? 'no response';
    error_log("media.php getFile failed for file_id=$fileId: $desc");
    http_response_code(404);
    exit('File not found: ' . $desc);
}

$fileUrl = TELEGRAM_FILE_BASE . '/' . $data['result']['file_path'];

// Пробрасываем Range-заголовок браузера (нужен для перемотки видео)
$requestHeaders = [];
if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

header('Cache-Control: public, max-age=86400');

$ch = curl_init($fileUrl);
curl_setopt_array($ch, $proxyOpts + [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => $requestHeaders,
    CURLOPT_HEADERFUNCTION => function ($ch, $header) {
        $trimmed = trim($header);
        // Устанавливаем код ответа (200 или 206 Partial Content)
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $trimmed, $m)) {
            http_response_code((int)$m[1]);
            return strlen($header);
        }
        // Пробрасываем нужные заголовки браузеру
        $lower = strtolower($header);
        if (str_starts_with($lower, 'content-type:')   ||
            str_starts_with($lower, 'content-length:') ||
            str_starts_with($lower, 'content-range:')  ||
            str_starts_with($lower, 'accept-ranges:')) {
            header($trimmed);
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
        echo $chunk;
        return strlen($chunk);
    },
]);

if (!curl_exec($ch)) {
    http_response_code(502);
    exit('Failed to fetch file');
}
curl_close($ch);
