-- Миграция: добавление колонки views
ALTER TABLE tg_posts ADD COLUMN views INT UNSIGNED DEFAULT NULL;
