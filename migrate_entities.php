<?php

/**
 * Миграция: заполняет поле entities для уже импортированных постов.
 * Читает result.json (Desktop-экспорт) и обновляет записи в БД.
 * Запускать один раз: http://localhost/tgLenta/migrate_entities.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

set_time_limit(300);
header('Content-Type: text/html; charset=utf-8');

$jsonFile = __DIR__ . '/result.json';
if (!file_exists($jsonFile)) {
    die('<b style="color:red">Файл result.json не найден.</b>');
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data || empty($data['messages'])) {
    die('<b style="color:red">result.json пуст или не читается.</b>');
}

$channelId = (int)($data['id'] ?? 0);
$messages  = $data['messages'];

echo "<h3>Миграция entities: " . count($messages) . " сообщений из result.json</h3><pre>";
flush();

$pdo = db();

$updateStmt = $pdo->prepare("
    UPDATE tg_posts
    SET entities = :entities
    WHERE tg_message_id = :tg_message_id
      AND channel_id    = :channel_id
      AND entities IS NULL
");

$updated = 0;
$skipped = 0;

foreach ($messages as $msg) {
    if (($msg['type'] ?? '') !== 'message') { $skipped++; continue; }

    $messageId = (int)($msg['id'] ?? 0);
    if (!$messageId) { $skipped++; continue; }

    $raw = $msg['text'] ?? '';
    if (is_string($raw) || !is_array($raw)) { $skipped++; continue; }

    // Извлекаем entities из массива сегментов Desktop-формата
    $entities = extractEntities($raw);
    if (!$entities) { $skipped++; continue; }

    $entitiesJson = json_encode($entities, JSON_UNESCAPED_UNICODE);

    $updateStmt->execute([
        ':entities'      => $entitiesJson,
        ':tg_message_id' => $messageId,
        ':channel_id'    => $channelId,
    ]);

    if ($updateStmt->rowCount() > 0) {
        $updated++;
        echo "✓ #{$messageId}: " . count($entities) . " entities\n";
    } else {
        $skipped++;
    }
}

echo "</pre><hr>";
echo "<b style='color:green'>Обновлено: $updated</b> &nbsp; Пропущено (нет entities / уже заполнено): $skipped<br><br>";
echo "<a href='index.html'>Открыть ленту →</a>";

// ─── Вспомогательные функции ──────────────────────────────────────────────────

/**
 * Конвертирует Desktop-формат текста (массив сегментов) в массив entities
 * в формате Bot API: [{offset, length, type, url?}, ...].
 * Возвращает null если entities нет.
 */
function extractEntities(array $segments): ?array {
    $typeMap = [
        'bold'          => 'bold',
        'italic'        => 'italic',
        'underline'     => 'underline',
        'strikethrough' => 'strikethrough',
        'code'          => 'code',
        'pre'           => 'pre',
        'text_link'     => 'text_link',
        'link'          => 'text_link',
        'mention'       => 'mention',
        'hashtag'       => 'hashtag',
        'cashtag'       => 'cashtag',
        'bot_command'   => 'bot_command',
        'email'         => 'email',
        'spoiler'       => 'spoiler',
    ];

    $entities = [];
    $offset   = 0;

    foreach ($segments as $part) {
        if (is_string($part)) {
            $offset += mb_strlen($part);
            continue;
        }
        if (!is_array($part)) continue;

        $partText = $part['text'] ?? '';
        $len      = mb_strlen($partText);
        $type     = $typeMap[$part['type'] ?? ''] ?? null;

        if ($type) {
            $entity = ['offset' => $offset, 'length' => $len, 'type' => $type];
            if ($type === 'text_link' && isset($part['href'])) {
                $entity['url'] = $part['href'];
            }
            $entities[] = $entity;
        }

        $offset += $len;
    }

    return !empty($entities) ? $entities : null;
}
