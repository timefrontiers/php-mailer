<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Log;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;

/**
 * Delivery log row in `email_log`.
 *
 * One row is created per outbound recipient at queue/send time. The row
 * tracks whether the message has been delivered (`sent = 1`) and whether
 * the recipient has read it (`unread = 0`), along with the provider's
 * message reference (`qref`).
 *
 * Table:  email_log
 * PK:     id (BIGINT UNSIGNED AUTO_INCREMENT)
 */
/** @phpstan-consistent-constructor */
class EmailLog
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'email_log';
  /** @var list<string> */
  protected static array  $_db_fields   = [
    'id', 'priority', 'qref', 'sent', 'unread',
    'email_id', 'sender_id', 'recipient_id',
    '_author', '_created', '_updated',
  ];

  public ?int    $id           = null;
  public int     $priority     = 5;      // 1 (high) – 10 (low)
  public ?string $qref         = null;   // Provider message-ID after delivery
  public bool    $sent         = false;
  public bool    $unread       = true;
  public ?int    $email_id     = null;   // FK → emails.id
  public ?int    $sender_id    = null;   // FK → mailer_profiles.id
  public ?int    $recipient_id = null;   // FK → email_recipients.id

  protected ?string $_author  = null;
  protected ?string $_created = null;
  protected ?string $_updated = null;

  // -------------------------------------------------------------------------
  // Boot
  // -------------------------------------------------------------------------

  public function __construct(?SQLDatabase $conn = null)
  {
    if ($conn !== null) {
      static::$_db_name = Config::get()->dbName;
      $this->setConnection($conn);
      static::useConnection($conn);
    } elseif (static::$_db_name === '') {
      static::$_db_name = Config::get()->dbName;
    }
  }

  // -------------------------------------------------------------------------
  // Factory helpers
  // -------------------------------------------------------------------------

  /**
   * Create and persist a new queued log entry.
   *
   * Call this immediately after the email row and recipient row are created.
   * The row represents one pending delivery — call markSent() once the driver
   * confirms transmission.
   *
   * @throws \RuntimeException on persistence failure.
   */
  public static function queue(
    SQLDatabase $conn,
    int         $emailId,
    int         $senderId,
    int         $recipientId,
    int         $priority = 5,
  ): self {
    $instance = new self($conn);
    $priority = max(1, min(10, $priority));
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($conn,
      "INSERT INTO `{$db}`.`email_log` (`priority`,`sent`,`unread`,`email_id`,`sender_id`,`recipient_id`) VALUES (?,0,1,?,?,?) "
      . 'ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`priority`=VALUES(`priority`),`sender_id`=VALUES(`sender_id`)',
      [$priority, $emailId, $senderId, $recipientId],
    );
    if ($result === false) {
      throw new \RuntimeException("Email log entry for email {$emailId} could not be persisted.");
    }
    $row = DatabaseGateway::fetchOne($conn,
      "SELECT `id`,`priority`,`qref`,`sent`,`unread`,`email_id`,`sender_id`,`recipient_id`,`_author`,`_created`,`_updated` FROM `{$db}`.`email_log` WHERE `email_id`=? AND `recipient_id`=? LIMIT 1",
      [$emailId, $recipientId],
    );
    if (!is_array($row)) {
      throw new \RuntimeException('Persisted email log could not be reloaded.');
    }
    return self::_instantiateFromRow($row, $conn);
  }

  /**
   * Load a single log entry by its primary-key id.
   */
  public static function loadById(SQLDatabase $conn, int $id): ?self
  {
    $instance = new self($conn);
    $found    = self::findBySql(
      'SELECT `id`,`priority`,`qref`,`sent`,`unread`,`email_id`,`sender_id`,`recipient_id`,`_author`,`_created`,`_updated` FROM :db:.:tbl: WHERE `id` = ? LIMIT 1',
      [$id],
    );
    if ($found === false) {
      throw new \RuntimeException('Email log lookup failed.');
    }
    return $found === [] ? null : $found[0];
  }

  // -------------------------------------------------------------------------
  // State transitions
  // -------------------------------------------------------------------------

  /**
   * Record successful delivery — store the provider's message reference and
   * flip sent to true.
   *
   * @param string $qref  Provider message-ID (e.g. Mailgun's <id@mg.domain>).
   * @return bool         False if the row could not be persisted.
   */
  public function markSent(string $qref): bool
  {
    if ($this->id === null || $qref === '') {
      return false;
    }
    if ($this->sent && $this->qref === $qref) {
      return true;
    }
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_log` SET `qref`=?,`sent`=1 WHERE `id`=? AND `sent`=0",
      [$qref, $this->id],
    );
    if ($result === false || $this->conn()->affectedRows() !== 1) {
      return false;
    }
    $this->qref = $qref;
    $this->sent = true;
    return true;
  }

  /**
   * Mark the message as read by the recipient.
   *
   * @return bool  False if the row could not be persisted.
   */
  public function markRead(): bool
  {
    if ($this->id === null || !$this->unread) {
      return $this->id !== null;
    }
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_log` SET `unread`=0 WHERE `id`=? AND `unread`=1",
      [$this->id],
    );
    if ($result === false || $this->conn()->affectedRows() !== 1) {
      return false;
    }
    $this->unread = false;
    return true;
  }

  // -------------------------------------------------------------------------
  // DatabaseObject override
  // -------------------------------------------------------------------------

  /** @param array<string,mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $instance = $conn === null ? new static() : new static($conn);
    foreach (static::$_db_fields as $key) {
      if (array_key_exists($key, $row) && property_exists($instance, $key)) {
        $value = $row[$key];
        if (in_array($key, ['id', 'priority', 'email_id', 'sender_id', 'recipient_id'], true)) {
          $value = $value === null ? null : (int) $value;
        } elseif (in_array($key, ['sent', 'unread'], true)) {
          $value = (bool) $value;
        }
        $instance->$key = $value;
      }
    }
    return $instance;
  }
}
