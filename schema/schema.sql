-- =============================================================================
-- timefrontiers/php-mailer  —  Database Schema
-- =============================================================================
-- Database: supplied at runtime (not hardcoded here).
-- Naming conventions:
--   * Internal PK:  id BIGINT UNSIGNED AUTO_INCREMENT
--   * Public ref:   code CHAR(15) UNIQUE  (prefix + 12 random digits)
--   * Audit cols:   _author, _created, _updated
--   * FK columns:   <entity>_id  (integer FK → <table>.id)
-- =============================================================================


-- -----------------------------------------------------------------------------
-- mailer_profiles
-- Verified sender identities (From addresses).
-- No public code — looked up by address.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mailer_profiles` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `address`  VARCHAR(320)    NOT NULL,
  `name`     VARCHAR(100)    NOT NULL DEFAULT '',
  `surname`  VARCHAR(100)    NOT NULL DEFAULT '',
  `_author`  VARCHAR(320)             DEFAULT NULL,
  `_created` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mailer_profiles_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- email_templates
-- Outer HTML shells injected with body content via the %{body} token.
-- Supports both raw HTML and Markdown (is_md = 1).
-- Code prefix: 429
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`     CHAR(15)        NOT NULL,
  `user`     VARCHAR(16)     NOT NULL,
  `title`    VARCHAR(128)    NOT NULL,
  `body`     MEDIUMTEXT      NOT NULL,
  `is_md`    TINYINT(1)      NOT NULL DEFAULT 0,
  `_author`  VARCHAR(320)             DEFAULT NULL,
  `_created` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_templates_code` (`code`),
  KEY        `idx_email_templates_user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- mailing_lists
-- Named groups of recipients. Members are rows in email_recipients with
-- mlist_id set and email_id = NULL.
-- Code prefix: 218
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mailing_lists` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`     CHAR(15)        NOT NULL,
  `user`     VARCHAR(16)     NOT NULL,
  `name`     VARCHAR(128)    NOT NULL,
  `_author`  VARCHAR(320)             DEFAULT NULL,
  `_created` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mailing_lists_code` (`code`),
  KEY        `idx_mailing_lists_user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- emails
-- Core email entity. Begins as DRAFT, moves to OUTBOX when queued,
-- lands in SENT once delivered.
-- Code prefix: 421
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `emails` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        CHAR(15)        NOT NULL,
  `user`        VARCHAR(16)     NOT NULL,
  `template_id` BIGINT UNSIGNED          DEFAULT NULL,
  `subject`     VARCHAR(255)    NOT NULL,
  `body`        MEDIUMTEXT      NOT NULL,
  `is_md`       TINYINT(1)      NOT NULL DEFAULT 0,
  `folder`      ENUM('draft','outbox','sent') NOT NULL DEFAULT 'draft',
  `sender_id`   BIGINT UNSIGNED          DEFAULT NULL,
  `_author`     VARCHAR(320)             DEFAULT NULL,
  `_created`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emails_code`   (`code`),
  KEY        `idx_emails_user`  (`user`),
  KEY        `idx_emails_folder` (`folder`),
  KEY        `idx_emails_sender_id` (`sender_id`),
  KEY        `idx_emails_template_id` (`template_id`),

  CONSTRAINT `fk_emails_template_id`
    FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,

  CONSTRAINT `fk_emails_sender_id`
    FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- email_recipients
-- One row per address per email (or per mailing list for list members).
--
--   email_id NOT NULL, mlist_id NULL  →  direct recipient of a specific email
--   email_id NULL,    mlist_id NOT NULL → standing member of a mailing list
--   email_id NOT NULL, mlist_id NOT NULL → list member at the time of a send
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_recipients` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_id` BIGINT UNSIGNED          DEFAULT NULL,
  `mlist_id` BIGINT UNSIGNED          DEFAULT NULL,
  `type`     ENUM('to','cc','bcc','reply-to') NOT NULL DEFAULT 'to',
  `address`  VARCHAR(320)    NOT NULL,
  `name`     VARCHAR(100)             DEFAULT NULL,
  `surname`  VARCHAR(100)             DEFAULT NULL,
  `_created` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_email_recipients_email_id`  (`email_id`),
  KEY `idx_email_recipients_mlist_id`  (`mlist_id`),
  KEY `idx_email_recipients_address`   (`address`),

  UNIQUE KEY `uq_email_recipients_per_email`
    (`email_id`, `address`, `type`),

  CONSTRAINT `fk_email_recipients_email_id`
    FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT `fk_email_recipients_mlist_id`
    FOREIGN KEY (`mlist_id`) REFERENCES `mailing_lists` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- email_attachments
