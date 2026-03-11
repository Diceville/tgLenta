<?php

/**
 * Прокси для медиафайлов Telegram.
 * Использование: /media.php?file_id=XXX
 * Получает файл с серверов Telegram и отдаёт браузеру.
 */

require_once __DIR__ . '/config.php';

$fileId = $_GET['file_id'] ?? '';
if (!$fileId) {
    http_response_code(400);
    exit('Missing file_id');
}

// Кэш на диске — не скачиваем повторно
$cacheDir  = __DIR__ . '/uploads/cache';
$cachePath = $cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $fileId);

if (file_exists($cachePath)) {
    $mime = mime_content_type($cachePath) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($cachePath);
    exit;
}

// Получаем путь к файлу через Telegram API
$ch = curl_init(TELEGRAM_API_BASE . '/getFile?file_id=' . urlencode($fileId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
curl_close($ch);

$data = json_decode($resp, true);
if (!$data || !$data['ok'] || empty($data['result']['file_path'])) {
    http_response_code(404);
    exit('File not found');
}

$fileUrl = TELEGRAM_FILE_BASE . '/' . $data['result']['file_path'];

// Скачиваем файл
$ch = curl_init($fileUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$fileData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$fileData || $httpCode !== 200) {
    http_response_code(502);
    exit('Failed to fetch file');
}

// Сохраняем в кэш
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
file_put_contents($cachePath, $fileData);

$mime = mime_content_type($cachePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
echo $fileData;
