-- ─── Миграция: добавление таблицы комментариев ────────────────────────────────
-- Запустите если БД уже создана (через старый setup.sql).
-- Для свежей установки достаточно setup.sql.

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
