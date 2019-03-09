CREATE TABLE /*_*/data_dump (
  `dumps_completed` INT(1) NOT NULL,
  `dumps_filename` LONGTEXT NOT NULL,
  `dumps_failed` INT(1) NOT NULL,
  `dumps_type` LONGTEXT NOT NULL
) /*$wgDBTableOptions*/;
