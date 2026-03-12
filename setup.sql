-- ─── Создание базы данных ─────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS tglenta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE tglenta;

-- ─── Посты из Telegram-канала ─────────────────────────────────────────────────
-- channel_id позволяет хранить посты нескольких каналов в одной БД.
-- Каждый деплой фильтрует по своему CHANNEL_ID из config.local.php.
CREATE TABLE IF NOT EXISTS tg_posts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tg_message_id BIGINT UNSIGNED NOT NULL,
    channel_id    BIGINT NOT NULL,
    text          TEXT,
    media_type    ENUM('none','photo','video','document','animation') DEFAULT 'none',
    media_file_id VARCHAR(255),
    media_url     VARCHAR(500),
    thumb_url     VARCHAR(500),
    post_date     DATETIME NOT NULL,
    views         INT UNSIGNED DEFAULT NULL,
    entities      TEXT DEFAULT NULL,
    media_group_id VARCHAR(64) DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_channel_message (channel_id, tg_message_id),
    INDEX idx_channel_date (channel_id, post_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Комментарии к постам ─────────────────────────────────────────────────────
-- Сохраняются из связанной группы обсуждений (Discussion group).
-- Требует DISCUSSION_GROUP_ID в конфиге.
CREATE TABLE IF NOT EXISTS tg_comments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id             INT UNSIGNED NOT NULL,
    discussion_group_id BIGINT NOT NULL,
    tg_message_id       BIGINT NOT NULL,
    message_thread_id   BIGINT DEFAULT NULL,
    user_id             BIGINT DEFAULT NULL,
    user_name           VARCHAR(255) DEFAULT NULL,
    user_username       VARCHAR(100) DEFAULT NULL,
    text                TEXT,
    entities            TEXT DEFAULT NULL,
    post_date           DATETIME NOT NULL,
    UNIQUE KEY uq_group_msg (discussion_group_id, tg_message_id),
    INDEX idx_post_id (post_id),
    INDEX idx_thread (discussion_group_id, message_thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Состояние синхронизации ──────────────────────────────────────────────────
-- Хранит last_update_id отдельно для каждого канала.
CREATE TABLE IF NOT EXISTS tg_state (
    channel_id BIGINT      NOT NULL,
    `key`      VARCHAR(64) NOT NULL,
    `value`    VARCHAR(255) NOT NULL,
    PRIMARY KEY (channel_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
