-- timefrontiers/php-mailer v1.0.x -> v1.1.0
-- Idempotent for MySQL 8.0.29+ and MariaDB 10.6+.
-- Take a verified backup and stop v1.0 workers before applying.

ALTER TABLE `emails`
  ADD COLUMN IF NOT EXISTS `sender_snapshot` JSON DEFAULT NULL AFTER `sender_id`,
  ADD COLUMN IF NOT EXISTS `driver` VARCHAR(64) DEFAULT NULL AFTER `sender_snapshot`,
  ADD COLUMN IF NOT EXISTS `driver_config` JSON DEFAULT NULL AFTER `driver`,
  ADD COLUMN IF NOT EXISTS `delivery_mode` VARCHAR(32) NOT NULL DEFAULT 'individual' AFTER `driver_config`,
  ADD COLUMN IF NOT EXISTS `log_body` TINYINT(1) NOT NULL DEFAULT 1 AFTER `delivery_mode`;

UPDATE `emails` `e`
LEFT JOIN `mailer_profiles` `p` ON `p`.`id`=`e`.`sender_id`
SET `e`.`sender_snapshot`=JSON_OBJECT('id',`p`.`id`,'address',`p`.`address`,'name',`p`.`name`,'surname',`p`.`surname`)
WHERE `e`.`sender_snapshot` IS NULL AND `p`.`id` IS NOT NULL;

UPDATE `emails` SET `log_body`=0 WHERE `body`='***redacted***';

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

INSERT IGNORE INTO `mailing_list_members` (`mailing_list_id`,`type`,`address`,`name`,`surname`,`_created`)
SELECT `mlist_id`,`type`,`address`,`name`,`surname`,`_created`
FROM `email_recipients`
WHERE `email_id` IS NULL AND `mlist_id` IS NOT NULL;

CREATE TABLE IF NOT EXISTS `email_recipients_v10_list_members` LIKE `email_recipients`;
INSERT IGNORE INTO `email_recipients_v10_list_members`
SELECT * FROM `email_recipients` WHERE `email_id` IS NULL;

-- Repair any historical log that incorrectly points at a standing list row by
-- materializing the same address as a proper per-email recipient first.
INSERT IGNORE INTO `email_recipients` (`email_id`,`mlist_id`,`type`,`address`,`name`,`surname`,`_created`)
SELECT DISTINCT `l`.`email_id`,`r`.`mlist_id`,`r`.`type`,`r`.`address`,`r`.`name`,`r`.`surname`,`r`.`_created`
FROM `email_log` `l`
INNER JOIN `email_recipients` `r` ON `r`.`id`=`l`.`recipient_id`
WHERE `r`.`email_id` IS NULL;

UPDATE `email_log` `l`
INNER JOIN `email_recipients` `old_recipient` ON `old_recipient`.`id`=`l`.`recipient_id` AND `old_recipient`.`email_id` IS NULL
INNER JOIN `email_recipients` `new_recipient`
  ON `new_recipient`.`email_id`=`l`.`email_id`
 AND `new_recipient`.`address`=`old_recipient`.`address`
 AND `new_recipient`.`type`=`old_recipient`.`type`
SET `l`.`recipient_id`=`new_recipient`.`id`;

DELETE FROM `email_recipients` WHERE `email_id` IS NULL;
ALTER TABLE `email_recipients` MODIFY COLUMN `email_id` BIGINT UNSIGNED NOT NULL;

ALTER TABLE `email_queue`
  MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'building',
  ADD COLUMN IF NOT EXISTS `email_id` BIGINT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sender_snapshot` JSON DEFAULT NULL AFTER `sender_id`,
  ADD COLUMN IF NOT EXISTS `body_text` MEDIUMTEXT DEFAULT NULL AFTER `body`,
  ADD COLUMN IF NOT EXISTS `headers` JSON DEFAULT NULL AFTER `body_text`,
  ADD COLUMN IF NOT EXISTS `driver_config` JSON DEFAULT NULL AFTER `driver`,
  ADD COLUMN IF NOT EXISTS `delivery_mode` VARCHAR(32) NOT NULL DEFAULT 'individual' AFTER `driver_config`,
  ADD COLUMN IF NOT EXISTS `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER `delivery_mode`,
  ADD COLUMN IF NOT EXISTS `worker_id` VARCHAR(128) DEFAULT NULL AFTER `priority`,
  ADD COLUMN IF NOT EXISTS `lease_expires_at` DATETIME DEFAULT NULL AFTER `worker_id`,
  ADD COLUMN IF NOT EXISTS `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `lease_expires_at`,
  ADD COLUMN IF NOT EXISTS `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER `attempts`,
  ADD COLUMN IF NOT EXISTS `next_attempt_at` DATETIME DEFAULT NULL AFTER `max_attempts`,
  ADD COLUMN IF NOT EXISTS `last_error_code` VARCHAR(128) DEFAULT NULL AFTER `next_attempt_at`,
  ADD COLUMN IF NOT EXISTS `reconciliation_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_error_code`;

