-- Adds size column

ALTER TABLE /*_*/data_dump ADD COLUMN dumps_size INT unsigned NOT NULL DEFAULT 0;
