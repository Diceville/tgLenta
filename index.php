<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="ru" id="htmlRoot">
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
            <button class="btn-theme" id="themeToggle" aria-label="Переключить тему">
                <svg class="icon-sun" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2a1 1 0 0 1 1 1v1a1 1 0 1 1-2 0v-1a1 1 0 0 1 1-1zm0-14a1 1 0 0 1-1-1V3a1 1 0 1 1 2 0v1a1 1 0 0 1-1 1zm7.07 11.07a1 1 0 0 1 0 1.414l-.707.707a1 1 0 1 1-1.414-1.414l.707-.707a1 1 0 0 1 1.414 0zm-14.14 0a1 1 0 0 1 1.414 0l.707.707a1 1 0 1 1-1.414 1.414l-.707-.707a1 1 0 0 1 0-1.414zM21 11a1 1 0 1 1 0 2h-1a1 1 0 1 1 0-2h1zM4 11a1 1 0 1 1 0 2H3a1 1 0 1 1 0-2h1zm14.364-6.364a1 1 0 0 1 0 1.414l-.707.707A1 1 0 1 1 16.243 5.34l.707-.707a1 1 0 0 1 1.414 0zM7.05 5.636a1 1 0 0 1 1.414 1.414l-.707.707A1 1 0 0 1 6.343 6.34l.707-.707z"/></svg>
                <svg class="icon-moon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z"/></svg>
            </button>
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
            postsPerPage: <?= POSTS_PER_PAGE ?>,
            siteUrl: <?= json_encode((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')) ?>
        };
    </script>
    <script src="assets/app.js"></script>

</body>
</html>
