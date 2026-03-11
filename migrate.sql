-- Миграция: добавление колонки views
ALTER TABLE tg_posts ADD COLUMN IF NOT EXISTS views INT UNSIGNED DEFAULT NULL;
