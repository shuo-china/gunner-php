-- Reward receipts are permanent: deleting one would weaken lost-response recovery.
CREATE TABLE IF NOT EXISTS `{{prefix}}game_reward_request` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `reward_json` MEDIUMTEXT NOT NULL,
  `created_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`user_id`, `namespace`, `request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A code's row lock serializes claims across different accounts.
CREATE TABLE IF NOT EXISTS `{{prefix}}game_redeem_code` (
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` VARBINARY(128) NOT NULL,
  `mode` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `diamonds` BIGINT UNSIGNED NOT NULL,
  `claimed_user_id` INT UNSIGNED DEFAULT NULL,
  `claimed_at_ms` BIGINT DEFAULT NULL,
  PRIMARY KEY (`namespace`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_redeem_claim` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `code` VARBINARY(128) NOT NULL,
  `global_code` VARBINARY(128) DEFAULT NULL,
  `request_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`user_id`, `namespace`, `code`),
  UNIQUE KEY `one_global_winner` (`namespace`, `global_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_daily_claim` (
  `user_id` INT UNSIGNED NOT NULL,
  `namespace` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `day` DATE NOT NULL,
  `request_id` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`user_id`, `namespace`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
