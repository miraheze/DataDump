-- Adds status column

ALTER TABLE /*_*/data_dump ADD COLUMN dumps_status VARCHAR(100) DEFAULT '' NOT NULL;
