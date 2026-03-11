<?php

// Локальные настройки (для разработки) — загружаем если файл существует
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ─── Telegram Bot API ─────────────────────────────────────────────────────────
define('BOT_TOKEN',            getenv('BOT_TOKEN')            ?: '');
define('CHANNEL_USERNAME',     getenv('CHANNEL_USERNAME')     ?: '');
// @username канала без @ (для ссылок на посты). Оставьте пустым для приватных каналов.
define('CHANNEL_TG_USERNAME',  getenv('CHANNEL_TG_USERNAME')  ?: '');

define('TELEGRAM_API_BASE',  'https://api.telegram.org/bot' . BOT_TOKEN);
define('TELEGRAM_FILE_BASE', 'https://api.telegram.org/file/bot' . BOT_TOKEN);

// ─── База данных ──────────────────────────────────────────────────────────────
// Поддержка как Railway-переменных (MYSQLHOST и т.д.), так и кастомных (DB_HOST)
define('DB_HOST', getenv('DB_HOST') ?: getenv('MYSQLHOST')     ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: getenv('MYSQLPORT')     ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: getenv('MYSQLDATABASE') ?: 'tglenta');
define('DB_USER', getenv('DB_USER') ?: getenv('MYSQLUSER')     ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: getenv('MYSQLPASSWORD') ?: '');

// ─── Настройки приложения ─────────────────────────────────────────────────────
define('SYNC_INTERVAL',  (int)(getenv('SYNC_INTERVAL')  ?: 60));
define('POSTS_PER_PAGE', (int)(getenv('POSTS_PER_PAGE') ?: 20));

define('UPLOADS_DIR', getenv('UPLOADS_DIR') ?: __DIR__ . '/uploads');
define('UPLOADS_URL', getenv('UPLOADS_URL') ?: '/uploads');

// Базовый URL сайта (пустой если сайт в корне, '/tgLenta' для локального MAMP)
define('BASE_URL', getenv('BASE_URL') ?: '');
