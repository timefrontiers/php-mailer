-- =============================================================================
-- timefrontiers/php-mailer  —  Migration: lnk_msgservice schema → TF schema
-- =============================================================================
-- Run this script inside whichever database holds the old tables.
-- It operates purely with ALTER TABLE / UPDATE — no database rename.
--
-- Safe execution order (do not reorder sections):
--   1–3 : add id PK to code-keyed tables  (emails, email_templates, mailing_lists)
--   4   : widen mailer_profiles columns
--   5   : migrate email_recipients  (needs emails.id + mailing_lists.id)
--   6   : migrate email_attachments (needs emails.id)
--   7   : migrate email_log         (needs emails.id)
--   8   : finish emails columns     (needs email_templates.id)
--   9   : finish email_templates columns
-- =============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 1 — emails: add id PK
-- Old: code CHAR(14) PRIMARY KEY
-- New: id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
--      code CHAR(15) UNIQUE
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `emails`
  ADD COLUMN `id` BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST;

-- Assign sequential ids (deterministic order by code)
SET @n = 0;
UPDATE `emails` SET `id` = (@n := @n + 1) ORDER BY `code`;

ALTER TABLE `emails`
  DROP PRIMARY KEY,
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (`id`),
  MODIFY COLUMN `code` CHAR(15) NOT NULL,
  ADD UNIQUE KEY `uq_emails_code` (`code`);


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 2 — email_templates: add id PK + add _updated
-- Old: code CHAR(14) PRIMARY KEY; no _updated
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `email_templates`
  ADD COLUMN `id` BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST;

SET @n = 0;
UPDATE `email_templates` SET `id` = (@n := @n + 1) ORDER BY `code`;

ALTER TABLE `email_templates`
  DROP PRIMARY KEY,
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (`id`),
  MODIFY COLUMN `code`  CHAR(15)     NOT NULL,
  MODIFY COLUMN `user`  VARCHAR(16)  NOT NULL,
  MODIFY COLUMN `body`  MEDIUMTEXT   NOT NULL,
  ADD UNIQUE KEY `uq_email_templates_code` (`code`),
  ADD KEY `idx_email_templates_user` (`user`),
  ADD COLUMN `_updated` DATETIME NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER `_created`;


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 3 — mailing_lists: add id PK, rename title → name, drop description
-- Old: code CHAR(14) PRIMARY KEY; title CHAR(96); description VARCHAR(256)
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `mailing_lists`
  ADD COLUMN `id` BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST;

SET @n = 0;
UPDATE `mailing_lists` SET `id` = (@n := @n + 1) ORDER BY `code`;

