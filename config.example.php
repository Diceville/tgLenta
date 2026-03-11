<?php

// ─── Telegram Bot API ─────────────────────────────────────────────────────────
// Токен бота, полученный от @BotFather
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');

// Username канала без @ (например: durov)
// Бот должен быть добавлен в канал как администратор
define('CHANNEL_USERNAME', 'your_channel_username');

define('TELEGRAM_API_BASE', 'https://api.telegram.org/bot' . BOT_TOKEN);
define('TELEGRAM_FILE_BASE', 'https://api.telegram.org/file/bot' . BOT_TOKEN);

// ─── База данных ──────────────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');         // MAMP: 8889, стандартный MySQL: 3306
define('DB_NAME', 'tglenta');
define('DB_USER', 'root');
define('DB_PASS', 'your_db_password');

// ─── Настройки приложения ─────────────────────────────────────────────────────
// Интервал опроса Telegram в секундах (используется фронтендом)
define('SYNC_INTERVAL', 60);

// Постов на страницу в api.php
define('POSTS_PER_PAGE', 20);

// Папка для локального хранения медиафайлов (создаётся автоматически)
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('UPLOADS_URL', '/tgLenta/uploads');
