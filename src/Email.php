<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use League\CommonMark\CommonMarkConverter;
use TimeFrontiers\Data\Random;
use TimeFrontiers\File\File;
use TimeFrontiers\Mailer\Driver\DriverConfigInterface;
use TimeFrontiers\Mailer\Driver\DriverFactory;
use TimeFrontiers\Mailer\Driver\DriverSnapshot;
use TimeFrontiers\Mailer\Driver\IdempotentMailDriverInterface;
use TimeFrontiers\Mailer\Driver\MailDriverInterface;
use TimeFrontiers\Mailer\Email\Attachment;
use TimeFrontiers\Mailer\Email\Queue;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Email\Template;
use TimeFrontiers\Mailer\Exception\ConfigException;
use TimeFrontiers\Mailer\Exception\DriverException;
use TimeFrontiers\Mailer\Exception\MailerException;
use TimeFrontiers\Mailer\Exception\UnknownDeliveryException;
use TimeFrontiers\Mailer\Exception\ValidationException;
use TimeFrontiers\Mailer\Log\EmailLog;
use TimeFrontiers\Mailer\Persistence\DeliveryAttemptStore;
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;
use TimeFrontiers\Mailer\Rendering\Renderer;
use TimeFrontiers\Mailer\Result\RecipientDeliveryResult;
use TimeFrontiers\Mailer\Result\SendResult;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Validation\Validator;

/**
 * Core persisted email entity. Public entity code prefix 421 is immutable.
 * @phpstan-consistent-constructor
 */
