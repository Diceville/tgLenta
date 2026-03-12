<?php

/**
 * Шаблон конфигурации. Скопируйте этот файл в config.local.php и заполните.
 * config.local.php НЕ коммитится в git.
 *
 * Для нового деплоя (новый канал / новый хостинг):
 *   1. git clone <repo>
 *   2. cp config.example.php config.local.php  и заполнить значения ниже
 *   3. Выполнить setup.sql в базе данных
 *   4. Открыть import.php (если есть история из Telegram Desktop)
 *   5. Открыть migrate_file_ids.php (чтобы получить file_id для старых постов)
 */

// ─── Telegram Bot API ─────────────────────────────────────────────────────────
// Создать бота: https://t.me/BotFather → /newbot
// Добавить бота в канал как администратора (чтобы он мог читать сообщения)
putenv('BOT_TOKEN=YOUR_BOT_TOKEN_HERE');

// Отображаемое название канала (заголовок сайта)
putenv('CHANNEL_USERNAME=Название канала');

// @username канала без @ — нужен для ссылок вида t.me/username/123
// Для приватного канала — оставьте пустым
putenv('CHANNEL_TG_USERNAME=');

// Числовой Telegram ID канала — используется для фильтрации постов в БД.
// Позволяет держать несколько каналов в одной базе данных.
// Узнать: SELECT DISTINCT channel_id FROM tg_posts;
// Формат: отрицательное число, например -1001665934953
putenv('CHANNEL_ID=-100XXXXXXXXXX');

// ─── База данных (MySQL/MariaDB) ───────────────────────────────────────────────
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_NAME=tglenta');
putenv('DB_USER=root');
putenv('DB_PASS=YOUR_DB_PASSWORD');

// ─── Настройки приложения ─────────────────────────────────────────────────────
// Интервал автообновления ленты в секундах (по умолчанию 60)
putenv('SYNC_INTERVAL=60');

// Количество постов на странице (по умолчанию 20)
putenv('POSTS_PER_PAGE=20');

// Базовый URL сайта. Пустая строка — если сайт в корне домена.
// Пример для MAMP: /tgLenta
putenv('BASE_URL=');

// ─── SOCKS5-прокси для запросов к Telegram ────────────────────────────────────
// Нужен если Telegram заблокирован у хостинг-провайдера.
// Формат SOCKS5_PROXY: host:port (например 185.229.65.219:1080)
// Формат SOCKS5_AUTH:  user:password (оставьте пустым если без авторизации)
// Оставьте пустыми если прокси не нужен.
putenv('SOCKS5_PROXY=');
putenv('SOCKS5_AUTH=');
