<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\DeliveryMode;
use TimeFrontiers\Mailer\Driver\DriverConfigInterface;
use TimeFrontiers\Mailer\Driver\DriverFactory;
use TimeFrontiers\Mailer\Driver\DriverSnapshot;
use TimeFrontiers\Mailer\Driver\IdempotentMailDriverInterface;
use TimeFrontiers\Mailer\Driver\MailDriverInterface;
use TimeFrontiers\Mailer\Email;
use TimeFrontiers\Mailer\Exception\MailerException;
use TimeFrontiers\Mailer\Exception\UnknownDeliveryException;
use TimeFrontiers\Mailer\Exception\ValidationException;
use TimeFrontiers\Mailer\Log\EmailLog;
use TimeFrontiers\Mailer\Persistence\DeliveryAttemptStore;
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Rendering\Renderer;
use TimeFrontiers\Mailer\ReplacementValue;
use TimeFrontiers\Mailer\Result\RecipientDeliveryResult;
use TimeFrontiers\Mailer\Result\SendResult;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Validation\Validator;

/**
 * Durable, lease-based delivery queue with normalized recipient state.
 * @phpstan-consistent-constructor
 */
class Queue
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'email_queue';
  /** @var list<string> */
  protected static array $_db_fields = [
    'id', 'email_id', 'status', 'sender_id', 'sender_snapshot', 'subject', 'body',
    'body_text', 'headers', 'recipients', 'driver', 'driver_config', 'delivery_mode',
    'priority', 'worker_id', 'lease_expires_at', 'attempts', 'max_attempts',
    'next_attempt_at', 'last_error_code', 'reconciliation_required', '_created', '_updated',
  ];

  public ?int $id = null;
  public ?int $email_id = null;
  public string $status = 'building';
  public ?int $sender_id = null;
  public string $sender_snapshot = '{}';
  public string $subject = '';
  public string $body = '';
  public string $body_text = '';
  public string $headers = '{}';
  /** Legacy compatibility column; normalized rows are authoritative in v1.1. */
  public string $recipients = '[]';
  public string $driver = '';
  public string $driver_config = '{}';
  public string $delivery_mode = DeliveryMode::INDIVIDUAL->value;
  public int $priority = 5;
  public ?string $worker_id = null;
  public ?string $lease_expires_at = null;
  public int $attempts = 0;
  public int $max_attempts = 5;
  public ?string $next_attempt_at = null;
  public ?string $last_error_code = null;
  public bool $reconciliation_required = false;
  protected ?string $_created = null;
  protected ?string $_updated = null;

  private ?Profile $_sender = null;
  private ?DriverConfigInterface $_driver_config = null;
  private ?SendResult $_lastDispatchResult = null;

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

  public static function make(
    SQLDatabase $conn,
    Profile $sender,
    string $subject,
    string $body,
    string $message_type = 'default',
    int|string|Template|null $template = null,
    ?DriverConfigInterface $driver = null,
  ): self {
    self::assertSameConnection($conn, $sender->conn(), 'sender profile');
    if ($sender->id === null) {
      throw new ValidationException('Queue::make() requires a persisted sender profile.');
    }
    $subject = Validator::field('subject', $subject)->text(min: 2, max: 255)->value()
      ?: throw new ValidationException('Queue subject must be 2-255 characters.');
    $body = Validator::field('body', $body)->html(min: 1, max: 0)->value();
    if ($body === false || strlen($body) > Config::get()->maxRenderedBytes) {
      throw new ValidationException('Queue body is empty or exceeds the configured size limit.');
    }

    new Template($conn);
    $resolvedTemplate = null;
    if ($template instanceof Template) {
      if ($template->id === null) {
        throw new ValidationException('Queue template must be persisted.');
      }
      self::assertSameConnection($conn, $template->conn(), 'template');
      $resolvedTemplate = $template;
    } elseif ($template !== null) {
      $candidate = Template::findById($template);
      if (!$candidate instanceof Template) {
        throw new ValidationException('Queue template was not found.');
      }
      $resolvedTemplate = $candidate;
    } else {
      $entry = Config::get()->getTemplate($message_type);
      $templateCode = $entry['templateCode'] ?? null;
      if (is_string($templateCode) && $templateCode !== '') {
        $candidate = Template::findById($templateCode);
        $resolvedTemplate = $candidate instanceof Template ? $candidate : null;
      }
    }
    if ($resolvedTemplate !== null) {
      $body = str_replace(['%{body}', '%{message}'], $body, $resolvedTemplate->render());
    }
    if (strlen($body) > Config::get()->maxRenderedBytes) {
      throw new ValidationException('Rendered queue body exceeds the configured size limit.');
    }

    $driverConfig = $driver ?? Config::get()->driver;
    $snapshot = DriverSnapshot::capture($driverConfig);
    $instance = new self($conn);
    $instance->_sender = $sender;
    $instance->_driver_config = $driverConfig;
    $instance->sender_id = $sender->id;
    $instance->sender_snapshot = self::encodeJson(self::snapshotSender($sender));
    $instance->subject = $subject;
    $instance->body = $body;
    $instance->body_text = self::plainFromHtml($body);
    $instance->driver = $snapshot['driver'];
    $instance->driver_config = self::encodeJson($snapshot['config']);
    $instance->max_attempts = Config::get()->queueMaxAttempts;
    if (!$instance->save()) {
      throw new \RuntimeException('Queue item could not be persisted.');
    }
    $instance->id = (int) DatabaseGateway::insertId($conn);
    if ($instance->id < 1) {
      throw new \RuntimeException('Queue insert did not return an identifier.');
    }
    return $instance;
  }

  public static function fromEmail(SQLDatabase $conn, Email $email, Profile $sender, int $priority = 5): self
  {
    if ($email->id === null) {
      throw new ValidationException('Queue requires a persisted email.');
    }
    self::assertSameConnection($conn, $email->conn(), 'email');
    self::assertSameConnection($conn, $sender->conn(), 'sender profile');
    $instance = new self($conn);
    $instance->email_id = $email->id;
    $instance->sender_id = $sender->id;
    $instance->sender_snapshot = self::encodeJson(self::snapshotSender($sender));
    $instance->subject = $email->renderSubject();
    $instance->body = $email->render();
    $instance->body_text = $email->renderPlainText();
    $instance->headers = self::encodeJson(self::replyToHeaders($email));
    $instance->driver = (string) $email->driver;
    $instance->driver_config = (string) $email->driver_config;
    $instance->delivery_mode = $email->delivery_mode;
    $instance->priority = max(1, min(10, $priority));
    $instance->max_attempts = Config::get()->queueMaxAttempts;
    if (!$instance->save()) {
      throw new \RuntimeException('Email queue job could not be persisted.');
    }
    $instance->id = (int) DatabaseGateway::insertId($conn);
    foreach ($email->getRecipients() as $recipient) {
      if ($recipient->recipientType() === RecipientType::REPLY_TO) {
        continue;
      }
      $instance->insertRecipient($recipient, [], $recipient->id);
    }
    $instance->snapshotAttachments($email);
    return $instance;
  }

  /**
   * @param string|array{email?:string,name?:string,surname?:string} $contact
   * @param array<string,string|ReplacementValue> $replaceValues
   */
  public function addRecipient(string|array $contact, array $replaceValues = [], RecipientType $type = RecipientType::TO): self
  {
    if ($this->id === null || $this->status !== 'building') {
      throw new \LogicException('Recipients can only be added while a queue item is building.');
    }
    if ($type === RecipientType::REPLY_TO) {
      throw new ValidationException('Queue Reply-To values belong in message headers, not delivery recipients.');
    }
    $this->insertRecipient(Recipient::fromContact($contact, $type), $replaceValues, null);
    return $this;
  }

  public function enqueue(): bool
  {
    if ($this->id === null) {
      throw new \LogicException('Queue item must be persisted before enqueueing.');
    }
    $db = Config::get()->dbName;
    $accepted = $this->conn()->transaction(function (SQLDatabase $conn) use ($db): bool {
      $job = DatabaseGateway::fetchOne($conn,
        "SELECT `status` FROM `{$db}`.`email_queue` WHERE `id`=? FOR UPDATE",
        [$this->id],
      );
      if (!is_array($job)) {
        throw new \RuntimeException('Queue acceptance state could not be loaded.');
      }
      if ($job['status'] === 'pending') {
        return true;
      }
      if ($job['status'] !== 'building') {
        return false;
      }
      $rows = $this->recipientRows(['pending'], true);
      if ($rows === []) {
        throw new ValidationException('Queue item requires at least one valid recipient.');
      }
      foreach ($rows as $row) {
        $values = self::decodeReplacements((string) $row['replacements']);
        $subject = Renderer::replace($this->subject, $values, 'header', Config::get()->unresolvedTokenPolicy);
        $html = Renderer::replace($this->body, $values, 'html', Config::get()->unresolvedTokenPolicy);
        $plain = Renderer::replace($this->body_text, $values, 'plain', Config::get()->unresolvedTokenPolicy);
        $this->assertRenderedSizes($subject, $html, $plain);
      }
      $result = DatabaseGateway::execute($conn,
        "UPDATE `{$db}`.`email_queue` SET `status`='pending',`next_attempt_at`=CURRENT_TIMESTAMP WHERE `id`=? AND `status`='building'",
        [$this->id],
      );
      return $result !== false && DatabaseGateway::affectedRows($conn) === 1;
    });
    if (!$accepted) {
      return false;
    }
    $this->status = 'pending';
    return true;
  }

  public function dispatch(?Profile $sender = null, ?DriverConfigInterface $driver = null): int
  {
    if ($sender !== null || $driver !== null) {
      @trigger_error('Queue::dispatch() sender/driver overrides are deprecated and ignored; persisted snapshots are authoritative.', E_USER_DEPRECATED);
    }
    if ($this->status === 'building' && !$this->enqueue()) {
      return 0;
    }
    $workerId = self::workerId();
    if (!$this->claimById($workerId)) {
      return 0;
    }
    return $this->dispatchClaimed($workerId);
  }

  public static function processNext(SQLDatabase $conn, Profile $sender, int $limit = 10): int
  {
    self::assertSameConnection($conn, $sender->conn(), 'worker sender compatibility argument');
    $workerId = self::workerId();
    $total = 0;
    self::recoverExpiredLeases($conn);
    for ($processed = 0; $processed < max(1, $limit); $processed++) {
      $item = self::claimNext($conn, $workerId);
      if ($item === null) {
        break;
      }
      $total += $item->dispatchClaimed($workerId);
    }
    return $total;
  }

  public static function claimNext(SQLDatabase $conn, string $workerId): ?self
  {
    $db = Config::get()->dbName;
    for ($try = 0; $try < 20; $try++) {
      $rows = DatabaseGateway::fetchAll($conn,
        "SELECT `id` FROM `{$db}`.`email_queue` WHERE `status` IN ('pending','retry','partial') "
        . 'AND `reconciliation_required`=0 AND `attempts`<`max_attempts` '
        . 'AND (`next_attempt_at` IS NULL OR `next_attempt_at`<=CURRENT_TIMESTAMP) '
        . 'ORDER BY `priority` ASC,`id` ASC LIMIT 1',
      );
      if ($rows === false) {
        throw new \RuntimeException('Eligible queue job lookup failed.');
      }
      if ($rows === []) {
        return null;
      }
      $item = new self($conn);
      $item->id = (int) $rows[0]['id'];
      if ($item->claimById($workerId)) {
        return self::loadById($conn, $item->id);
      }
    }
    return null;
  }

  public static function recoverExpiredLeases(SQLDatabase $conn): int
  {
    $db = Config::get()->dbName;
    $jobs = DatabaseGateway::fetchAll($conn,
      "SELECT `id`,`attempts`,`max_attempts` FROM `{$db}`.`email_queue` WHERE `status`='processing' AND `lease_expires_at`<CURRENT_TIMESTAMP ORDER BY `id` ASC",
    );
    if ($jobs === false) {
      throw new \RuntimeException('Expired queue leases could not be inspected.');
    }
    $recovered = 0;
    foreach ($jobs as $job) {
      $jobId = (int) $job['id'];
      $recoveryWorker = self::workerId();
      $takeover = DatabaseGateway::execute($conn,
        "UPDATE `{$db}`.`email_queue` SET `worker_id`=? WHERE `id`=? AND `status`='processing' AND `lease_expires_at`<CURRENT_TIMESTAMP",
        [$recoveryWorker, $jobId],
      );
      if ($takeover === false) {
        throw new \RuntimeException('Expired queue lease could not be claimed for recovery.');
      }
      if ($conn->affectedRows() !== 1) {
        continue;
      }
      $prepared = DatabaseGateway::fetchAll($conn,
        "SELECT `a`.`id`,`a`.`queue_recipient_id`,`a`.`status` FROM `{$db}`.`email_delivery_attempts` `a` "
        . "INNER JOIN `{$db}`.`email_queue_recipients` `r` ON `r`.`id`=`a`.`queue_recipient_id` "
        . "WHERE `r`.`queue_id`=? AND `a`.`status` IN ('prepared','unknown','accepted','accepted_local_failure')",
        [$jobId],
      );
      if ($prepared === false) {
        throw new \RuntimeException('Expired delivery attempts could not be inspected.');
      }
      if ($prepared !== []) {
        foreach ($prepared as $attempt) {
          if ($attempt['status'] === 'prepared') {
            $attemptUpdate = DatabaseGateway::execute($conn,
              "UPDATE `{$db}`.`email_delivery_attempts` SET `status`='unknown',`error_code`='worker_lost_after_dispatch_start',`completed_at`=CURRENT_TIMESTAMP WHERE `id`=? AND `status`='prepared'",
              [(int) $attempt['id']],
            );
            if ($attemptUpdate === false) {
              throw new \RuntimeException('Expired prepared attempt could not be quarantined.');
            }
          }
          $recipientUpdate = DatabaseGateway::execute($conn,
            "UPDATE `{$db}`.`email_queue_recipients` SET `status`='unknown',`last_error_code`='worker_lost_after_dispatch_start',`worker_id`=NULL WHERE `id`=? AND `status`='processing'",
            [(int) $attempt['queue_recipient_id']],
          );
          if ($recipientUpdate === false) {
            throw new \RuntimeException('Expired processing recipient could not be quarantined.');
          }
        }
        $result = DatabaseGateway::execute($conn,
          "UPDATE `{$db}`.`email_queue` SET `status`='reconciliation',`reconciliation_required`=1,`last_error_code`='unknown_provider_outcome',`worker_id`=NULL,`lease_expires_at`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
          [$jobId, $recoveryWorker],
        );
      } elseif ((int) $job['attempts'] >= (int) $job['max_attempts']) {
        $recipientUpdate = DatabaseGateway::execute($conn,
          "UPDATE `{$db}`.`email_queue_recipients` SET `status`='dead_letter',`last_error_code`='retry_exhausted',`worker_id`=NULL WHERE `queue_id`=? AND `status` IN ('pending','retry','processing')",
          [$jobId],
        );
        if ($recipientUpdate === false) {
          throw new \RuntimeException('Expired recipients could not be dead-lettered.');
        }
        $result = DatabaseGateway::execute($conn,
          "UPDATE `{$db}`.`email_queue` SET `status`='dead_letter',`last_error_code`='retry_exhausted',`worker_id`=NULL,`lease_expires_at`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
          [$jobId, $recoveryWorker],
        );
      } else {
        $backoff = self::backoffSeconds((int) $job['attempts']);
        $recipientUpdate = DatabaseGateway::execute($conn,
          "UPDATE `{$db}`.`email_queue_recipients` SET `status`='retry',`next_attempt_at`=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND),`last_error_code`='worker_lease_expired',`worker_id`=NULL WHERE `queue_id`=? AND `status`='processing'",
          [$backoff, $jobId],
        );
        if ($recipientUpdate === false) {
          throw new \RuntimeException('Expired recipients could not be scheduled for retry.');
        }
        $result = DatabaseGateway::execute($conn,
          "UPDATE `{$db}`.`email_queue` SET `status`='retry',`next_attempt_at`=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND),`last_error_code`='worker_lease_expired',`worker_id`=NULL,`lease_expires_at`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
          [$backoff, $jobId, $recoveryWorker],
        );
      }
      if ($result === false || DatabaseGateway::affectedRows($conn) !== 1) {
        throw new \RuntimeException('Recovered queue state could not be persisted exactly once.');
      }
      $recovered++;
    }
    return $recovered;
  }

  public function lastDispatchResult(): ?SendResult
  {
    return $this->_lastDispatchResult;
  }

  private function claimById(string $workerId): bool
  {
    if ($this->id === null || preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $workerId) !== 1) {
      return false;
    }
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_queue` SET `status`='processing',`worker_id`=?,`lease_expires_at`=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND),`attempts`=`attempts`+1 "
      . "WHERE `id`=? AND `status` IN ('pending','retry','partial') AND `reconciliation_required`=0 AND `attempts`<`max_attempts` "
      . 'AND (`next_attempt_at` IS NULL OR `next_attempt_at`<=CURRENT_TIMESTAMP)',
      [$workerId, Config::get()->queueLeaseSeconds, $this->id],
    );
    return $result !== false && $this->conn()->affectedRows() === 1;
  }

  private function dispatchClaimed(string $workerId): int
  {
    if ($this->worker_id !== $workerId || $this->status !== 'processing') {
      $fresh = self::loadById($this->conn(), (int) $this->id);
      if ($fresh === null || $fresh->worker_id !== $workerId || $fresh->status !== 'processing') {
        throw new \LogicException('Queue item is not owned by this worker.');
      }
      $this->copyFrom($fresh);
    }
    $sender = $this->resolvedSender();
    $driverConfig = $this->resolvedDriverConfig();
    $driver = DriverFactory::fromConfig($driverConfig, Config::get()->driverFactory);
    $attachments = $this->email_id === null ? [] : $this->loadEmailAttachments();
    $headers = self::decodeJsonObject($this->headers);
    $store = new DeliveryAttemptStore($this->conn());
    $results = [];
    $localReconciliation = false;

    foreach ($this->recipientRows(['pending', 'retry']) as $row) {
      if (!$this->renewLease($workerId)) {
        throw new \LogicException('Queue lease is no longer owned by this worker.');
      }
      $recipientRowId = (int) $row['id'];
      $attemptNo = (int) $row['attempts'] + 1;
      if (!$this->claimRecipient($recipientRowId, $workerId)) {
        continue;
      }
      $recipient = Recipient::make(
        (string) $row['address'],
        (string) ($row['name'] ?? ''),
        (string) ($row['surname'] ?? ''),
        RecipientType::from((string) $row['type']),
      );
      $idempotencyKey = DeliveryAttemptStore::idempotencyKey("queue:{$this->id}:recipient:{$recipientRowId}");
      try {
        $attemptId = $store->prepare(
          $this->email_id,
          isset($row['source_recipient_id']) ? (int) $row['source_recipient_id'] : null,
          $recipientRowId,
          $attemptNo,
          $idempotencyKey,
          $driverConfig->driverName(),
          $workerId,
        );
      } catch (\Throwable) {
        $this->retryRecipient($recipientRowId, $attemptNo, 'attempt_persistence_failed', $workerId);
        $this->_userError('dispatch', 'Email delivery could not be started safely.');
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'failed', null, 'attempt_persistence_failed', $idempotencyKey);
        continue;
      }

      if (!$this->renewLease($workerId)) {
        $store->markFailed($attemptId, 'worker_lease_lost_before_dispatch');
        throw new \LogicException('Queue lease was lost before provider dispatch.');
      }

      $values = self::decodeReplacements((string) $row['replacements']);
      try {
        $subject = Renderer::replace($this->subject, $values, 'header', Config::get()->unresolvedTokenPolicy);
        $html = Renderer::replace($this->body, $values, 'html', Config::get()->unresolvedTokenPolicy);
        $plain = Renderer::replace($this->body_text, $values, 'plain', Config::get()->unresolvedTokenPolicy);
        $this->assertRenderedSizes($subject, $html, $plain);
        $messageId = self::dispatchDriver($driver, $idempotencyKey, $sender, $recipient, $subject, $html, $plain, $headers, $attachments);
        if ($messageId === '') {
          throw new UnknownDeliveryException('Provider returned no message identifier.');
        }
      } catch (UnknownDeliveryException) {
        $attemptRecorded = $store->markUnknown($attemptId, 'transport_outcome_unknown');
        $errorCode = $attemptRecorded ? 'transport_outcome_unknown' : 'unknown_outcome_persistence_failed';
        $this->markRecipientUnknown($recipientRowId, $errorCode, $workerId);
        $this->_userError('dispatch', 'Email delivery requires reconciliation before it can be retried.');
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', null, 'transport_outcome_unknown', $idempotencyKey);
        $localReconciliation = true;
        continue;
      } catch (\Throwable) {
        if (!$store->markFailed($attemptId, 'provider_rejected')) {
          $this->markRecipientUnknown($recipientRowId, 'failure_outcome_persistence_failed', $workerId);
          $localReconciliation = true;
          $this->_userError('dispatch', 'Email delivery state requires reconciliation before retry.');
          $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', null, 'failure_outcome_persistence_failed', $idempotencyKey);
          continue;
        }
        $this->retryRecipient($recipientRowId, $attemptNo, 'provider_rejected', $workerId);
        $this->_userError('dispatch', 'The email provider rejected a delivery.');
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'failed', null, 'provider_rejected', $idempotencyKey);
        continue;
      }

      if (!$store->markAccepted($attemptId, $messageId)) {
        $this->markRecipientUnknown($recipientRowId, 'acceptance_persistence_failed', $workerId);
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', $messageId, 'acceptance_persistence_failed', $idempotencyKey);
        $localReconciliation = true;
        continue;
      }
      if (!$this->markRecipientAccepted($recipientRowId, $messageId, $workerId)) {
        if (!$store->markLocalFailure($attemptId, 'recipient_acceptance_projection_failed')) {
          $this->_userError('dispatch', 'Accepted delivery reconciliation state could not be persisted.');
        }
        $this->_userError('dispatch', 'Email was accepted, but local delivery records require reconciliation.');
        $localReconciliation = true;
      }
      if ($this->email_id !== null && !empty($row['source_recipient_id'])) {
        try {
          $log = EmailLog::queue($this->conn(), $this->email_id, (int) $sender->id, (int) $row['source_recipient_id'], $this->priority);
          if (!$log->markSent($messageId)) {
            throw new \RuntimeException('log update failed');
          }
        } catch (\Throwable) {
          if (!$store->markLocalFailure($attemptId, 'accepted_log_failure')) {
            $this->_userError('dispatch', 'Accepted delivery reconciliation state could not be persisted.');
          }
          $this->_userError('dispatch', 'Email was accepted, but local logging requires reconciliation.');
          $localReconciliation = true;
        }
      }
      $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'accepted', $messageId, null, $idempotencyKey);
    }

    $this->finalizeJob($localReconciliation);
    $this->_lastDispatchResult = new SendResult($results, $localReconciliation);
    return $this->_lastDispatchResult->acceptedCount();
  }

  /** @param array<string,string|ReplacementValue> $replacements */
  private function insertRecipient(Recipient $recipient, array $replacements, ?int $sourceRecipientId): void
  {
    $db = Config::get()->dbName;
    $this->conn()->transaction(function (SQLDatabase $conn) use ($db, $recipient, $replacements, $sourceRecipientId): void {
      $job = DatabaseGateway::fetchOne($conn,
        "SELECT `id` FROM `{$db}`.`email_queue` WHERE `id`=? AND `status`='building' FOR UPDATE",
        [$this->id],
      );
      if (!is_array($job)) {
        throw new \LogicException('Queue recipients can only be changed while the job is building.');
      }
      $ordinalRow = DatabaseGateway::fetchOne($conn,
        "SELECT COALESCE(MAX(`ordinal`),0)+1 AS `next_ordinal` FROM `{$db}`.`email_queue_recipients` WHERE `queue_id`=?",
        [$this->id],
      );
      if (!is_array($ordinalRow)) {
        throw new \RuntimeException('Queue recipient order could not be allocated.');
      }
      $result = DatabaseGateway::execute($conn,
        "INSERT INTO `{$db}`.`email_queue_recipients` (`queue_id`,`source_recipient_id`,`ordinal`,`type`,`address`,`name`,`surname`,`replacements`,`status`,`next_attempt_at`) "
        . "VALUES (?,?,?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`surname`=VALUES(`surname`),`replacements`=VALUES(`replacements`)",
        [
          $this->id,
          $sourceRecipientId,
          (int) $ordinalRow['next_ordinal'],
          $recipient->type,
          $recipient->address,
          $recipient->name,
          $recipient->surname,
          self::encodeReplacements($replacements),
        ],
      );
      if ($result === false) {
        throw new \RuntimeException('Queue recipient could not be persisted.');
      }
    });
  }

  private function snapshotAttachments(Email $email): void
  {
    $db = Config::get()->dbName;
    $ordinal = 0;
    foreach ($email->attachments() as $attachment) {
      if ($attachment->fileId === null) {
        throw new ValidationException('Transient local-path attachments cannot be queued.');
      }
      $result = DatabaseGateway::execute($this->conn(),
        "INSERT INTO `{$db}`.`email_queue_attachments` (`queue_id`,`file_id`,`ordinal`) VALUES (?,?,?) "
        . 'ON DUPLICATE KEY UPDATE `ordinal`=VALUES(`ordinal`)',
        [$this->id, $attachment->fileId, ++$ordinal],
      );
      if ($result === false) {
        throw new \RuntimeException('Queue attachment snapshot could not be persisted.');
      }
    }
  }

  /**
   * @param list<string> $statuses
   * @return list<array<string,mixed>>
   */
  private function recipientRows(array $statuses, bool $forUpdate = false): array
  {
    $db = Config::get()->dbName;
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $rows = DatabaseGateway::fetchAll($this->conn(),
      "SELECT `id`,`source_recipient_id`,`ordinal`,`type`,`address`,`name`,`surname`,`replacements`,`status`,`attempts`,`provider_message_id`,`last_error_code`,`next_attempt_at` "
      . "FROM `{$db}`.`email_queue_recipients` WHERE `queue_id`=? AND `status` IN ({$marks}) "
      . 'AND (`next_attempt_at` IS NULL OR `next_attempt_at`<=CURRENT_TIMESTAMP) ORDER BY `ordinal` ASC,`id` ASC'
      . ($forUpdate ? ' FOR UPDATE' : ''),
      [$this->id, ...$statuses],
    );
    if ($rows === false) {
      throw new \RuntimeException('Queue recipients could not be loaded.');
    }
    return $rows;
  }

  private function claimRecipient(int $recipientId, string $workerId): bool
  {
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_queue_recipients` SET `status`='processing',`attempts`=`attempts`+1,`worker_id`=? "
      . "WHERE `id`=? AND `queue_id`=? AND `status` IN ('pending','retry') AND `attempts`<? AND (`next_attempt_at` IS NULL OR `next_attempt_at`<=CURRENT_TIMESTAMP) "
      . "AND EXISTS (SELECT 1 FROM `{$db}`.`email_queue` `q` WHERE `q`.`id`=? AND `q`.`status`='processing' AND `q`.`worker_id`=? AND `q`.`lease_expires_at`>=CURRENT_TIMESTAMP)",
      [$workerId, $recipientId, $this->id, $this->max_attempts, $this->id, $workerId],
    );
    return $result !== false && $this->conn()->affectedRows() === 1;
  }

  private function retryRecipient(int $recipientId, int $attemptNo, string $errorCode, string $workerId): void
  {
    $db = Config::get()->dbName;
    if ($attemptNo >= $this->max_attempts) {
      $status = 'dead_letter';
      $result = DatabaseGateway::execute($this->conn(),
        "UPDATE `{$db}`.`email_queue_recipients` SET `status`=?,`last_error_code`=?,`next_attempt_at`=NULL,`worker_id`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
        [$status, 'retry_exhausted:' . $errorCode, $recipientId, $workerId],
      );
    } else {
      $result = DatabaseGateway::execute($this->conn(),
        "UPDATE `{$db}`.`email_queue_recipients` SET `status`='retry',`last_error_code`=?,`next_attempt_at`=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND),`worker_id`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
        [$errorCode, self::backoffSeconds($attemptNo), $recipientId, $workerId],
      );
    }
    if ($result === false || $this->conn()->affectedRows() !== 1) {
      throw new \RuntimeException('Queue recipient retry state could not be persisted.');
    }
  }

  private function markRecipientUnknown(int $recipientId, string $errorCode, string $workerId): void
  {
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_queue_recipients` SET `status`='unknown',`last_error_code`=?,`worker_id`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
      [$errorCode, $recipientId, $workerId],
    );
    if ($result === false || $this->conn()->affectedRows() !== 1) {
      throw new \RuntimeException('Unknown recipient outcome could not be persisted.');
    }
  }

  private function markRecipientAccepted(int $recipientId, string $messageId, string $workerId): bool
  {
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_queue_recipients` SET `status`='accepted',`provider_message_id`=?,`accepted_at`=CURRENT_TIMESTAMP,`last_error_code`=NULL,`worker_id`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
      [$messageId, $recipientId, $workerId],
    );
    return $result !== false && $this->conn()->affectedRows() === 1;
  }

  /** @phpstan-impure Renews and verifies state in the shared queue table. */
  private function renewLease(string $workerId): bool
  {
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_queue` SET `lease_expires_at`=DATE_ADD(GREATEST(`lease_expires_at`,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND)),INTERVAL 1 SECOND) "
      . "WHERE `id`=? AND `status`='processing' AND `worker_id`=? AND `lease_expires_at`>=CURRENT_TIMESTAMP",
      [Config::get()->queueLeaseSeconds, $this->id, $workerId],
    );
    return $result !== false && $this->conn()->affectedRows() === 1;
  }

  private function finalizeJob(bool $localReconciliation): void
  {
    $db = Config::get()->dbName;
    $rows = DatabaseGateway::fetchAll($this->conn(),
      "SELECT `status`,COUNT(*) AS `total` FROM `{$db}`.`email_queue_recipients` WHERE `queue_id`=? GROUP BY `status`",
      [$this->id],
    );
    if ($rows === false) {
      throw new \RuntimeException('Queue completion state could not be calculated.');
    }
    $counts = [];
    foreach ($rows as $row) {
      $counts[(string) $row['status']] = (int) $row['total'];
    }
    $unknown = ($counts['unknown'] ?? 0) > 0;
    $retry = ($counts['retry'] ?? 0) + ($counts['pending'] ?? 0) > 0;
    $dead = ($counts['dead_letter'] ?? 0) > 0;
    $accepted = ($counts['accepted'] ?? 0) > 0;
    if ($localReconciliation || $unknown) {
      $status = 'reconciliation';
      $reconcile = 1;
      $error = $unknown ? 'unknown_provider_outcome' : 'accepted_local_failure';
    } elseif ($retry) {
      $status = $accepted ? 'partial' : 'retry';
      $reconcile = 0;
      $error = 'recipient_retry_pending';
    } elseif ($dead) {
      $status = $accepted ? 'partial_dead_letter' : 'dead_letter';
      $reconcile = 0;
      $error = 'recipient_retry_exhausted';
    } else {
      $status = 'sent';
      $reconcile = 0;
      $error = null;
    }
    $next = DatabaseGateway::fetchOne($this->conn(),
      "SELECT MIN(`next_attempt_at`) AS `next_attempt_at` FROM `{$db}`.`email_queue_recipients` WHERE `queue_id`=? AND `status`='retry'",
      [$this->id],
    );
    if ($next === false) {
      throw new \RuntimeException('Queue retry schedule could not be read.');
    }
    $result = DatabaseGateway::execute($this->conn(),
      "UPDATE `{$db}`.`email_queue` SET `status`=?,`reconciliation_required`=?,`last_error_code`=?,`next_attempt_at`=?,`worker_id`=NULL,`lease_expires_at`=NULL WHERE `id`=? AND `status`='processing' AND `worker_id`=?",
      [$status, $reconcile, $error, $next['next_attempt_at'] ?? null, $this->id, $this->worker_id],
    );
    if ($result === false || $this->conn()->affectedRows() !== 1) {
      throw new \RuntimeException('Queue completion state could not be persisted.');
    }
    $this->status = $status;
    $this->reconciliation_required = (bool) $reconcile;
  }

  /** @return list<Attachment> */
  private function loadEmailAttachments(): array
  {
    $db = Config::get()->dbName;
    $rows = DatabaseGateway::fetchAll($this->conn(),
      "SELECT `file_id` FROM `{$db}`.`email_queue_attachments` WHERE `queue_id`=? ORDER BY `ordinal` ASC",
      [$this->id],
    );
    if ($rows === false) {
      throw new \RuntimeException('Queued attachment snapshot could not be loaded.');
    }
    $attachments = [];
    foreach ($rows as $row) {
      $attachments[] = Attachment::fromFileId($this->conn(), (int) $row['file_id']);
    }
    return $attachments;
  }

  private function assertRenderedSizes(string $subject, string $html, string $plain): void
  {
    if ($subject === '' || strlen($subject) > 998) {
      throw new ValidationException('Rendered queue subject must be 1-998 bytes.');
    }
    $limit = Config::get()->maxRenderedBytes;
    if (strlen($html) > $limit || strlen($plain) > $limit) {
      throw new ValidationException('Rendered queue message exceeds the configured size limit.');
    }
  }

  private function resolvedSender(): Profile
  {
    if ($this->_sender !== null) {
      return $this->_sender;
    }
    $data = self::decodeJsonObject($this->sender_snapshot);
    if (!isset($data['address'])) {
      throw new MailerException('Queue sender snapshot is missing.');
    }
    $sender = new Profile($this->conn());
    $sender->id = $this->sender_id;
    $sender->address = (string) $data['address'];
    $sender->name = (string) ($data['name'] ?? '');
    $sender->surname = (string) ($data['surname'] ?? '');
    return $this->_sender = $sender;
  }

  private function resolvedDriverConfig(): DriverConfigInterface
  {
    return $this->_driver_config ??= DriverSnapshot::restore($this->driver, self::decodeJsonObject($this->driver_config));
  }

  private static function loadById(SQLDatabase $conn, int $id): ?self
  {
    $db = Config::get()->dbName;
    $columns = implode(',', array_map(static fn(string $field): string => '`' . $field . '`', static::$_db_fields));
    $rows = DatabaseGateway::fetchAll($conn, "SELECT {$columns} FROM `{$db}`.`email_queue` WHERE `id`=? LIMIT 1", [$id]);
    if ($rows === false) {
      throw new \RuntimeException('Queue job lookup failed.');
    }
    return $rows === [] ? null : self::_instantiateFromRow($rows[0], $conn);
  }

  private function copyFrom(self $other): void
  {
    foreach (static::$_db_fields as $field) {
      $this->$field = $other->$field;
    }
  }

  /**
   * @param array<string,mixed> $headers
   * @param list<Attachment> $attachments
   */
  private static function dispatchDriver(
    MailDriverInterface $driver,
    string $idempotencyKey,
    Profile $sender,
    Recipient $recipient,
    string $subject,
    string $html,
    string $plain,
    array $headers,
    array $attachments,
  ): string {
    if ($driver instanceof IdempotentMailDriverInterface) {
      return $driver->sendIdempotently($idempotencyKey, $sender, $recipient, $subject, $html, $plain, $headers, $attachments);
    }
    return $driver->send($sender, $recipient, $subject, $html, $plain, $headers, $attachments);
  }

  /** @return array<string,list<string>> */
  private static function replyToHeaders(Email $email): array
  {
    $recipients = $email->getRecipients(RecipientType::REPLY_TO);
    return $recipients === [] ? [] : ['Reply-To' => array_map(static fn(Recipient $recipient): string => $recipient->getAddress(), $recipients)];
  }

  private static function plainFromHtml(string $html): string
  {
    return trim((string) preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  }

  /** @param array<string,string|ReplacementValue> $values */
  private static function encodeReplacements(array $values): string
  {
    $encoded = [];
    foreach ($values as $key => $value) {
      $replacement = $value instanceof ReplacementValue ? $value : ReplacementValue::text((string) $value);
      $encoded[(string) $key] = $replacement->jsonSerialize();
    }
    return self::encodeJson($encoded);
  }

  /** @return array<string,ReplacementValue> */
  private static function decodeReplacements(string $json): array
  {
    $decoded = self::decodeJsonObject($json);
    $values = [];
    foreach ($decoded as $key => $value) {
      if (is_array($value)) {
        $values[(string) $key] = ReplacementValue::fromArray($value);
      } elseif (is_scalar($value) || $value === null) {
        $values[(string) $key] = ReplacementValue::text((string) $value);
      }
    }
    return $values;
  }

  /** @param array<string|int,mixed> $value */
  private static function encodeJson(array $value): string
  {
    try {
      return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (\JsonException $exception) {
      throw new ValidationException('Queue payload could not be encoded.', previous: $exception);
    }
  }

  /** @return array<string,mixed> */
  private static function decodeJsonObject(?string $json): array
  {
    if ($json === null || $json === '') {
      return [];
    }
    try {
      $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      return [];
    }
    return is_array($decoded) ? $decoded : [];
  }

  /** @return array{id:int,address:string,name:string,surname:string} */
  private static function snapshotSender(Profile $sender): array
  {
    return ['id' => (int) $sender->id, 'address' => (string) $sender->address, 'name' => (string) $sender->name, 'surname' => (string) $sender->surname];
  }

  private static function workerId(): string
  {
    return gethostname() . ':' . getmypid() . ':' . bin2hex(random_bytes(8));
  }

  private static function backoffSeconds(int $attempt): int
  {
    return min(86_400, Config::get()->queueBaseBackoffSeconds * (2 ** max(0, min(10, $attempt - 1))));
  }

  private static function assertSameConnection(SQLDatabase $left, SQLDatabase $right, string $context): void
  {
    if ($left->getInstance() !== $right->getInstance()) {
      throw new \LogicException("Cross-database {$context} use is not supported by php-mailer.");
    }
  }

  /** @param array<string,mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $instance = $conn === null ? new static() : new static($conn);
    foreach (static::$_db_fields as $key) {
      if (!array_key_exists($key, $row) || !property_exists($instance, $key)) {
        continue;
      }
      $value = $row[$key];
      if (in_array($key, ['id', 'email_id', 'sender_id'], true)) {
        $value = $value === null ? null : (int) $value;
      } elseif (in_array($key, ['priority', 'attempts', 'max_attempts'], true)) {
        $value = (int) $value;
      } elseif ($key === 'reconciliation_required') {
        $value = (bool) $value;
      } elseif (in_array($key, ['status', 'sender_snapshot', 'subject', 'body', 'body_text', 'headers', 'recipients', 'driver', 'driver_config', 'delivery_mode'], true)) {
        $value = (string) ($value ?? '');
      }
      $instance->$key = $value;
    }
    return $instance;
  }
}
