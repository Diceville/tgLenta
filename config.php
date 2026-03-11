<?php

// Локальные настройки (для разработки) — загружаем если файл существует
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ─── Telegram Bot API ─────────────────────────────────────────────────────────
define('BOT_TOKEN',        getenv('BOT_TOKEN')        ?: '');
define('CHANNEL_USERNAME', getenv('CHANNEL_USERNAME') ?: '');

define('TELEGRAM_API_BASE',  'https://api.telegram.org/bot' . BOT_TOKEN);
define('TELEGRAM_FILE_BASE', 'https://api.telegram.org/file/bot' . BOT_TOKEN);

// ─── База данных ──────────────────────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'tglenta');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ─── Настройки приложения ─────────────────────────────────────────────────────
define('SYNC_INTERVAL',  (int)(getenv('SYNC_INTERVAL')  ?: 60));
define('POSTS_PER_PAGE', (int)(getenv('POSTS_PER_PAGE') ?: 20));

define('UPLOADS_DIR', getenv('UPLOADS_DIR') ?: __DIR__ . '/uploads');
define('UPLOADS_URL', getenv('UPLOADS_URL') ?: '/uploads');
