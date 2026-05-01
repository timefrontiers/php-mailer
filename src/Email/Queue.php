<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\Driver\DriverConfigInterface;
use TimeFrontiers\Mailer\Driver\DriverFactory;
use TimeFrontiers\Mailer\Exception\MailerException;
use TimeFrontiers\Mailer\Exception\ValidationException;

/**
 * Bulk-email queue entity stored in `email_queue`.
 *
 * Designed for personalized bulk sends (newsletters, campaigns, batch notifications).
 * The template shell is applied at make() time — the stored body already has %{body}
 * replaced. Per-recipient %{token} replacements are applied at dispatch time, so the
 * cron runner needs no knowledge of templates.
 *
 * Workflow:
 *   $queue = Queue::make($conn, $sender, $subject, $body, 'newsletter');
 *   $queue->addRecipient('john@doe.com', ['user-name' => 'John', 'user-surname' => 'Doe']);
 *   $queue->addRecipient(['name' => 'Jane', 'email' => 'jane@doe.com'], ['user-name' => 'Jane']);
 *   $queue->dispatch();   // send immediately, or leave for cron
 *
 * Table:   email_queue
 * PK:      id (BIGINT UNSIGNED AUTO_INCREMENT)
 */
class Queue
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination,
      \TimeFrontiers\Helper\HasErrors;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'email_queue';
  protected static array  $_db_fields   = [
    'id', 'status', 'sender_id', 'subject', 'body',
    'recipients', 'driver', '_created', '_updated',
  ];

  public ?int    $id         = null;
  public string  $status     = 'pending';
  public ?int    $sender_id  = null;
  public string  $subject    = '';
  public string  $body       = '';

  /** JSON-encoded array of {contact, replaceValues} objects. */
  public string  $recipients = '[]';

  /** Driver key override (null = use Config::get()->driver at dispatch time). */
  public ?string $driver     = null;

  protected ?string $_created = null;
  protected ?string $_updated = null;

  // Runtime-only (not persisted)
  private ?Profile              $_sender        = null;
  private ?DriverConfigInterface $_driver_config = null;

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
   * Create a new queue item.
   *
   * The body is template-rendered at creation time (if a template is resolved),
   * so cron runners only need to apply per-recipient bare-key replacements.
   *
   * @param Profile                  $sender        The From profile (FK-enforced, required).
   * @param string                   $subject       Subject line — may contain %{tokens}.
   * @param string                   $body          Email body — may contain %{tokens}.
   * @param string                   $message_type  Config::templates key (default = 'default').
   * @param int|string|Template|null $template      Explicit template override. null = use config lookup.
   * @param DriverConfigInterface|null $driver      Transport override. null = use Config::get()->driver.
   * @throws ValidationException|\RuntimeException
   */
  public static function make(
    SQLDatabase              $conn,
    Profile                  $sender,
    string                   $subject,
    string                   $body,
    string                   $message_type = 'default',
    int|string|Template|null $template     = null,
    ?DriverConfigInterface   $driver       = null,
  ): self {
    if (empty($sender->id)) {
      throw new ValidationException("Queue::make() — sender Profile must be persisted (id required).");
    }

    $instance                = new self($conn);
    $instance->_sender       = $sender;
    $instance->sender_id     = (int) $sender->id;
    $instance->subject       = $subject;
    $instance->_driver_config = $driver ?? Config::get()->driver;

    // Resolve template
    $resolvedTpl = null;
    if ($template !== null) {
      if ($template instanceof Template) {
        if (empty($template->id)) {
          throw new ValidationException("Queue::make() — invalid Template instance (no id).");
        }
        $resolvedTpl = $template;
      } else {
        // int or string (code) — DatabaseObject::findById handles both
        $resolvedTpl = Template::findById($template);
        if (!$resolvedTpl) {
          throw new ValidationException("Queue::make() — template not found: {$template}");
        }
      }
    } else {
      // Soft lookup from Config by message_type — no exception on miss
      try {
        $configTpl = Config::get()->getTemplate($message_type);
        if ($configTpl && !empty($configTpl['templateCode'])) {
          $resolvedTpl = Template::findById($configTpl['templateCode']);
        }
      } catch (\Throwable) {}
    }

    // Pre-render: inject body into template shell now, leave %{tokens} for dispatch
    if ($resolvedTpl !== null) {
      $shell = $resolvedTpl->render();
      $body  = str_replace(['%{body}', '%{message}'], $body, $shell);
    }

    $instance->body       = $body;
    $instance->recipients = '[]';

    if (!$instance->save()) {
      throw new \RuntimeException("Queue::make() — failed to persist queue item.");
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
  }

  // -------------------------------------------------------------------------
  // Building the recipient list
  // -------------------------------------------------------------------------

  /**
   * Add a recipient to this queue item.
   *
   * Recipient data is stored as JSON in `recipients` — no row is written to
   * `email_recipients`. This keeps the queue lightweight for bulk sends.
   *
   * @param string|array{email: string, name?: string, surname?: string} $contact
   *   A bare email address, an RFC 5322 "Name <email>" string, or an assoc array.
   * @param array  $replaceValues  Bare-key token map applied only for this recipient.
   */
  public function addRecipient(string|array $contact, array $replaceValues = []): self
  {
    $list   = json_decode($this->recipients, true) ?? [];
    $list[] = ['contact' => $contact, 'replaceValues' => $replaceValues];

    $this->recipients = (string) json_encode($list, JSON_UNESCAPED_UNICODE);
    $this->save();
    return $this;
  }

  // -------------------------------------------------------------------------
  // Dispatch
  // -------------------------------------------------------------------------

  /**
   * Dispatch all pending recipients in this queue item immediately.
   *
   * @param Profile|null              $sender  Override sender (falls back to stored _sender).
   * @param DriverConfigInterface|null $driver  Override driver config.
   * @return int  Number of recipients successfully dispatched.
   */
  public function dispatch(?Profile $sender = null, ?DriverConfigInterface $driver = null): int
  {
    $sender = $sender ?? $this->_sender
      ?? throw new MailerException("Queue::dispatch() — no sender. Pass a Profile or load via processNext().");

    $driverCfg = $driver ?? $this->_driver_config ?? Config::get()->driver;
    $drv       = DriverFactory::fromConfig($driverCfg);

    $list = json_decode($this->recipients, true) ?? [];
    $sent = 0;
    $fail = 0;

    $this->status = 'processing';
    $this->save();

    foreach ($list as $item) {
      try {
        $contact       = $item['contact'];
        $replaceValues = $item['replaceValues'] ?? [];

        // Build a transient Recipient (not persisted to email_recipients)
        if (is_array($contact)) {
          $recipient = Recipient::make(
            address: $contact['email']   ?? '',
            name:    $contact['name']    ?? '',
            surname: $contact['surname'] ?? '',
          );
        } else {
          [$email, $name, $surname] = $this->_parseAddress((string) $contact);
          $recipient = Recipient::make(address: $email, name: $name, surname: $surname);
        }

        // Apply per-recipient token replacements
        $renderedBody    = $this->_applyTokens($this->body,    $replaceValues);
        $renderedSubject = $this->_applyTokens($this->subject, $replaceValues);
        $bodyText        = trim(preg_replace('/\n{3,}/', "\n\n", strip_tags(
          html_entity_decode($renderedBody, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        )) ?? '');

        $drv->send(
          sender:      $sender,
          recipient:   $recipient,
          subject:     $renderedSubject,
          bodyHtml:    $renderedBody,
          bodyText:    $bodyText,
          headers:     [],
          attachments: [],
        );

        $sent++;
      } catch (\Throwable $e) {
        $this->_addError('dispatch', $e->getMessage(), 7);
        $fail++;
      }
    }

    $this->status = ($fail === 0) ? 'sent' : ($sent === 0 ? 'failed' : 'sent');
    $this->save();

    return $sent;
  }

  /**
   * Cron entry point — process the next N pending queue items.
   *
   * @return int  Total recipients dispatched across all processed items.
   */
  public static function processNext(SQLDatabase $conn, Profile $sender, int $limit = 10): int
  {
    $instance = new self($conn);
    /** @var self[] $items */
    $items = self::findBySql(
      'SELECT * FROM :db:.:tbl: WHERE `status` = ? ORDER BY `id` ASC LIMIT ' . max(1, (int) $limit),
      ['pending'],
    ) ?: [];

    $total = 0;
    foreach ($items as $item) {
      $item->_sender = $sender;
      $total += $item->dispatch($sender);
    }

    return $total;
  }

  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

  /** Apply bare-key token replacements to a string. */
  private function _applyTokens(string $text, array $replacements): string
  {
    if (empty($replacements)) {
      return $text;
    }
    $search  = array_map(fn($k) => '%{' . $k . '}', array_keys($replacements));
    $replace = array_values($replacements);
    return str_replace($search, $replace, $text);
  }

  /**
   * Parse an RFC 5322 "First Last <email>" string into [email, name, surname].
   *
   * @return array{string, string, string}
   */
  private function _parseAddress(string $contact): array
  {
    if (preg_match('/^(.+?)\s*<([^>]+)>\s*$/', trim($contact), $m)) {
      $parts = explode(' ', trim($m[1]), 2);
      return [$m[2], $parts[0], $parts[1] ?? ''];
    }
    return [$contact, '', ''];
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
