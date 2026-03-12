<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = min(50, max(1, (int)($_GET['limit'] ?? POSTS_PER_PAGE)));
$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : null;
$search  = isset($_GET['search']) ? trim($_GET['search']) : null;
$offset  = ($page - 1) * $limit;

$pdo = db();

// Фильтр по каналу: если CHANNEL_ID задан — показываем только его посты
$channelId     = CHANNEL_ID;
$channelFilter = $channelId ? 'AND channel_id = :channel_id' : '';

try {
    if ($search !== null && $search !== '') {
        // Режим поиска
        $like = '%' . $search . '%';

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM tg_posts
            WHERE text LIKE :q
              $channelFilter
              AND NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
        ");
        $countParams = [':q' => $like];
        if ($channelId) $countParams[':channel_id'] = $channelId;
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, tg_message_id, channel_id, text, media_type, media_file_id,
                   media_url, thumb_url, post_date, views, entities, media_group_id
            FROM tg_posts
            WHERE text LIKE :q
              $channelFilter
              AND NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
            ORDER BY post_date DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':q',      $like,   PDO::PARAM_STR);
        if ($channelId) $stmt->bindValue(':channel_id', $channelId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        echo json_encode([
            'posts'    => groupAlbums(formatPosts($posts)),
            'page'     => $page,
            'total'    => $total,
            'has_more' => ($offset + $limit) < $total,
            'search'   => $search,
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($sinceId !== null) {
        // Режим polling: только новые посты
        $stmt = $pdo->prepare("
            SELECT id, tg_message_id, channel_id, text, media_type, media_file_id,
                   media_url, thumb_url, post_date, views, entities, media_group_id
            FROM tg_posts
            WHERE tg_message_id > :since_id
              $channelFilter
              AND NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
            ORDER BY post_date DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
        if ($channelId) $stmt->bindValue(':channel_id', $channelId, PDO::PARAM_INT);
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
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM tg_posts
            WHERE 1=1
              $channelFilter
              AND NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
        ");
        if ($channelId) $countStmt->execute([':channel_id' => $channelId]);
        else $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, tg_message_id, channel_id, text, media_type, media_file_id,
                   media_url, thumb_url, post_date, views, entities, media_group_id
            FROM tg_posts
            WHERE 1=1
              $channelFilter
              AND NOT (media_type = 'none' AND (text IS NULL OR text = '') AND media_url IS NULL)
            ORDER BY post_date DESC
            LIMIT :limit OFFSET :offset
        ");
        if ($channelId) $stmt->bindValue(':channel_id', $channelId, PDO::PARAM_INT);
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
            $mediaUrl = BASE_URL . '/media.php?file_id=' . urlencode($post['media_file_id']);
        }

        $thumbUrl = $post['thumb_url'];
        if (!$thumbUrl && in_array($post['media_type'], ['video', 'animation']) && $post['media_file_id']) {
            $thumbUrl = null;
        }

        // Ссылка на пост в Telegram
        $msgId = (int)$post['tg_message_id'];
        if (CHANNEL_TG_USERNAME) {
            $tgLink = 'https://t.me/' . CHANNEL_TG_USERNAME . '/' . $msgId;
        } else {
            // Приватный канал: убираем -100 из channel_id
            $chanId = ltrim((string)$post['channel_id'], '-');
            if (str_starts_with($chanId, '100')) $chanId = substr($chanId, 3);
            $tgLink = 'https://t.me/c/' . $chanId . '/' . $msgId;
        }

        return [
            'id'             => (int)$post['id'],
            'tg_id'          => $msgId,
            'text'           => $post['text'],
            'entities'       => $post['entities'] ? json_decode($post['entities'], true) : null,
            'media_type'     => $post['media_type'],
            'media_url'      => $mediaUrl,
            'media_files'    => null, // заполняется в groupAlbums
            'thumb_url'      => $thumbUrl,
            'date'           => $post['post_date'],
            'timestamp'      => strtotime($post['post_date']),
            'views'          => isset($post['views']) ? (int)$post['views'] : null,
            'tg_link'        => $tgLink,
            'media_group_id' => $post['media_group_id'] ?? null,
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

        if ($p['media_type'] !== 'photo' || !$p['media_url']) {
            $result[] = $p;
            $i++;
            continue;
        }

        $group   = [$p];
        $j       = $i + 1;
        $groupId = $p['media_group_id'] ?? null;

        if ($groupId) {
            // Точная группировка по media_group_id
            while ($j < $n && ($posts[$j]['media_group_id'] ?? null) === $groupId) {
                $group[] = $posts[$j];
                $j++;
            }
        } else {
            // Fallback: группируем фото с одинаковой датой
            while ($j < $n
                   && $posts[$j]['media_type'] === 'photo'
                   && $posts[$j]['media_url']
                   && $posts[$j]['date'] === $p['date']) {
                $group[] = $posts[$j];
                $j++;
            }
        }

        if (count($group) === 1) {
            $p['media_files'] = [$p['media_url']];
            $result[] = $p;
        } else {
            $merged = $group[0];
            $merged['media_files'] = array_map(fn($gp) => $gp['media_url'], $group);
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
