ALTER TABLE /*$wgDBprefix*/data_dump
  MODIFY COLUMN dumps_size BIGINT unsigned NOT NULL DEFAULT 0;
