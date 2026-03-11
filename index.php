<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(CHANNEL_USERNAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

    <header class="site-header">
        <div class="container">
            <div class="channel-info">
                <div class="channel-avatar" id="channelAvatar"></div>
                <div class="channel-meta">
                    <h1 class="channel-name" id="channelName"><?= htmlspecialchars(CHANNEL_USERNAME) ?></h1>
                    <span class="channel-label">Telegram-канал</span>
                </div>
            </div>
            <div class="search-wrap">
                <input type="search" id="searchInput" class="search-input" placeholder="Поиск...">
            </div>
            <div class="sync-status" id="syncStatus"></div>
        </div>
    </header>

    <main class="container">
        <div id="feed" class="feed">
            <div class="skeleton-list" id="skeletonList" hidden>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
                <div class="skeleton-card"></div>
            </div>
        </div>

        <div class="empty-state" id="emptyState" hidden>
            <p>Постов пока нет.<br>Запустите синхронизацию: <a href="sync.php" target="_blank">sync.php</a></p>
        </div>

        <div class="error-state" id="errorState" hidden>
            <p>Не удалось загрузить посты. Проверьте соединение с базой данных.</p>
        </div>

        <div class="load-more-wrap" id="loadMoreWrap" hidden>
            <button class="btn-load-more" id="loadMoreBtn">Загрузить ещё</button>
        </div>

        <!-- Sentinel для IntersectionObserver -->
        <div id="sentinel"></div>
    </main>

    <script>
        window.APP_CONFIG = {
            syncInterval: <?= SYNC_INTERVAL * 1000 ?>,
            postsPerPage: <?= POSTS_PER_PAGE ?>
        };
    </script>
    <script src="assets/app.js"></script>

</body>
</html>
