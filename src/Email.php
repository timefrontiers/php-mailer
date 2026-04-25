<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use League\CommonMark\CommonMarkConverter;
use TimeFrontiers\File\File;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Data\Random;
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Folder;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Email\Attachment;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Email\Template;
use TimeFrontiers\Mailer\Log\EmailLog;
use TimeFrontiers\Mailer\Driver\DriverConfigInterface;
use TimeFrontiers\Mailer\Driver\DriverFactory;
use TimeFrontiers\Mailer\Exception\MailerException;
use TimeFrontiers\Mailer\Exception\ValidationException;

/**
 * Core email entity stored in `emails`.
 *
 * An email begins life as a DRAFT, moves to OUTBOX when queued for delivery,
 * and lands in SENT once all recipients have been dispatched. The body can be
 * authored as plain HTML or Markdown (is_md = true). An optional template_id
 * wraps the body inside a stored template shell via the %{body} token.
 *
 * Table:   emails
 * PK:      id (BIGINT UNSIGNED AUTO_INCREMENT)
 * Unique:  code CHAR(15) — prefix 421, 12 random digits
 *
 * Attachments:
 *   attach($file)               — persisted, backed by php-file
 *   attachRaw($path, …)         — transient, not stored in email_attachments
 *
 * Delivery:
 *   send($conn, $sender, $driverConfig)  — immediate delivery
 *   queue($conn, $sender, $priority)     — deferred (OUTBOX)
 */
