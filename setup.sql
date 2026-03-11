-- Создание базы данных
CREATE DATABASE IF NOT EXISTS tglenta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE tglenta;

-- Таблица постов из Telegram-канала
CREATE TABLE IF NOT EXISTS tg_posts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tg_message_id BIGINT UNSIGNED NOT NULL UNIQUE,
    channel_id    BIGINT NOT NULL,
    text          TEXT,
    media_type    ENUM('none','photo','video','document','animation') DEFAULT 'none',
    media_file_id VARCHAR(255),
    media_url     VARCHAR(500),
    thumb_url     VARCHAR(500),
    post_date     DATETIME NOT NULL,
    views         INT UNSIGNED DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_post_date (post_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица состояния синхронизации (хранит last_update_id)
CREATE TABLE IF NOT EXISTS tg_state (
    `key`   VARCHAR(64) PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Начальное значение offset
INSERT IGNORE INTO tg_state (`key`, `value`) VALUES ('last_update_id', '0');