class Email
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  public const CODE_PREFIX = '421';
  public const CODE_LENGTH = 15;
  public const CODE_PATTERN = '/^421\d{8,12}$/D';

  protected static string $_primary_key = 'id';
  protected static string $_db_name = '';
  protected static string $_table_name = 'emails';
  /** @var list<string> */
  protected static array $_db_fields = [
    'id', 'code', 'user', 'template_id', 'subject', 'body', 'is_md', 'folder',
    'sender_id', 'sender_snapshot', 'driver', 'driver_config', 'delivery_mode',
    'log_body', '_author', '_created', '_updated',
  ];

  public ?int $id = null;
  public ?string $code = null;
  public ?string $user = null;
  public ?int $template_id = null;
  public ?string $subject = null;
  public ?string $body = null;
  public bool $is_md = false;
  public string $folder = Folder::DRAFT->value;
  public ?int $sender_id = null;
  public ?string $sender_snapshot = null;
  public ?string $driver = null;
  public ?string $driver_config = null;
  public string $delivery_mode = DeliveryMode::INDIVIDUAL->value;
  public bool $log_body = true;

  protected ?string $_author = null;
  protected ?string $_created = null;
  protected ?string $_updated = null;

  /** @var list<Attachment> */
  private array $_attachments = [];
  private bool $_attachmentsHydrated = false;
  /** @var array<string,string|ReplacementValue> */
  private array $_replacements = [];
  private ?Template $_template = null;
  private ?Profile $_sender = null;
  private ?DriverConfigInterface $_driver = null;
  private ?string $_raw_body = null;
  private ?SendResult $_lastDeliveryResult = null;

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
    string $user = 'SYSTEM',
    ?string $message_type = 'default',
    int|string|Template|null $template = null,
    ?DriverConfigInterface $driver = null,
    bool $log_body = true,
  ): self {
    self::assertSameConnection($conn, $sender->conn(), 'sender profile');
    if ($sender->id === null) {
      throw new ValidationException('Email::make() requires a persisted sender profile.');
    }

    $config = Config::get();
    if (strlen($body) > $config->maxRenderedBytes) {
      throw new ValidationException('Email body exceeds the configured size limit.');
    }
    $instance = new self($conn);
    $instance->subject = Validator::field('subject', $subject)->text(min: 2, max: 255)->value()
      ?: throw new ValidationException('Email subject must be 2-255 characters.');
    $validBody = Validator::field('body', $body)->html(min: 1, max: 0)->value();
    if ($validBody === false) {
      throw new ValidationException('Email body must not be empty.');
    }
    $instance->user = Validator::field('user', $user)->pattern('/^(SYSTEM|087[0-9]{8,12})$/D')->value()
      ?: throw new ValidationException('Invalid user code format.');

    $driverConfig = $driver ?? $config->driver;
    $snapshot = DriverSnapshot::capture($driverConfig);
    $instance->_sender = $sender;
    $instance->_driver = $driverConfig;
    $instance->sender_id = $sender->id;
    $instance->sender_snapshot = self::encodeJson(self::snapshotSender($sender));
    $instance->driver = $snapshot['driver'];
    $instance->driver_config = self::encodeJson($snapshot['config']);
    $instance->_raw_body = $validBody;
    $instance->body = $log_body ? $validBody : '***redacted***';
    $instance->log_body = $log_body;
    $instance->folder = Folder::DRAFT->value;
    $instance->code = $instance->generateCode();

    new Template($conn);
    $configTemplate = null;
    if ($template instanceof Template) {
      if ($template->id === null) {
        throw new ValidationException('Email::make() received an unpersisted template.');
      }
      self::assertSameConnection($conn, $template->conn(), 'template');
      $instance->_template = $template;
      $instance->template_id = $template->id;
    } elseif ($template !== null) {
      $resolved = Template::findById($template);
      if (!$resolved instanceof Template) {
        throw new ValidationException('Email template was not found.');
      }
      $instance->_template = $resolved;
      $instance->template_id = $resolved->id;
    } else {
      $configTemplate = $config->getTemplate((string) $message_type);
      $templateCode = $configTemplate['templateCode'] ?? null;
      if (is_string($templateCode) && $templateCode !== '') {
        $resolved = Template::findById($templateCode);
        if ($resolved instanceof Template) {
          $instance->_template = $resolved;
          $instance->template_id = $resolved->id;
        }
      }
    }

    $configTemplate ??= $config->getTemplate((string) $message_type);
    foreach ($configTemplate['replaceVars'] ?? [] as $key) {
      $instance->_replacements[(string) $key] = '';
    }
    if (!$instance->save()) {
      throw new \RuntimeException('Email::make() failed to persist the email.');
    }
    $instance->id = (int) DatabaseGateway::insertId($conn);
    if ($instance->id < 1) {
      throw new \RuntimeException('Email::make() did not receive a persisted identifier.');
    }
    return $instance;
  }

  public static function load(SQLDatabase $conn, string $code): ?self
  {
    if (Validator::field('code', $code)->pattern(self::CODE_PATTERN)->value() === false) {
      return null;
    }
    $db = Config::get()->dbName;
    $columns = implode(',', array_map(static fn(string $field): string => '`' . $field . '`', static::$_db_fields));
    $rows = DatabaseGateway::fetchAll($conn, "SELECT {$columns} FROM `{$db}`.`emails` WHERE `code`=? LIMIT 1", [$code]);
    if ($rows === false) {
      throw new \RuntimeException('Email lookup failed.');
    }
    return $rows === [] ? null : self::_instantiateFromRow($rows[0], $conn);
  }

  public function setSubject(string $subject): self
  {
    $valid = Validator::field('subject', $subject)->text(min: 2, max: 255)->value();
    if ($valid === false) {
      throw new ValidationException('Email subject must be 2-255 characters.');
    }
    $this->subject = $valid;
    $this->saveOrFail('subject');
    return $this;
  }

  public function setBody(string $body, bool $isMd = false): self
  {
    if (strlen($body) > Config::get()->maxRenderedBytes) {
      throw new ValidationException('Email body exceeds the configured size limit.');
    }
    $valid = Validator::field('body', $body)->html(min: 1, max: 0)->value();
    if ($valid === false) {
      throw new ValidationException('Email body must not be empty.');
    }
    $this->_raw_body = $valid;
    $this->body = $this->log_body ? $valid : '***redacted***';
    $this->is_md = $isMd;
    $this->saveOrFail('body');
    return $this;
  }

  public function setTemplate(?Template $template): self
  {
    if ($template !== null) {
      if ($template->id === null) {
        throw new ValidationException('Email template must be persisted.');
      }
      self::assertSameConnection($this->conn(), $template->conn(), 'template');
    }
    $this->_template = $template;
    $this->template_id = $template?->id;
    $this->saveOrFail('template');
    return $this;
  }

  public function replace(string $pattern, string|ReplacementValue $value): self
  {
    $key = preg_match('/^%\{(.+)\}$/D', $pattern, $match) === 1 ? $match[1] : $pattern;
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $key) !== 1) {
      throw new ValidationException('Replacement key is invalid.');
    }
    $this->_replacements[$key] = $value;
    return $this;
  }

  /** @param string|array{email?:string,name?:string,surname?:string} $contact */
  public function addRecipient(
    string|array $contact,
    RecipientType $type = RecipientType::TO,
    ?int $mlistId = null,
  ): Recipient {
    if ($this->id === null) {
      throw new \RuntimeException('Email must be persisted before recipients are added.');
    }
    return Recipient::forEmail($this->conn(), $this->id, $contact, $type, $mlistId);
  }

  /** @return list<Recipient> */
  public function getRecipients(?RecipientType $type = null): array
  {
    if ($this->id === null) {
      return [];
    }
    $db = Config::get()->dbName;
    $sql = "SELECT `id`,`email_id`,`mlist_id`,`type`,`address`,`name`,`surname`,`_created` FROM `{$db}`.`email_recipients` WHERE `email_id`=?";
    $params = [$this->id];
    if ($type !== null) {
      $sql .= ' AND `type`=?';
      $params[] = $type->value;
    }
    $rows = DatabaseGateway::fetchAll($this->conn(), $sql . ' ORDER BY `id` ASC', $params);
    if ($rows === false) {
      throw new \RuntimeException('Email recipients could not be loaded.');
    }
    return array_values(array_map(fn(array $row): Recipient => Recipient::_instantiateFromRow($row, $this->conn()), $rows));
  }

  public function attach(File $file): self
  {
    self::assertSameConnection($this->conn(), $file->conn(), 'attachment');
    $attachment = Attachment::fromFile($file);
    foreach ($this->attachments() as $existing) {
      if ($existing->fileId === $attachment->fileId) {
        return $this;
      }
    }
    $this->assertAttachmentCanBeAdded($attachment);
    $this->persistAttachment($attachment);
    $this->_attachments[] = $attachment;
    $this->_attachmentsHydrated = true;
    return $this;
  }

  public function attachRaw(string $path, string $mimeType, string $name): self
  {
    $attachment = Attachment::fromPath($path, $mimeType, $name);
    $this->assertAttachmentCanBeAdded($attachment);
    $this->_attachments[] = $attachment;
    $this->_attachmentsHydrated = true;
    return $this;
  }

  /** @return list<Attachment> */
  public function attachments(): array
  {
    $this->hydrateAttachments();
    return $this->_attachments;
  }

  /** @param array<string,string|ReplacementValue> $replaceValues */
  public function renderSubject(array $replaceValues = []): string
  {
    $rendered = Renderer::replace(
      (string) $this->subject,
      $this->mergedReplacements($replaceValues),
      'header',
      Config::get()->unresolvedTokenPolicy,
    );
    if ($rendered === '' || strlen($rendered) > 998) {
      throw new ValidationException('Rendered email subject must be 1-998 bytes.');
    }
    return $rendered;
  }

  /** @param array<string,string|ReplacementValue> $replaceValues */
  public function render(array $replaceValues = []): string
  {
    $values = $this->mergedReplacements($replaceValues);
    $html = $this->baseHtml();
    $rendered = Renderer::replace($html, $values, 'html', Config::get()->unresolvedTokenPolicy);
    if (strlen($rendered) > Config::get()->maxRenderedBytes) {
      throw new ValidationException('Rendered email exceeds the configured size limit.');
    }
    return $rendered;
  }

  /** @param array<string,string|ReplacementValue> $replaceValues */
  public function renderPlainText(array $replaceValues = []): string
  {
    $values = $this->mergedReplacements($replaceValues);
    $html = $this->render($replaceValues);
    if ($html === '') {
      return Renderer::replace(Config::get()->plainTextTemplate, $values, 'plain', Config::get()->unresolvedTokenPolicy);
    }
    $plain = strip_tags($html);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\n{3,}/', "\n\n", $plain));
  }

  /** @param array<string,string|ReplacementValue> $replace_values */
  public function send(array $replace_values = []): bool
  {
    if (!$this->guardForDelivery('send', false, $replace_values)) {
      return false;
    }
    try {
      $sender = $this->resolvedSender();
      $driverConfig = $this->resolvedDriverConfig();
      $driver = DriverFactory::fromConfig($driverConfig, Config::get()->driverFactory);
    } catch (ConfigException $exception) {
      throw $exception;
    } catch (MailerException $exception) {
      $this->_userError('send', $exception->getMessage());
      return false;
    }
    $subject = $this->renderSubject($replace_values);
    $html = $this->render($replace_values);
    $plain = $this->renderPlainText($replace_values);
    $headers = $this->replyToHeaders();
    $attachments = $this->attachments();
    $store = new DeliveryAttemptStore($this->conn());
    $results = [];
    $localReconciliation = false;

    foreach ($this->deliveryRecipients() as $recipient) {
      $idempotencyKey = DeliveryAttemptStore::idempotencyKey("email:{$this->id}:recipient:{$recipient->id}");
      $latest = $store->latestImmediateOutcome((int) $this->id, (int) $recipient->id);
      if ($latest !== null && in_array($latest['status'], ['accepted', 'accepted_local_failure'], true)) {
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'accepted', $latest['provider_message_id'], null, $idempotencyKey);
        continue;
      }
      if ($latest !== null && in_array($latest['status'], ['prepared', 'unknown'], true)) {
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', null, 'delivery_reconciliation_required', $idempotencyKey);
        $localReconciliation = true;
        continue;
      }

      try {
        $attemptNo = $store->nextImmediateAttempt((int) $this->id, (int) $recipient->id);
        $attemptId = $store->prepare((int) $this->id, (int) $recipient->id, null, $attemptNo, $idempotencyKey, $driverConfig->driverName());
      } catch (\Throwable) {
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'failed', null, 'attempt_persistence_failed', $idempotencyKey);
        $this->_userError('send', 'Email delivery could not be started safely.');
        continue;
      }

      try {
        $messageId = $this->dispatchDriver($driver, $idempotencyKey, $sender, $recipient, $subject, $html, $plain, $headers, $attachments);
        if ($messageId === '') {
          throw new UnknownDeliveryException('Provider returned no message identifier.');
        }
      } catch (UnknownDeliveryException) {
        $attemptRecorded = $store->markUnknown($attemptId, 'transport_outcome_unknown');
        $errorCode = $attemptRecorded ? 'transport_outcome_unknown' : 'unknown_outcome_persistence_failed';
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', null, $errorCode, $idempotencyKey);
        $this->_userError('send', 'Email delivery requires reconciliation before it can be retried.');
        $localReconciliation = true;
        continue;
      } catch (\Throwable) {
        if (!$store->markFailed($attemptId, 'provider_rejected')) {
          $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', null, 'failure_outcome_persistence_failed', $idempotencyKey);
          $this->_userError('send', 'Email delivery state requires reconciliation before retry.');
          $localReconciliation = true;
          continue;
        }
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'failed', null, 'provider_rejected', $idempotencyKey);
        $this->_userError('send', 'The email provider rejected a delivery.');
        continue;
      }

      if (!$store->markAccepted($attemptId, $messageId)) {
        $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'unknown', $messageId, 'acceptance_persistence_failed', $idempotencyKey);
        $this->_userError('send', 'Provider acceptance requires local reconciliation.');
        $localReconciliation = true;
        continue;
      }

      try {
        $log = EmailLog::queue($this->conn(), (int) $this->id, (int) $sender->id, (int) $recipient->id);
        if (!$log->markSent($messageId)) {
          throw new \RuntimeException('log update failed');
        }
      } catch (\Throwable) {
        if (!$store->markLocalFailure($attemptId, 'accepted_log_failure')) {
          $this->_userError('send', 'Accepted delivery reconciliation state could not be persisted.');
        }
        $localReconciliation = true;
        $this->_userError('send', 'Email was accepted, but local delivery records require reconciliation.');
      }
      $results[] = new RecipientDeliveryResult((string) $recipient->address, $recipient->type, 'accepted', $messageId, null, $idempotencyKey);
    }

    $result = new SendResult($results, $localReconciliation);
    if ($result->allAccepted()) {
      $this->folder = Folder::SENT->value;
      if (!$this->save()) {
        $localReconciliation = true;
        $this->_userError('send', 'Email was accepted, but its local folder requires reconciliation.');
        $result = new SendResult($results, true);
      }
    }
    $this->_lastDeliveryResult = $result;
    return $result->allAccepted();
  }

  public function queue(SQLDatabase $conn, Profile $sender, int $priority = 5): bool
  {
    self::assertSameConnection($this->conn(), $conn, 'queue');
    self::assertSameConnection($conn, $sender->conn(), 'sender profile');
    try {
      $this->withSender($sender, $this->resolvedDriverConfig());
    } catch (ConfigException $exception) {
      throw $exception;
    } catch (MailerException $exception) {
      $this->_userError('queue', $exception->getMessage());
      return false;
    }
    if (!$this->guardForDelivery('queue', true)) {
      return false;
    }
    try {
      return $conn->transaction(function () use ($conn, $sender, $priority): bool {
        $queue = Queue::fromEmail($conn, $this, $sender, $priority);
        if (!$queue->enqueue()) {
          throw new \RuntimeException('Queue acceptance state could not be persisted.');
        }
        $this->folder = Folder::OUTBOX->value;
        if (!$this->save()) {
          throw new \RuntimeException('Queued email folder state could not be persisted.');
        }
        return true;
      });
    } catch (\Throwable) {
      $this->_userError('queue', 'Email could not be queued safely.');
      return false;
    }
  }

  public function withSender(Profile $sender, ?DriverConfigInterface $driver = null): self
  {
    self::assertSameConnection($this->conn(), $sender->conn(), 'sender profile');
    $driverConfig = $driver ?? Config::get()->driver;
    $snapshot = DriverSnapshot::capture($driverConfig);
    $this->_sender = $sender;
    $this->_driver = $driverConfig;
    $this->sender_id = $sender->id;
    $this->sender_snapshot = self::encodeJson(self::snapshotSender($sender));
    $this->driver = $snapshot['driver'];
    $this->driver_config = self::encodeJson($snapshot['config']);
    $this->saveOrFail('sender');
    return $this;
  }

  public function lastDeliveryResult(): ?SendResult
  {
    return $this->_lastDeliveryResult;
  }

  public function moveTo(Folder $folder): bool
  {
    if ($this->folder === Folder::SENT->value && $folder !== Folder::SENT) {
      throw new \LogicException('A sent email cannot be moved back to a mutable folder.');
    }
    $this->folder = $folder->value;
    return $this->save();
  }

  /** @return list<Recipient> */
  private function deliveryRecipients(): array
  {
    return array_values(array_filter(
      $this->getRecipients(),
      static fn(Recipient $recipient): bool => $recipient->recipientType() !== RecipientType::REPLY_TO,
    ));
  }

  /** @return array<string,list<string>> */
  private function replyToHeaders(): array
  {
    $replyTo = $this->getRecipients(RecipientType::REPLY_TO);
    return $replyTo === [] ? [] : ['Reply-To' => array_map(static fn(Recipient $r): string => $r->getAddress(), $replyTo)];
  }

  /**
   * @param array<string,string|ReplacementValue> $replaceValues
   * @return array<string,string|ReplacementValue>
   */
  private function mergedReplacements(array $replaceValues): array
  {
    return [
      ...$this->_replacements,
      'server' => ReplacementValue::url(rtrim(Config::get()->mailServer, '/')),
      'code' => (string) $this->code,
      ...$replaceValues,
    ];
  }

  private function baseHtml(): string
  {
    $rawBody = $this->_raw_body ?? $this->body;
    if ($rawBody === null || $rawBody === '' || $rawBody === '***redacted***') {
      return '';
    }
    $html = html_entity_decode($rawBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($this->is_md) {
      $converter = new CommonMarkConverter(['html_input' => Config::get()->trustedTemplateHtml ? 'allow' : 'strip']);
      $html = (string) $converter->convert($html);
    }
    if ($this->_template === null && $this->template_id !== null) {
      new Template($this->conn());
      $loaded = Template::findById($this->template_id);
      $this->_template = $loaded instanceof Template ? $loaded : null;
    }
    if ($this->_template !== null) {
      $html = str_replace(['%{body}', '%{message}'], $html, $this->_template->render());
    }
    return $html;
  }

  /**
   * Run delivery validation without breaking the documented bool contract.
   *
   * send() and queue() are documented as returning bool. A validation
   * rejection must therefore surface as false plus a HasErrors entry, never as
   * an exception escaping to a caller written as `if (!$email->send())`. The
   * typed reason stays available through the delivery-result accessors.
   *
   * @param array<string,string|ReplacementValue> $replaceValues
   */
  private function guardForDelivery(string $context, bool $queued, array $replaceValues = []): bool
  {
    try {
      $this->validateForDelivery($queued, $replaceValues);
      return true;
    } catch (ConfigException $exception) {
      // A missing/invalid bootstrap is a deployment fault, not a delivery
      // rejection. It must stay loud rather than look like a failed send.
      throw $exception;
    } catch (MailerException $exception) {
      // Package-authored validation messages are safe for the user rank;
      // provider/transport detail never reaches this path.
      $this->_userError($context, $exception->getMessage());
      return false;
    }
  }

  /** @param array<string,string|ReplacementValue> $replaceValues */
  private function validateForDelivery(bool $queued, array $replaceValues = []): void
  {
    if ($this->id === null || $this->subject === null || $this->subject === '') {
      throw new MailerException('Email must be persisted with a subject before delivery.');
    }
    if ($this->delivery_mode !== DeliveryMode::INDIVIDUAL->value) {
      throw new MailerException('Unknown email delivery mode.');
    }
    if ($queued && (!$this->log_body || $this->body === '***redacted***')) {
      throw new ValidationException('Redacted-body emails cannot be queued without an encrypted payload store.');
    }
    if ($this->deliveryRecipients() === []) {
      throw new ValidationException('Email requires at least one TO, CC, or BCC recipient.');
    }
    $this->validateAttachments($queued);
    $this->renderSubject($replaceValues);
    $this->render($replaceValues);
    $this->renderPlainText($replaceValues);
  }

  private function resolvedSender(): Profile
  {
    if ($this->_sender !== null) {
      return $this->_sender;
    }
    $data = self::decodeJsonObject($this->sender_snapshot);
    if ($data === [] || !isset($data['address'])) {
      throw new MailerException('Persisted email has no sender snapshot.');
    }
    $profile = new Profile($this->conn());
    $profile->id = $this->sender_id;
    $profile->address = (string) $data['address'];
    $profile->name = (string) ($data['name'] ?? '');
    $profile->surname = (string) ($data['surname'] ?? '');
    return $this->_sender = $profile;
  }

  private function resolvedDriverConfig(): DriverConfigInterface
  {
    if ($this->_driver !== null) {
      return $this->_driver;
    }
    if ($this->driver === null) {
      throw new MailerException('Persisted email has no driver snapshot.');
    }
    return $this->_driver = DriverSnapshot::restore($this->driver, self::decodeJsonObject($this->driver_config));
  }

  /**
   * @param array<string,list<string>> $headers
   * @param list<Attachment> $attachments
   */
  private function dispatchDriver(
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

  private function hydrateAttachments(): void
  {
    if ($this->_attachmentsHydrated || $this->id === null) {
      return;
    }
    $db = Config::get()->dbName;
    $rows = DatabaseGateway::fetchAll($this->conn(),
      "SELECT `file_id` FROM `{$db}`.`email_attachments` WHERE `email_id`=? ORDER BY `id` ASC",
      [$this->id],
    );
    if ($rows === false) {
      throw new \RuntimeException('Email attachments could not be loaded.');
    }
    foreach ($rows as $row) {
      $this->_attachments[] = Attachment::fromFileId($this->conn(), (int) $row['file_id']);
    }
    $this->_attachmentsHydrated = true;
  }

  private function persistAttachment(Attachment $attachment): void
  {
    if ($this->id === null || $attachment->fileId === null) {
      throw new \LogicException('Only persisted emails and php-file attachments can be linked.');
    }
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn(),
      "INSERT INTO `{$db}`.`email_attachments` (`email_id`,`file_id`) VALUES (?,?) ON DUPLICATE KEY UPDATE `file_id`=VALUES(`file_id`)",
      [$this->id, $attachment->fileId],
    );
    if ($result === false) {
      throw new \RuntimeException('Email attachment reference could not be persisted.');
    }
  }

  private function assertAttachmentCanBeAdded(Attachment $attachment): void
  {
    $current = $this->attachments();
    if (count($current) >= Config::get()->maxAttachments) {
      throw new ValidationException('Email exceeds the configured attachment count limit.');
    }
    $this->validateAttachment($attachment);
    $total = $attachment->size + array_sum(array_map(static fn(Attachment $item): int => $item->size, $current));
    if ($total > Config::get()->maxTotalAttachmentBytes) {
      throw new ValidationException('Email exceeds the configured total attachment size limit.');
    }
  }

  private function validateAttachments(bool $queued): void
  {
    $attachments = $this->attachments();
    if (count($attachments) > Config::get()->maxAttachments) {
      throw new ValidationException('Email exceeds the configured attachment count limit.');
    }
    $total = 0;
    foreach ($attachments as $attachment) {
      if ($queued && !$attachment->isPersisted()) {
        throw new ValidationException('Transient local-path attachments cannot be queued.');
      }
      $this->validateAttachment($attachment);
      $total += $attachment->size;
    }
    if ($total > Config::get()->maxTotalAttachmentBytes) {
      throw new ValidationException('Email exceeds the configured total attachment size limit.');
    }
  }

  private function validateAttachment(Attachment $attachment): void
  {
    $config = Config::get();
    if ($attachment->size > $config->maxAttachmentBytes) {
      throw new ValidationException('Attachment exceeds the configured individual size limit.');
    }
    if ($config->allowedAttachmentMimeTypes !== [] && !in_array(strtolower($attachment->mimeType), array_map('strtolower', $config->allowedAttachmentMimeTypes), true)) {
      throw new ValidationException('Attachment MIME type is not allowed.');
    }
  }

  private function saveOrFail(string $operation): void
  {
    if (!$this->save()) {
      throw new \RuntimeException("Email {$operation} update could not be persisted.");
    }
  }

  private function generateCode(): string
  {
    do {
      $code = self::CODE_PREFIX . Random::numeric(self::CODE_LENGTH - strlen(self::CODE_PREFIX));
    } while (self::valueExists('code', $code));
    return $code;
  }

  /** @return array{id:int,address:string,name:string,surname:string} */
  private static function snapshotSender(Profile $sender): array
  {
    return [
      'id' => (int) $sender->id,
      'address' => (string) $sender->address,
      'name' => (string) $sender->name,
      'surname' => (string) $sender->surname,
    ];
  }

  /** @param array<string|int,mixed> $value */
  private static function encodeJson(array $value): string
  {
    try {
      return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (\JsonException $exception) {
      throw new ValidationException('Mailer snapshot could not be encoded.', previous: $exception);
    }
  }

  /** @return array<string,mixed> */
  private static function decodeJsonObject(?string $value): array
  {
    if ($value === null || $value === '') {
      return [];
    }
    try {
      $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
      return [];
    }
    return is_array($decoded) ? $decoded : [];
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
      if (in_array($key, ['id', 'template_id', 'sender_id'], true)) {
        $value = $value === null ? null : (int) $value;
      } elseif (in_array($key, ['is_md', 'log_body'], true)) {
        $value = (bool) $value;
      }
      $instance->$key = $value;
    }
    return $instance;
  }
}