UPDATE `email_queue` `q`
LEFT JOIN `mailer_profiles` `p` ON `p`.`id`=`q`.`sender_id`
SET `q`.`sender_snapshot`=COALESCE(`q`.`sender_snapshot`,JSON_OBJECT('id',`p`.`id`,'address',`p`.`address`,'name',`p`.`name`,'surname',`p`.`surname`)),
    `q`.`body_text`=COALESCE(`q`.`body_text`,''),
    `q`.`headers`=COALESCE(`q`.`headers`,JSON_OBJECT()),
    `q`.`driver`=COALESCE(`q`.`driver`,''),
    `q`.`driver_config`=COALESCE(`q`.`driver_config`,JSON_OBJECT()),
    `q`.`max_attempts`=IF(`q`.`max_attempts`<1,5,`q`.`max_attempts`);

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

CREATE TABLE IF NOT EXISTS `email_queue_migration_exceptions` (
  `email_id` BIGINT UNSIGNED NOT NULL,
  `error_code` VARCHAR(128) NOT NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email_id`,`error_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `email_queue_migration_exceptions` (`email_id`,`error_code`)
SELECT `id`,'legacy_outbox_missing_sender'
FROM `emails`
WHERE `folder`='outbox' AND `sender_id` IS NULL;

-- Old Email::queue() outbox messages did not persist a transport snapshot.
-- Materialize them for review, but never make them worker-eligible automatically.
INSERT INTO `email_queue`
  (`email_id`,`status`,`sender_id`,`sender_snapshot`,`subject`,`body`,`body_text`,`headers`,`recipients`,`driver`,`driver_config`,`delivery_mode`,`priority`,`max_attempts`,`last_error_code`,`reconciliation_required`)
SELECT
  `e`.`id`,'reconciliation',`e`.`sender_id`,`e`.`sender_snapshot`,`e`.`subject`,`e`.`body`,'',JSON_OBJECT(),JSON_ARRAY(),'',JSON_OBJECT(),'individual',
  COALESCE(MIN(`l`.`priority`),5),5,'legacy_outbox_driver_snapshot_missing',1
FROM `emails` `e`
LEFT JOIN `email_log` `l` ON `l`.`email_id`=`e`.`id` AND `l`.`sent`=0
LEFT JOIN `email_queue` `existing` ON `existing`.`email_id`=`e`.`id`
WHERE `e`.`folder`='outbox' AND `e`.`sender_id` IS NOT NULL AND `existing`.`id` IS NULL
GROUP BY `e`.`id`,`e`.`sender_id`,`e`.`sender_snapshot`,`e`.`subject`,`e`.`body`;

INSERT IGNORE INTO `email_queue_recipients`
  (`queue_id`,`source_recipient_id`,`ordinal`,`type`,`address`,`name`,`surname`,`replacements`,`status`,`next_attempt_at`)
SELECT
  `q`.`id`,`r`.`id`,ROW_NUMBER() OVER (PARTITION BY `q`.`id` ORDER BY `r`.`id`),`r`.`type`,`r`.`address`,`r`.`name`,`r`.`surname`,JSON_OBJECT(),'pending',CURRENT_TIMESTAMP
FROM `email_queue` `q`
INNER JOIN `email_recipients` `r` ON `r`.`email_id`=`q`.`email_id`
WHERE `q`.`email_id` IS NOT NULL AND `r`.`type` IN ('to','cc','bcc');

DELIMITER //
DROP PROCEDURE IF EXISTS `mailer_migrate_queue_recipients_v110`//
CREATE PROCEDURE `mailer_migrate_queue_recipients_v110`()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE queue_id_value BIGINT UNSIGNED;
  DECLARE payload JSON;
  DECLARE item_count INT;
  DECLARE item_index INT;
  DECLARE address_value VARCHAR(320);
  DECLARE name_value VARCHAR(100);
  DECLARE replacements_value JSON;
  DECLARE queue_cursor CURSOR FOR
    SELECT `id`,`recipients` FROM `email_queue`
    WHERE JSON_VALID(`recipients`) AND JSON_LENGTH(`recipients`)>0;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;

  OPEN queue_cursor;
  queue_loop: LOOP
    FETCH queue_cursor INTO queue_id_value,payload;
    IF done=1 THEN LEAVE queue_loop; END IF;
    SET item_count=JSON_LENGTH(payload);
    SET item_index=0;
    WHILE item_index<item_count DO
      SET address_value=COALESCE(
        JSON_UNQUOTE(JSON_EXTRACT(payload,CONCAT('$[',item_index,'].contact.email'))),
        JSON_UNQUOTE(JSON_EXTRACT(payload,CONCAT('$[',item_index,'].contact')))
      );
      SET name_value=JSON_UNQUOTE(JSON_EXTRACT(payload,CONCAT('$[',item_index,'].contact.name')));
      IF address_value LIKE '%<%>%' THEN
        SET name_value=COALESCE(name_value,TRIM(SUBSTRING_INDEX(address_value,'<',1)));
        SET address_value=SUBSTRING_INDEX(SUBSTRING_INDEX(address_value,'<',-1),'>',1);
      END IF;
      SET replacements_value=COALESCE(JSON_EXTRACT(payload,CONCAT('$[',item_index,'].replaceValues')),JSON_OBJECT());
      IF address_value IS NOT NULL AND address_value LIKE '%_@_%._%' THEN
        INSERT IGNORE INTO `email_queue_recipients`
          (`queue_id`,`ordinal`,`type`,`address`,`name`,`replacements`,`status`,`next_attempt_at`)
        VALUES (queue_id_value,item_index+1,'to',address_value,name_value,replacements_value,'pending',CURRENT_TIMESTAMP);
      END IF;
      SET item_index=item_index+1;
    END WHILE;
  END LOOP;
  CLOSE queue_cursor;
END//
CALL `mailer_migrate_queue_recipients_v110`()//
DROP PROCEDURE `mailer_migrate_queue_recipients_v110`//
DELIMITER ;

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

INSERT IGNORE INTO `email_queue_attachments` (`queue_id`,`file_id`,`ordinal`)
SELECT `q`.`id`,`a`.`file_id`,ROW_NUMBER() OVER (PARTITION BY `q`.`id` ORDER BY `a`.`id`)
FROM `email_queue` `q`
INNER JOIN `email_attachments` `a` ON `a`.`email_id`=`q`.`email_id`
WHERE `q`.`email_id` IS NOT NULL;

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

-- Every legacy in-flight job is ambiguous. Never turn it into an ordinary retry.
UPDATE `email_queue`
SET `status`='reconciliation',`reconciliation_required`=1,`last_error_code`='legacy_in_flight_requires_review',`worker_id`=NULL,`lease_expires_at`=NULL
WHERE `status`='processing';

-- Old jobs do not contain complete transport secrets. They remain visible but blocked.
UPDATE `email_queue`
SET `status`='reconciliation',`reconciliation_required`=1,`last_error_code`='legacy_driver_snapshot_incomplete'
WHERE (`driver` IS NULL OR `driver`='' OR `driver_config` IS NULL OR JSON_LENGTH(`driver_config`)=0)
  AND `status` NOT IN ('sent','failed','dead_letter','reconciliation');

-- Indexes added through an idempotent metadata procedure.
CREATE TABLE IF NOT EXISTS `email_log_v10_duplicates` LIKE `email_log`;

INSERT IGNORE INTO `email_log_v10_duplicates`
SELECT `duplicate`.* FROM `email_log` `duplicate`
INNER JOIN `email_log` `keeper`
  ON `keeper`.`email_id`=`duplicate`.`email_id`
 AND `keeper`.`recipient_id`=`duplicate`.`recipient_id`
 AND `keeper`.`id`<`duplicate`.`id`;

UPDATE `email_log` `keeper`
INNER JOIN (
  SELECT `email_id`,`recipient_id`,MAX(`sent`) AS `sent`,MAX(`qref`) AS `qref`,MIN(`id`) AS `keeper_id`
  FROM `email_log` GROUP BY `email_id`,`recipient_id`
) `summary` ON `summary`.`keeper_id`=`keeper`.`id`
SET `keeper`.`sent`=`summary`.`sent`,
    `keeper`.`qref`=COALESCE(`keeper`.`qref`,`summary`.`qref`);

DELETE `duplicate` FROM `email_log` `duplicate`
INNER JOIN `email_log` `keeper`
  ON `keeper`.`email_id`=`duplicate`.`email_id`
 AND `keeper`.`recipient_id`=`duplicate`.`recipient_id`
 AND `keeper`.`id`<`duplicate`.`id`;

DELIMITER //
DROP PROCEDURE IF EXISTS `mailer_add_v110_indexes`//
CREATE PROCEDURE `mailer_add_v110_indexes`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='email_queue' AND index_name='idx_email_queue_claim') THEN
    CREATE INDEX `idx_email_queue_claim` ON `email_queue` (`status`,`reconciliation_required`,`next_attempt_at`,`priority`,`id`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='email_queue' AND index_name='idx_email_queue_lease') THEN
    CREATE INDEX `idx_email_queue_lease` ON `email_queue` (`status`,`lease_expires_at`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='email_queue' AND index_name='uq_email_queue_email_id') THEN
    CREATE UNIQUE INDEX `uq_email_queue_email_id` ON `email_queue` (`email_id`);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='email_log' AND index_name='uq_email_log_delivery') THEN
    CREATE UNIQUE INDEX `uq_email_log_delivery` ON `email_log` (`email_id`,`recipient_id`);
  END IF;
END//
CALL `mailer_add_v110_indexes`()//
DROP PROCEDURE `mailer_add_v110_indexes`//
DELIMITER ;
