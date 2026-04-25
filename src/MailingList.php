<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Data\Random;
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Exception\ValidationException;

/**
 * Mailing list stored in `mailing_lists`.
 *
 * A mailing list is a named group of recipients. Each member is a row in
 * `email_recipients` with a non-null `mlist_id` and a null `email_id`.
 * At send time the caller iterates `recipients()` and sends to each member.
 *
 * Table:   mailing_lists
 * PK:      id (BIGINT UNSIGNED AUTO_INCREMENT)
 * Unique:  code CHAR(15) — prefix 218, 12 random digits
 */
class MailingList
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination,
      \TimeFrontiers\Helper\HasErrors;

  public const CODE_PREFIX  = '218';
  public const CODE_LENGTH  = 15;
  public const CODE_PATTERN = '/^218\d{12}$/';

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'mailing_lists';
  protected static array  $_db_fields   = [
    'id', 'code', 'user', 'name', '_author', '_created', '_updated',
  ];

  public ?int    $id   = null;
  public ?string $code = null;
  public ?string $user = null;
  public ?string $name = null;

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
   * Create and persist a new mailing list.
   *
   * @throws ValidationException on bad input.
   */
  public static function make(
    SQLDatabase $conn,
    string      $name,
    string      $user,
  ): self {
    $instance = new self($conn);

    $instance->name = Validator::field('name', $name)->text(min: 2, max: 128)->value()
      ?: throw new ValidationException("Mailing list name must be 2–128 characters.");

    $instance->user = Validator::field('user', $user)->pattern('/^[A-Z0-9]{14,16}$/')->value()
      ?: throw new ValidationException("Invalid user code format.");

    $instance->code = $instance->_generateCode();

    if (!$instance->save()) {
      throw new \RuntimeException("MailingList::make() — failed to persist mailing list.");
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
  }

  /**
   * Load a mailing list by its public code.
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
  // Member access
  // -------------------------------------------------------------------------

  /**
   * Return all members of this list as Recipient value objects.
   *
   * Optionally filter by recipient type (e.g. TO, CC, BCC).
   * All returned instances are transient — they are not bound to any email.
   *
   * @return Recipient[]
   */
  public function recipients(?RecipientType $type = null): array
  {
    if ($this->id === null) {
      return [];
    }

    $sql    = 'SELECT * FROM :db:.:tbl: WHERE `mlist_id` = ?';
    $params = [$this->id];

    if ($type !== null) {
      $sql    .= ' AND `type` = ?';
      $params[] = $type->value;
    }

    $sql .= ' ORDER BY `id` ASC';

    // Temporarily use the Recipient table by swapping the Recipient static scope.
    // DatabaseObject::findBySql resolves :db: and :tbl: from the calling class,
    // so we delegate directly to Recipient::findBySql.
    return Recipient::findBySql($sql, $params) ?: [];
  }

  /**
   * Add a new member to this list.
   *
   * Uses Recipient::forEmail() semantics but with email_id = null so the row
   * is a list-member rather than a per-email recipient.
   *
   * @param string|array{email?: string, name?: string, surname?: string} $contact
   * @throws ValidationException|\RuntimeException
   */
  public function addMember(
    SQLDatabase   $conn,
    string|array  $contact,
    RecipientType $type = RecipientType::TO,
  ): Recipient {
    // Reuse the Recipient make() factory for validation; then persist manually
    // with the mlist_id set and email_id left null.
    $address = is_array($contact) ? ($contact['email'] ?? '') : $contact;
    $name    = is_array($contact) ? ($contact['name']    ?? '') : '';
    $surname = is_array($contact) ? ($contact['surname'] ?? '') : '';

    $transient = Recipient::make($address, $name, $surname, $type, $this->id);

    // Check for duplicate before persisting
    $existing = Recipient::findBySql(
      'SELECT * FROM :db:.:tbl: WHERE `mlist_id` = ? AND `address` = ? AND `type` = ? LIMIT 1',
      [$this->id, $transient->address, $type->value],
    );
    if ($existing) {
      return $existing[0];
    }

    // Build a persisted recipient tied to this list (email_id stays null)
    $row             = new Recipient($conn);
    $row->email_id   = null;
    $row->mlist_id   = $this->id;
    $row->address    = $transient->address;
    $row->name       = $transient->name;
    $row->surname    = $transient->surname;
    $row->type       = $type->value;

    if (!$row->save()) {
      throw new \RuntimeException(
        "MailingList::addMember() — failed to persist member {$transient->address}."
      );
    }

    $row->id = (int) $row->conn()->insertId();
    return $row;
  }

  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

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
