CREATE TABLE /*_*/data_dump (
  `dumps_filename` LONGTEXT NOT NULL,
  `dumps_size` BIGINT unsigned NOT NULL DEFAULT 0,
  `dumps_status` VARCHAR(100) DEFAULT '' NOT NULL,
  `dumps_timestamp` VARCHAR(14) NOT NULL,
  `dumps_type` LONGTEXT NOT NULL
) /*$wgDBTableOptions*/;
