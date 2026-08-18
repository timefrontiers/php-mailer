-- timefrontiers/php-mailer v1.1 fresh-install schema
-- MySQL 8.0.29+ or MariaDB 10.6+, InnoDB, utf8mb4.

CREATE TABLE IF NOT EXISTS `mailer_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `address` VARCHAR(320) NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `surname` VARCHAR(100) NOT NULL DEFAULT '',
  `_author` VARCHAR(320) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mailer_profiles_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user` VARCHAR(16) NOT NULL,
  `title` VARCHAR(128) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `is_md` TINYINT(1) NOT NULL DEFAULT 0,
  `replace_keys` JSON DEFAULT NULL,
  `_author` VARCHAR(320) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_templates_code` (`code`),
  KEY `idx_email_templates_user` (`user`),
  CONSTRAINT `chk_email_templates_public_code` CHECK (`code` REGEXP '^429[0-9]{8,12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailing_lists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user` VARCHAR(16) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `_author` VARCHAR(320) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mailing_lists_code` (`code`),
  KEY `idx_mailing_lists_user` (`user`),
  CONSTRAINT `chk_mailing_lists_public_code` CHECK (`code` REGEXP '^218[0-9]{8,12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mailing_list_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mailing_list_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('to','cc','bcc','reply-to') NOT NULL DEFAULT 'to',
  `address` VARCHAR(320) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `surname` VARCHAR(100) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mailing_list_member` (`mailing_list_id`,`address`,`type`),
  KEY `idx_mailing_list_member_address` (`address`),
  CONSTRAINT `fk_mailing_list_members_list` FOREIGN KEY (`mailing_list_id`) REFERENCES `mailing_lists` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emails` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user` VARCHAR(16) NOT NULL,
  `template_id` BIGINT UNSIGNED DEFAULT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `is_md` TINYINT(1) NOT NULL DEFAULT 0,
  `folder` ENUM('draft','outbox','sent') NOT NULL DEFAULT 'draft',
  `sender_id` BIGINT UNSIGNED DEFAULT NULL,
  `sender_snapshot` JSON DEFAULT NULL,
  `driver` VARCHAR(64) DEFAULT NULL,
  `driver_config` JSON DEFAULT NULL,
  `delivery_mode` VARCHAR(32) NOT NULL DEFAULT 'individual',
  `log_body` TINYINT(1) NOT NULL DEFAULT 1,
  `_author` VARCHAR(320) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emails_code` (`code`),
  KEY `idx_emails_user` (`user`),
  KEY `idx_emails_folder` (`folder`),
  KEY `idx_emails_sender_id` (`sender_id`),
  KEY `idx_emails_template_id` (`template_id`),
  CONSTRAINT `fk_emails_template_id` FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_emails_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_emails_public_code` CHECK (`code` REGEXP '^421[0-9]{8,12}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_id` BIGINT UNSIGNED NOT NULL,
  `mlist_id` BIGINT UNSIGNED DEFAULT NULL,
  `type` ENUM('to','cc','bcc','reply-to') NOT NULL DEFAULT 'to',
  `address` VARCHAR(320) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `surname` VARCHAR(100) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_recipients_per_email` (`email_id`,`address`,`type`),
  KEY `idx_email_recipients_mlist_id` (`mlist_id`),
  KEY `idx_email_recipients_address` (`address`),
  CONSTRAINT `fk_email_recipients_email_id` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_email_recipients_mlist_id` FOREIGN KEY (`mlist_id`) REFERENCES `mailing_lists` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_id` BIGINT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_attachments` (`email_id`,`file_id`),
  KEY `idx_email_attachments_email_id` (`email_id`),
  CONSTRAINT `fk_email_attachments_email_id` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `qref` VARCHAR(320) DEFAULT NULL,
  `sent` TINYINT(1) NOT NULL DEFAULT 0,
  `unread` TINYINT(1) NOT NULL DEFAULT 1,
  `email_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED DEFAULT NULL,
  `recipient_id` BIGINT UNSIGNED NOT NULL,
  `_author` VARCHAR(320) DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_log_delivery` (`email_id`,`recipient_id`),
  KEY `idx_email_log_sender_id` (`sender_id`),
  KEY `idx_email_log_recipient_id` (`recipient_id`),
  KEY `idx_email_log_sent` (`sent`),
  CONSTRAINT `fk_email_log_email_id` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_email_log_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_email_log_recipient_id` FOREIGN KEY (`recipient_id`) REFERENCES `email_recipients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_id` BIGINT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'building',
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `sender_snapshot` JSON NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `body_text` MEDIUMTEXT NOT NULL,
  `headers` JSON NOT NULL,
  `recipients` JSON NOT NULL,
  `driver` VARCHAR(64) NOT NULL,
  `driver_config` JSON NOT NULL,
  `delivery_mode` VARCHAR(32) NOT NULL DEFAULT 'individual',
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `worker_id` VARCHAR(128) DEFAULT NULL,
  `lease_expires_at` DATETIME DEFAULT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `next_attempt_at` DATETIME DEFAULT NULL,
  `last_error_code` VARCHAR(128) DEFAULT NULL,
  `reconciliation_required` TINYINT(1) NOT NULL DEFAULT 0,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_queue_claim` (`status`,`reconciliation_required`,`next_attempt_at`,`priority`,`id`),
  KEY `idx_email_queue_lease` (`status`,`lease_expires_at`),
  KEY `idx_email_queue_sender_id` (`sender_id`),
  UNIQUE KEY `uq_email_queue_email_id` (`email_id`),
  CONSTRAINT `fk_email_queue_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_email_queue_email_id` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue_id` BIGINT UNSIGNED NOT NULL,
  `source_recipient_id` BIGINT UNSIGNED DEFAULT NULL,
  `ordinal` INT UNSIGNED NOT NULL,
  `type` ENUM('to','cc','bcc') NOT NULL DEFAULT 'to',
  `address` VARCHAR(320) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `surname` VARCHAR(100) DEFAULT NULL,
  `replacements` JSON NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `worker_id` VARCHAR(128) DEFAULT NULL,
  `provider_message_id` VARCHAR(320) DEFAULT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error_code` VARCHAR(128) DEFAULT NULL,
  `next_attempt_at` DATETIME DEFAULT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_queue_recipient` (`queue_id`,`address`,`type`),
  UNIQUE KEY `uq_queue_recipient_order` (`queue_id`,`ordinal`),
  KEY `idx_queue_recipient_dispatch` (`queue_id`,`status`,`next_attempt_at`,`ordinal`),
  KEY `idx_queue_recipient_source` (`source_recipient_id`),
  CONSTRAINT `fk_queue_recipient_queue` FOREIGN KEY (`queue_id`) REFERENCES `email_queue` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_queue_recipient_source` FOREIGN KEY (`source_recipient_id`) REFERENCES `email_recipients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_queue_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue_id` BIGINT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `ordinal` INT UNSIGNED NOT NULL,
  `_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_queue_attachment` (`queue_id`,`file_id`),
  UNIQUE KEY `uq_queue_attachment_order` (`queue_id`,`ordinal`),
  CONSTRAINT `fk_queue_attachment_queue` FOREIGN KEY (`queue_id`) REFERENCES `email_queue` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_delivery_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_id` BIGINT UNSIGNED DEFAULT NULL,
  `recipient_id` BIGINT UNSIGNED DEFAULT NULL,
  `queue_recipient_id` BIGINT UNSIGNED DEFAULT NULL,
  `attempt_no` SMALLINT UNSIGNED NOT NULL,
  `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `driver` VARCHAR(64) NOT NULL,
  `worker_id` VARCHAR(128) DEFAULT NULL,
  `status` VARCHAR(32) NOT NULL,
  `provider_message_id` VARCHAR(320) DEFAULT NULL,
  `error_code` VARCHAR(128) DEFAULT NULL,
  `started_at` DATETIME NOT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_immediate_attempt` (`email_id`,`recipient_id`,`attempt_no`),
  UNIQUE KEY `uq_queue_attempt` (`queue_recipient_id`,`attempt_no`),
  KEY `idx_attempt_idempotency` (`idempotency_key`),
  KEY `idx_attempt_reconciliation` (`status`,`started_at`),
  CONSTRAINT `fk_attempt_email` FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attempt_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `email_recipients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attempt_queue_recipient` FOREIGN KEY (`queue_recipient_id`) REFERENCES `email_queue_recipients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
