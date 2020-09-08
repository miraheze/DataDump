CREATE TABLE /*_*/data_dump (
  `dumps_completed` INT(1) NOT NULL,
  `dumps_filename` LONGTEXT NOT NULL,
  `dumps_failed` INT(1) NOT NULL,
  `dumps_size` BIGINT unsigned NOT NULL DEFAULT 0,
  `dumps_timestamp` VARCHAR(14) NOT NULL,
  `dumps_type` LONGTEXT NOT NULL
) /*$wgDBTableOptions*/;
