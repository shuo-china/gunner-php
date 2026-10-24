-- Cloud snapshots are separate from future server-authoritative economic tables.
CREATE TABLE IF NOT EXISTS `{{prefix}}game_save` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `snapshot` MEDIUMTEXT NOT NULL,
  `saved_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`user_id`, `namespace`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_save_history` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `snapshot` MEDIUMTEXT NOT NULL,
  `saved_at_ms` BIGINT NOT NULL,
  `utc_day` INT UNSIGNED NOT NULL,
  `is_daily` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `namespace`, `revision`),
  KEY `daily` (`user_id`, `namespace`, `is_daily`, `utc_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_save_request` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `saved_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`user_id`, `namespace`, `request_id`),
  KEY `expiry` (`user_id`, `namespace`, `saved_at_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
