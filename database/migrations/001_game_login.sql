-- Replace {{prefix}} using game:migrate. No sample accounts, destructive DROP or secret values.
CREATE TABLE IF NOT EXISTS `{{prefix}}user` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nickname` VARCHAR(255) DEFAULT NULL,
  `mobile` VARCHAR(255) DEFAULT NULL,
  `avatar` VARCHAR(255) DEFAULT '',
  `gender` TINYINT NOT NULL DEFAULT 1,
  `status` TINYINT NOT NULL DEFAULT 1,
  `last_login_ip` VARCHAR(45) DEFAULT '',
  `last_login_time` INT DEFAULT NULL,
  `create_time` INT NOT NULL DEFAULT 0,
  `update_time` INT NOT NULL DEFAULT 0,
  `delete_time` INT DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_identity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `provider` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `app_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `openid` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unionid` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `create_time` INT NOT NULL, `update_time` INT NOT NULL, `last_login_time` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `platform_identity` (`provider`, `app_id`, `openid`),
  UNIQUE KEY `player_identity` (`user_id`, `provider`, `app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL, `identity_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at_ms` BIGINT NOT NULL, `revoked_at_ms` BIGINT DEFAULT NULL,
  `create_time` INT NOT NULL, `last_seen_at_ms` BIGINT NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `session_token` (`token_hash`),
  KEY `player_expiry` (`user_id`, `expires_at_ms`), KEY `expiry` (`expires_at_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}game_login_throttle` (
  `bucket_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` INT UNSIGNED NOT NULL, `expires_at` INT NOT NULL,
  PRIMARY KEY (`bucket_key`), KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
