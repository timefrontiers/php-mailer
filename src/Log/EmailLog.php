<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Log;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Data\Random;
use TimeFrontiers\Mailer\Config;

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
class EmailLog
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'email_log';
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
    $instance              = new self($conn);
    $instance->email_id    = $emailId;
    $instance->sender_id   = $senderId;
    $instance->recipient_id = $recipientId;
    $instance->priority    = max(1, min(10, $priority));
    $instance->sent        = false;
    $instance->unread      = true;

    if (!$instance->save()) {
      throw new \RuntimeException(
        "EmailLog::queue() — failed to persist log entry for email {$emailId}."
      );
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
  }

  /**
   * Load a single log entry by its primary-key id.
   */
  public static function loadById(SQLDatabase $conn, int $id): ?self
  {
    $instance = new self($conn);
    $found    = self::findBySql(
      'SELECT * FROM :db:.:tbl: WHERE `id` = ? LIMIT 1',
      [$id],
    );
    return $found ? $found[0] : null;
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
    $this->qref = $qref;
    $this->sent = true;
    return $this->save();
  }

  /**
   * Mark the message as read by the recipient.
   *
   * @return bool  False if the row could not be persisted.
   */
  public function markRead(): bool
  {
    $this->unread = false;
    return $this->save();
  }

  // -------------------------------------------------------------------------
  // DatabaseObject override
  // -------------------------------------------------------------------------

  public static function _instantiateFromRow(array $row): static
  {
    $instance = new static();
    foreach ($row as $key => $value) {
      if (!is_int($key) && property_exists($instance, $key)) {
        $instance->$key = $value;
      }
    }
    return $instance;
  }
}