-- Maps a persisted email to a file stored in timefrontiers/php-file
-- (file_meta table). Transient attachments (fromPath) do not appear here.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_attachments` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_id` BIGINT UNSIGNED NOT NULL,
  `file_id`  BIGINT UNSIGNED NOT NULL,
  `_created` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email_attachments` (`email_id`, `file_id`),
  KEY        `idx_email_attachments_email_id` (`email_id`),

  CONSTRAINT `fk_email_attachments_email_id`
    FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
  -- file_id is a cross-package FK (→ file_meta.id); no DB-level constraint
  -- to keep the schema portable across deployments that may use separate DBs.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- email_log
-- One row per outbound recipient per email.
-- sent = 0  →  queued / pending delivery
-- sent = 1  →  dispatched; qref holds the provider message-ID
-- unread = 1 → recipient has not yet read the message
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `priority`     TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `qref`         VARCHAR(320)             DEFAULT NULL,
  `sent`         TINYINT(1)      NOT NULL DEFAULT 0,
  `unread`       TINYINT(1)      NOT NULL DEFAULT 1,
  `email_id`     BIGINT UNSIGNED NOT NULL,
  `sender_id`    BIGINT UNSIGNED          DEFAULT NULL,
  `recipient_id` BIGINT UNSIGNED NOT NULL,
  `_author`      VARCHAR(320)             DEFAULT NULL,
  `_created`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_email_log_email_id`     (`email_id`),
  KEY `idx_email_log_sender_id`    (`sender_id`),
  KEY `idx_email_log_recipient_id` (`recipient_id`),
  KEY `idx_email_log_sent`         (`sent`),

  CONSTRAINT `fk_email_log_email_id`
    FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,

  CONSTRAINT `fk_email_log_sender_id`
    FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,


  CONSTRAINT `fk_email_log_recipient_id`
    FOREIGN KEY (`recipient_id`) REFERENCES `email_recipients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- email_queue
-- Bulk-email queue for personalized batch sending (newsletters, campaigns).
--
-- body stores the template-rendered shell with %{body} already substituted
-- but per-recipient %{token} placeholders still intact for dispatch-time
-- replacement. recipients is a JSON array:
--   [{"contact": "Name <email>", "replaceValues": {"user-name": "John"}}, …]
--
-- status lifecycle: pending → processing → sent | failed
-- Processed by Email\Queue::processNext() or Queue::dispatch().
-- NOTE: FK on sender_id is added below via ALTER TABLE so this statement
--       can succeed even when run in isolation (mailer_profiles must exist
--       before the ALTER TABLE is executed).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `status`     ENUM('pending','processing','sent','failed')
                                NOT NULL DEFAULT 'pending',
  `sender_id`  BIGINT UNSIGNED  NOT NULL,
  `subject`    VARCHAR(255)     NOT NULL,
  `body`       MEDIUMTEXT       NOT NULL,
  `recipients` JSON             NOT NULL,
  `driver`     VARCHAR(64)               DEFAULT NULL,
  `_created`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `_updated`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_email_queue_status`    (`status`),
  KEY `idx_email_queue_sender_id` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add FK separately — mailer_profiles must exist before running this line.
ALTER TABLE `email_queue`
  ADD CONSTRAINT `fk_email_queue_sender_id`
    FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE;