ALTER TABLE `mailing_lists`
  DROP PRIMARY KEY,
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (`id`),
  MODIFY COLUMN `code` CHAR(15)    NOT NULL,
  MODIFY COLUMN `user` VARCHAR(16) NOT NULL,
  CHANGE COLUMN `title` `name` VARCHAR(128) NOT NULL,
  DROP COLUMN `description`,
  ADD UNIQUE KEY `uq_mailing_lists_code` (`code`),
  ADD KEY `idx_mailing_lists_user` (`user`),
  ADD COLUMN `_updated` DATETIME NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER `_created`;


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 4 — mailer_profiles: widen columns + promote id to BIGINT
-- Old: id INT(10) UNSIGNED; address CHAR(128); name CHAR(56); surname CHAR(56)
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE `mailer_profiles`
  MODIFY COLUMN `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN `address` VARCHAR(320) NOT NULL,
  MODIFY COLUMN `name`    VARCHAR(100) NOT NULL DEFAULT '',
  MODIFY COLUMN `surname` VARCHAR(100) DEFAULT NULL;


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 5 — email_recipients: int FKs replacing char-code columns
-- Old: id INT UNSIGNED; email CHAR(14) NOT NULL; mlist CHAR(14) DEFAULT NULL
-- New: id BIGINT UNSIGNED; email_id BIGINT UNSIGNED nullable; mlist_id BIGINT UNSIGNED nullable
-- ─────────────────────────────────────────────────────────────────────────────

-- 5a. Promote id, add new FK columns
ALTER TABLE `email_recipients`
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD COLUMN `email_id` BIGINT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD COLUMN `mlist_id` BIGINT UNSIGNED DEFAULT NULL AFTER `email_id`;

-- 5b. Back-fill email_id from the old code-reference in `email`
UPDATE `email_recipients` er
  JOIN `emails` e ON e.`code` = er.`email`
  SET er.`email_id` = e.`id`;

-- 5c. Back-fill mlist_id from the old code-reference in `mlist`
UPDATE `email_recipients` er
  JOIN `mailing_lists` ml ON ml.`code` = er.`mlist`
  SET er.`mlist_id` = ml.`id`
  WHERE er.`mlist` IS NOT NULL;

-- 5d. Drop old code-ref columns
ALTER TABLE `email_recipients`
  DROP COLUMN `email`,
  DROP COLUMN `mlist`;

-- 5e. Widen other columns + tighten type enum
ALTER TABLE `email_recipients`
  MODIFY COLUMN `type`    ENUM('to','cc','bcc','reply-to') NOT NULL DEFAULT 'to',
  MODIFY COLUMN `address` VARCHAR(320) NOT NULL,
  MODIFY COLUMN `name`    VARCHAR(100) DEFAULT NULL,
  MODIFY COLUMN `surname` VARCHAR(100) DEFAULT NULL;

-- 5f. Indexes + FK constraints
ALTER TABLE `email_recipients`
  ADD KEY `idx_email_recipients_email_id` (`email_id`),
  ADD KEY `idx_email_recipients_mlist_id` (`mlist_id`),
  ADD KEY `idx_email_recipients_address`  (`address`(191)),
  ADD UNIQUE KEY `uq_email_recipients_per_email` (`email_id`, `address`, `type`),
  ADD CONSTRAINT `fk_email_recipients_email_id`
    FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_email_recipients_mlist_id`
    FOREIGN KEY (`mlist_id`) REFERENCES `mailing_lists` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 6 — email_attachments: rename email → email_id + fid → file_id
-- Old: id INT UNSIGNED; email CHAR(14); fid INT UNSIGNED
-- New: id BIGINT UNSIGNED; email_id BIGINT UNSIGNED FK; file_id BIGINT UNSIGNED
-- ─────────────────────────────────────────────────────────────────────────────

-- 6a. Promote id, add new columns
ALTER TABLE `email_attachments`
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD COLUMN `email_id` BIGINT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD COLUMN `file_id`  BIGINT UNSIGNED DEFAULT NULL AFTER `email_id`;

-- 6b. Back-fill email_id
UPDATE `email_attachments` ea
  JOIN `emails` e ON e.`code` = ea.`email`
  SET ea.`email_id` = e.`id`;

-- 6c. Back-fill file_id from old `fid`
UPDATE `email_attachments`
  SET `file_id` = `fid`;

-- 6d. Drop old columns
ALTER TABLE `email_attachments`
  DROP COLUMN `email`,
  DROP COLUMN `fid`;

-- 6e. Constraints
ALTER TABLE `email_attachments`
  ADD UNIQUE KEY `uq_email_attachments` (`email_id`, `file_id`),
  ADD KEY `idx_email_attachments_email_id` (`email_id`),
  ADD CONSTRAINT `fk_email_attachments_email_id`
    FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;
  -- file_id intentionally has no DB-level FK (cross-package reference to php-file)


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 7 — email_log: rename email → email_id; rename sender/recipient columns
-- Old: id INT; email CHAR(14); sender INT UNSIGNED; recipient INT UNSIGNED
-- New: id BIGINT UNSIGNED; email_id BIGINT UNSIGNED FK; sender_id; recipient_id
-- Note: sender + recipient were already integer FKs in the old schema — just
--       rename and widen them.
-- ─────────────────────────────────────────────────────────────────────────────

