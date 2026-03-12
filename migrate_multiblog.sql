-- ─── Миграция: поддержка нескольких блогов в одной БД ────────────────────────
--
-- Запустите этот скрипт если БД уже создана (через старый setup.sql).
-- Для свежей установки достаточно setup.sql — там уже новая схема.
--
-- ВАЖНО: перед запуском укажите ваш channel_id в строке INSERT ниже.
-- Узнать channel_id: SELECT DISTINCT channel_id FROM tg_posts LIMIT 5;

-- 1. Пересоздаём tg_state с channel_id в первичном ключе
DROP TABLE IF EXISTS tg_state;

CREATE TABLE tg_state (
    channel_id BIGINT      NOT NULL,
    `key`      VARCHAR(64) NOT NULL,
    `value`    VARCHAR(255) NOT NULL,
    PRIMARY KEY (channel_id, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Вставляем начальное состояние для вашего канала
--    Замените 0 на channel_id из вашей таблицы tg_posts
INSERT INTO tg_state (channel_id, `key`, `value`)
SELECT DISTINCT channel_id, 'last_update_id', '0'
FROM tg_posts
LIMIT 1;

-- 3. Обновляем индексы tg_posts для многоканальных запросов
--    (если UNIQUE KEY по tg_message_id уже есть — удаляем его)
ALTER TABLE tg_posts
    DROP INDEX IF EXISTS tg_message_id,
    ADD UNIQUE KEY IF NOT EXISTS uq_channel_message (channel_id, tg_message_id),
    ADD INDEX IF NOT EXISTS idx_channel_date (channel_id, post_date);

-- 4. Добавляем новые поля (entities, media_group_id)
ALTER TABLE tg_posts
    ADD COLUMN IF NOT EXISTS entities       TEXT         DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS media_group_id VARCHAR(64)  DEFAULT NULL;
