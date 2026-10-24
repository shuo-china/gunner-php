-- Sharing and daily check-in have independent daily allowances.
CREATE TABLE IF NOT EXISTS `{{prefix}}game_share_claim` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `day` DATE NOT NULL,
  `request_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`user_id`, `namespace`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
