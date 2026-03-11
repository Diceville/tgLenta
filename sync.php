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
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
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

// ─── Основная логика синхронизации ────────────────────────────────────────────

$pdo = db();

// Читаем последний обработанный update_id
$stmt = $pdo->prepare("SELECT `value` FROM tg_state WHERE `key` = 'last_update_id'");
$stmt->execute();
$lastUpdateId = (int)($stmt->fetchColumn() ?? 0);

// Запрашиваем новые обновления
$updates = tgRequest('getUpdates', [
    'offset'          => $lastUpdateId + 1,
    'limit'           => 100,
    'timeout'         => 0,
    'allowed_updates' => ['channel_post'],
]);

if ($updates === null) {
    flock($lock, LOCK_UN);
    echo json_encode(['error' => 'Telegram API request failed']);
    exit;
}

$synced   = 0;
$errors   = [];
$maxUpdate = $lastUpdateId;

$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO tg_posts
        (tg_message_id, channel_id, text, media_type, media_file_id, media_url, thumb_url, post_date)
    VALUES
        (:tg_message_id, :channel_id, :text, :media_type, :media_file_id, :media_url, :thumb_url, :post_date)
");

foreach ($updates as $update) {
    $updateId = (int)$update['update_id'];
    if ($updateId > $maxUpdate) {
        $maxUpdate = $updateId;
    }

    $msg = $update['channel_post'] ?? null;
    if (!$msg) continue;

    // Пропускаем служебные сообщения без контента
    if (empty($msg['text']) && empty($msg['caption']) && empty($msg['photo'])
        && empty($msg['video']) && empty($msg['animation']) && empty($msg['document'])) {
        continue;
    }

    $text      = $msg['text'] ?? $msg['caption'] ?? null;
    $channelId = $msg['chat']['id'];
    $messageId = (int)$msg['message_id'];
    $postDate  = date('Y-m-d H:i:s', $msg['date']);

    [$mediaType, $mediaFileId, $thumbFileId] = extractMedia($msg);

    // Не скачиваем файлы — отдаём через media.php по file_id
    $mediaUrl = $mediaFileId ? null : null;
    $thumbUrl = $thumbFileId ? null : null;

    try {
        $insertStmt->execute([
            ':tg_message_id' => $messageId,
            ':channel_id'    => $channelId,
            ':text'          => $text,
            ':media_type'    => $mediaType,
            ':media_file_id' => $mediaFileId,
            ':media_url'     => $mediaUrl,
            ':thumb_url'     => $thumbUrl,
            ':post_date'     => $postDate,
        ]);
        if ($insertStmt->rowCount() > 0) {
            $synced++;
        }
    } catch (PDOException $e) {
        $errors[] = "DB error for message $messageId: " . $e->getMessage();
    }
}

// Сохраняем новый offset
if ($maxUpdate > $lastUpdateId) {
    $pdo->prepare("UPDATE tg_state SET `value` = ? WHERE `key` = 'last_update_id'")
        ->execute([$maxUpdate]);
}

flock($lock, LOCK_UN);

echo json_encode([
    'synced'  => $synced,
    'updates' => count($updates),
    'errors'  => $errors,
]);