-- 7a. Promote id, add email_id
ALTER TABLE `email_log`
  MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ADD COLUMN `email_id` BIGINT UNSIGNED DEFAULT NULL AFTER `unread`;

-- 7b. Back-fill email_id
UPDATE `email_log` el
  JOIN `emails` e ON e.`code` = el.`email`
  SET el.`email_id` = e.`id`;

-- 7c. Drop old `email` code column
ALTER TABLE `email_log`
  DROP COLUMN `email`;

-- 7d. Rename sender → sender_id, recipient → recipient_id; widen both
ALTER TABLE `email_log`
  CHANGE COLUMN `sender`    `sender_id`    BIGINT UNSIGNED DEFAULT NULL,
  CHANGE COLUMN `recipient` `recipient_id` BIGINT UNSIGNED NOT NULL;

-- 7e. Indexes + FKs
ALTER TABLE `email_log`
  ADD KEY `idx_email_log_email_id`     (`email_id`),
  ADD KEY `idx_email_log_sender_id`    (`sender_id`),
  ADD KEY `idx_email_log_recipient_id` (`recipient_id`),
  ADD KEY `idx_email_log_sent`         (`sent`),
  ADD CONSTRAINT `fk_email_log_email_id`
    FOREIGN KEY (`email_id`) REFERENCES `emails` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_email_log_sender_id`
    FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_email_log_recipient_id`
    FOREIGN KEY (`recipient_id`) REFERENCES `email_recipients` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;


-- ─────────────────────────────────────────────────────────────────────────────
-- SECTION 8 — emails: finish column changes
-- Add: is_md, template_id, sender_id
-- Drop: template (code ref), header, origin, replace_pattern, thread
-- Widen: subject → VARCHAR(255), body → MEDIUMTEXT, folder → ENUM
-- ─────────────────────────────────────────────────────────────────────────────

-- 8a. Add new columns
ALTER TABLE `emails`
  ADD COLUMN `is_md`       TINYINT(1)      NOT NULL DEFAULT 0 AFTER `body`,
  ADD COLUMN `template_id` BIGINT UNSIGNED DEFAULT NULL AFTER `user`,
  ADD COLUMN `sender_id`   BIGINT UNSIGNED DEFAULT NULL AFTER `folder`;

-- 8b. Back-fill template_id from old code reference
UPDATE `emails` e
  JOIN `email_templates` et ON et.`code` = e.`template`
  SET e.`template_id` = et.`id`
  WHERE e.`template` IS NOT NULL AND e.`template` != '';

-- 8c. Drop obsolete columns
ALTER TABLE `emails`
  DROP COLUMN `template`,
  DROP COLUMN `header`,
  DROP COLUMN `origin`,
  DROP COLUMN `replace_pattern`,
  DROP COLUMN `thread`;

-- 8d. Widen / tighten remaining columns
ALTER TABLE `emails`
  MODIFY COLUMN `subject` VARCHAR(255) NOT NULL,
  MODIFY COLUMN `body`    MEDIUMTEXT   NOT NULL,
  MODIFY COLUMN `folder`  ENUM('DRAFT','OUTBOX','SENT') NOT NULL DEFAULT 'DRAFT';

-- 8e. Indexes + FKs
ALTER TABLE `emails`
  ADD KEY `idx_emails_user`        (`user`),
  ADD KEY `idx_emails_folder`      (`folder`),
  ADD KEY `idx_emails_sender_id`   (`sender_id`),
  ADD KEY `idx_emails_template_id` (`template_id`),
  ADD CONSTRAINT `fk_emails_template_id`
    FOREIGN KEY (`template_id`) REFERENCES `email_templates` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_emails_sender_id`
    FOREIGN KEY (`sender_id`) REFERENCES `mailer_profiles` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;