class Email
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination,
      \TimeFrontiers\Helper\HasErrors;

  public const CODE_PREFIX  = '421';
  public const CODE_LENGTH  = 15;
  public const CODE_PATTERN = '/^421\d{12}$/';

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'emails';
  protected static array  $_db_fields   = [
    'id', 'code', 'user', 'template_id', 'subject',
    'body', 'is_md', 'folder', 'sender_id',
    '_author', '_created', '_updated',
  ];

  public ?int    $id          = null;
  public ?string $code        = null;
  public ?string $user        = null;
  public ?int    $template_id = null;
  public ?string $subject     = null;
  public ?string $body        = null;
  public bool    $is_md       = false;
  public string  $folder      = Folder::DRAFT->value;
  public ?int    $sender_id   = null;

  protected ?string $_author  = null;
  protected ?string $_created = null;
  protected ?string $_updated = null;

  /** @var Attachment[] In-memory attachments (populated at runtime, not from DB). */
  private array $_attachments = [];

  /** @var array<string, string> Token replacements applied during render. */
  private array $_replacements = [];

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
   * Create and persist a new email in DRAFT state.
   *
   * @throws ValidationException on bad input.
   */
  public static function make(
    SQLDatabase $conn,
    string      $subject,
    string      $body,
    string      $user,
    bool        $isMd       = false,
    ?int        $templateId = null,
  ): self {
    $instance = new self($conn);

    $instance->subject = Validator::field('subject', $subject)->text(min: 2, max: 255)->value()
      ?: throw new ValidationException("Email subject must be 2–255 characters.");

    $instance->body = Validator::field('body', $body)->html(min: 1, max: 0)->value()
      ?: throw new ValidationException("Email body must not be empty.");

    $instance->user = Validator::field('user', $user)->pattern('/^[A-Z0-9]{14,16}$/')->value()
      ?: throw new ValidationException("Invalid user code format.");

    $instance->is_md       = $isMd;
    $instance->template_id = $templateId;
    $instance->folder      = Folder::DRAFT->value;
    $instance->code        = $instance->_generateCode();

    if (!$instance->save()) {
      throw new \RuntimeException("Email::make() — failed to persist email.");
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
  }

  /**
   * Load an email by its public code.
   */
  public static function load(SQLDatabase $conn, string $code): ?self
  {
    $instance = new self($conn);
    if (!Validator::field('code', $code)->pattern(self::CODE_PATTERN)->value()) {
      return null;
    }
    $found = self::findBySql(
      'SELECT * FROM :db:.:tbl: WHERE `code` = ? LIMIT 1',
      [$code],
    );
    return $found ? $found[0] : null;
  }

  // -------------------------------------------------------------------------
  // Fluent builders
  // -------------------------------------------------------------------------

  /**
   * Update the subject and persist immediately.
   *
   * @throws ValidationException on invalid input.
   */
  public function setSubject(string $subject): self
  {
    $valid = Validator::field('subject', $subject)->text(min: 2, max: 255)->value();
    if ($valid === false) {
      throw new ValidationException("Email subject must be 2–255 characters.");
    }
    $this->subject = $valid;
    $this->save();
    return $this;
  }

  /**
   * Replace the body and persist immediately.
   *
   * @throws ValidationException on invalid input.
   */
  public function setBody(string $body, bool $isMd = false): self
  {
    $valid = Validator::field('body', $body)->html(min: 1, max: 0)->value();
    if ($valid === false) {
      throw new ValidationException("Email body must not be empty.");
    }
    $this->body  = $valid;
    $this->is_md = $isMd;
    $this->save();
    return $this;
  }

  /**
   * Bind (or unbind) a persisted template.
   * Pass null to detach any current template.
   */
  public function setTemplate(?Template $template): self
  {
    $this->template_id = $template?->id;
    $this->save();
    return $this;
  }

  /**
   * Register a token replacement applied at render time.
   * Does not persist — call as many times as needed before send().
   *
   * Example: $email->replace('%{name}', 'Alice');
   */
  public function replace(string $pattern, string $value): self
  {
    $this->_replacements[$pattern] = $value;
    return $this;
  }

  // -------------------------------------------------------------------------
  // Recipients
  // -------------------------------------------------------------------------

  /**
   * Find-or-create a persisted recipient tied to this email.
   *
   * @param string|array{email?: string, name?: string, surname?: string} $contact
   * @throws \RuntimeException if the email has not yet been persisted (id is null).
   */
  public function addRecipient(
    SQLDatabase   $conn,
    string|array  $contact,
    RecipientType $type    = RecipientType::TO,
    ?int          $mlistId = null,
  ): Recipient {
    if ($this->id === null) {
      throw new \RuntimeException("Email::addRecipient() — email must be persisted before adding recipients.");
    }
    return Recipient::forEmail($conn, $this->id, $contact, $type, $mlistId);
  }

  /**
   * Retrieve all recipients of a given type (or all types if null).
   *
   * @return Recipient[]
   */
  public function getRecipients(?RecipientType $type = null): array
  {
    if ($this->id === null) {
      return [];
    }
    $sql    = 'SELECT * FROM :db:.:tbl: WHERE `email_id` = ?';
    $params = [$this->id];
    if ($type !== null) {
      $sql    .= ' AND `type` = ?';
      $params[] = $type->value;
    }
    return Recipient::findBySql($sql . ' ORDER BY `id` ASC', $params) ?: [];
  }

  // -------------------------------------------------------------------------
  // Attachments
  // -------------------------------------------------------------------------

  /**
   * Add a persisted attachment backed by a php-file File instance.
   * The file reference will be written to `email_attachments` at send time.
   */
  public function attach(File $file): self
  {
    $this->_attachments[] = Attachment::fromFile($file);
    return $this;
  }

  /**
   * Add a transient attachment from a local filesystem path.
   * Not stored in `email_attachments` — driver reads the file directly.
   *
   * @throws \InvalidArgumentException if the path is not readable.
   */
  public function attachRaw(string $path, string $mimeType, string $name): self
  {
    $this->_attachments[] = Attachment::fromPath($path, $mimeType, $name);
    return $this;
  }

  // -------------------------------------------------------------------------
  // Rendering
  // -------------------------------------------------------------------------

  /**
   * Render the final HTML body.
   *
   * Pipeline:
   *   1. Start with $this->body (HTML entity-decoded).
   *   2. Convert Markdown → HTML if is_md is true.
   *   3. Inject into the bound template via %{body} (if template_id is set).
   *   4. Apply all registered token replacements.
   */
  public function render(): string
  {
    if (empty($this->body)) {
      return '';
    }

    $html = html_entity_decode($this->body);

    if ($this->is_md) {
      $converter = new CommonMarkConverter(['html_input' => 'allow']);
      $html      = (string) $converter->convert($html);
    }

    if ($this->template_id !== null && $this->conn() !== null) {
      $tpl = Template::findBySql(
        'SELECT * FROM :db:.:tbl: WHERE `id` = ? LIMIT 1',
        [$this->template_id],
      );
      if ($tpl) {
        $shell = $tpl[0]->render();
        $html  = str_replace('%{body}', $html, $shell);
      }
    }

    return $this->_applyReplacements($html);
  }

  /**
   * Render a plain-text fallback, stripping HTML tags.
   * Falls back to the configured plain-text template if body is empty.
   */
  public function renderPlainText(): string
  {
    $html = $this->render();
    if ($html === '') {
      return Config::get()->plainTextTemplate;
    }
    $plain = strip_tags($html);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\n{3,}/', "\n\n", $plain) ?? $plain);
  }

  // -------------------------------------------------------------------------
  // Delivery
  // -------------------------------------------------------------------------

  /**
   * Send the email immediately to all TO/CC/BCC recipients.
   *
   * The driver is instantiated fresh for each call via DriverFactory so that
   * different emails can use different transport configurations.
   *
   * Each TO recipient gets its own driver call (for per-recipient tracking).
   * CC and BCC addresses are included as headers on every TO send.
   *
   * @param SQLDatabase           $conn         Database connection (for log writes).
   * @param Profile               $sender        The From profile.
   * @param DriverConfigInterface $driverConfig  Transport config to use.
   * @return bool  True if every recipient was dispatched without exception.
   */
  public function send(
    SQLDatabase           $conn,
    Profile               $sender,
    DriverConfigInterface $driverConfig,
  ): bool {
    if ($this->id === null) {
      throw new \RuntimeException("Email::send() — email must be persisted before sending.");
    }

    if (empty($this->subject)) {
      throw new MailerException("Email::send() — subject is empty.");
    }

    // Store sender reference
    $this->sender_id = $sender->id;
    $this->save();

    $driver    = DriverFactory::fromConfig($driverConfig);
    $bodyHtml  = $this->render();
    $bodyText  = $this->renderPlainText();
    $subject   = $this->_applyReplacements($this->subject ?? '');

    // Collect CC / BCC / Reply-To headers (shared across all TO sends)
    $headers = $this->_buildSharedHeaders($conn);

    $toRecipients  = $this->getRecipients(RecipientType::TO);
    if (empty($toRecipients)) {
      throw new MailerException("Email::send() — no TO recipients.");
    }

    // Persist attachment records (persisted only; transient are driver-only)
    $this->_persistAttachments($conn);

    $success = true;
    foreach ($toRecipients as $recipient) {
      try {
        $qref = $driver->send(
          sender:      $sender,
          recipient:   $recipient,
          subject:     $subject,
          bodyHtml:    $bodyHtml,
          bodyText:    $bodyText,
          headers:     $headers,
          attachments: $this->_attachments,
        );

        $log = EmailLog::queue($conn, $this->id, (int) $sender->id, (int) $recipient->id);
        $log->markSent($qref);
      } catch (\Throwable $e) {
        $success = false;
      }
    }

    if ($success) {
      $this->folder = Folder::SENT->value;
      $this->save();
    }

    return $success;
  }

  /**
   * Queue the email for deferred delivery by moving it to OUTBOX.
   *
   * Creates an EmailLog entry (sent = false) for each TO recipient.
   * A separate queue runner should call send() on OUTBOX emails.
   *
   * @param SQLDatabase $conn      Database connection.
   * @param Profile     $sender    The From profile.
   * @param int         $priority  Delivery priority 1 (high) – 10 (low).
   * @return bool  True if the email was successfully moved to OUTBOX.
   */
  public function queue(
    SQLDatabase $conn,
    Profile     $sender,
    int         $priority = 5,
  ): bool {
    if ($this->id === null) {
      throw new \RuntimeException("Email::queue() — email must be persisted before queueing.");
    }

    $this->sender_id = $sender->id;
    $this->folder    = Folder::OUTBOX->value;

    if (!$this->save()) {
      return false;
    }

    $toRecipients = $this->getRecipients(RecipientType::TO);
    foreach ($toRecipients as $recipient) {
      EmailLog::queue($conn, $this->id, (int) $sender->id, (int) $recipient->id, $priority);
    }

    return true;
  }

  // -------------------------------------------------------------------------
  // Folder management
  // -------------------------------------------------------------------------

  /**
   * Move the email to a different folder and persist.
   *
   * @throws \LogicException if attempting to un-send (move out of SENT).
   */
  public function moveTo(Folder $folder): bool
  {
    if ($this->folder === Folder::SENT->value && !$folder->isMutable()) {
      // Already in SENT — no-op
      return true;
    }
    if ($this->folder === Folder::SENT->value) {
      throw new \LogicException("Email::moveTo() — cannot move a SENT email back to {$folder->value}.");
    }
    $this->folder = $folder->value;
    return $this->save();
  }

  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

  /**
   * Apply all registered token replacements to a string.
   */
  private function _applyReplacements(string $text): string
  {
    if (empty($this->_replacements)) {
      return $text;
    }
    return str_replace(
      array_keys($this->_replacements),
      array_values($this->_replacements),
      $text,
    );
  }

  /**
   * Build the shared header map (CC / BCC / Reply-To) for the send call.
   *
   * @return array<string, string[]>
   */
  private function _buildSharedHeaders(SQLDatabase $conn): array
  {
    $headers = [];

    $cc = $this->getRecipients(RecipientType::CC);
    if (!empty($cc)) {
      $headers['Cc'] = array_map(fn(Recipient $r) => $r->getAddress(), $cc);
    }

    $bcc = $this->getRecipients(RecipientType::BCC);
    if (!empty($bcc)) {
      $headers['Bcc'] = array_map(fn(Recipient $r) => $r->getAddress(), $bcc);
    }

    $replyTo = $this->getRecipients(RecipientType::REPLY_TO);
    if (!empty($replyTo)) {
      $headers['Reply-To'] = array_map(fn(Recipient $r) => $r->getAddress(), $replyTo);
    }

    return $headers;
  }

  /**
   * Write `email_attachments` rows for all persisted (php-file backed) attachments.
   * Transient attachments (fromPath) are skipped — no DB row is created for them.
   */
  private function _persistAttachments(SQLDatabase $conn): void
  {
    if (empty($this->_attachments) || $this->id === null) {
      return;
    }

    $db  = Config::get()->dbName;
    $tbl = 'email_attachments';

    foreach ($this->_attachments as $att) {
      if (!$att->isPersisted()) {
        continue;
      }

      // Avoid duplicates
      $exists = $conn->query(
        "SELECT id FROM `{$db}`.`{$tbl}` WHERE `email_id` = ? AND `file_id` = ? LIMIT 1",
        [$this->id, $att->fileId],
      );
      if ($exists && $exists->num_rows > 0) {
        continue;
      }

      $conn->query(
        "INSERT INTO `{$db}`.`{$tbl}` (`email_id`, `file_id`) VALUES (?, ?)",
        [$this->id, $att->fileId],
      );
    }
  }

  private function _generateCode(): string
  {
    do {
      $code = self::CODE_PREFIX . Random::numeric(self::CODE_LENGTH - strlen(self::CODE_PREFIX));
    } while (self::valueExists('code', $code));
    return $code;
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
