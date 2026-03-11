<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = min(50, max(1, (int)($_GET['limit'] ?? POSTS_PER_PAGE)));
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : null;
$offset  = ($page - 1) * $limit;

$pdo = db();

try {
    if ($sinceId !== null) {
        // Режим polling: только новые посты
        $stmt = $pdo->prepare("
            SELECT id, tg_message_id, channel_id, text, media_type, media_file_id,
                   media_url, thumb_url, post_date
            FROM tg_posts
            WHERE tg_message_id > :since_id
              AND NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
            ORDER BY post_date DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        echo json_encode([
            'posts'    => groupAlbums(formatPosts($posts)),
            'has_more' => false,
            'page'     => 1,
        ], JSON_UNESCAPED_UNICODE);

    } else {
        // Режим пагинации
        $total = (int)$pdo->query("
            SELECT COUNT(*) FROM tg_posts
            WHERE NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
        ")->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, tg_message_id, channel_id, text, media_type, media_file_id,
                   media_url, thumb_url, post_date
            FROM tg_posts
            WHERE NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
            ORDER BY post_date DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        echo json_encode([
            'posts'    => groupAlbums(formatPosts($posts)),
            'page'     => $page,
            'total'    => $total,
            'has_more' => ($offset + $limit) < $total,
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

// ─── Форматирование постов ────────────────────────────────────────────────────

function formatPosts(array $posts): array {
    return array_map(function (array $post): array {
        $mediaUrl = $post['media_url'];
        if (!$mediaUrl && $post['media_file_id']) {
            $mediaUrl = '/tgLenta/media.php?file_id=' . urlencode($post['media_file_id']);
        }

        $thumbUrl = $post['thumb_url'];
        if (!$thumbUrl && in_array($post['media_type'], ['video', 'animation']) && $post['media_file_id']) {
            $thumbUrl = null;
        }

        return [
            'id'          => (int)$post['id'],
            'tg_id'       => (int)$post['tg_message_id'],
            'text'        => $post['text'],
            'media_type'  => $post['media_type'],
            'media_url'   => $mediaUrl,
            'media_files' => null, // заполняется в groupAlbums
            'thumb_url'   => $thumbUrl,
            'date'        => $post['post_date'],
            'timestamp'   => strtotime($post['post_date']),
        ];
    }, $posts);
}

// ─── Группировка фото-альбомов ────────────────────────────────────────────────

function groupAlbums(array $posts): array {
    if (empty($posts)) return $posts;

    $result = [];
    $i = 0;
    $n = count($posts);

    while ($i < $n) {
        $p = $posts[$i];

        // Группируем только фото с URL
        if ($p['media_type'] !== 'photo' || !$p['media_url']) {
            $result[] = $p;
            $i++;
            continue;
        }

        // Собираем подряд идущие фото с той же датой
        $group = [$p];
        $j = $i + 1;
        while ($j < $n
               && $posts[$j]['media_type'] === 'photo'
               && $posts[$j]['media_url']
               && $posts[$j]['date'] === $p['date']) {
            $group[] = $posts[$j];
            $j++;
        }

        if (count($group) === 1) {
            // Одиночное фото — media_files с одним элементом для лайтбокса
            $p['media_files'] = [$p['media_url']];
            $result[] = $p;
        } else {
            // Альбом — объединяем
            $merged = $group[0];
            $merged['media_files'] = array_map(fn($gp) => $gp['media_url'], $group);
            // Берём подпись из любого поста группы
            $merged['text'] = null;
            foreach ($group as $gp) {
                if (!empty($gp['text'])) {
                    $merged['text'] = $gp['text'];
                    break;
                }
            }
            $result[] = $merged;
        }

        $i = $j;
    }

    return $result;
}
