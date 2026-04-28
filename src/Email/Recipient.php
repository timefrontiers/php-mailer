<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use TimeFrontiers\{SQLDatabase, Str};
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Exception\ValidationException;

/**
 * An email recipient row in `email_recipients`.
 *
 * Each row ties one address+type to a specific email (email_id).
 * Rows with a non-null mlist_id also record which mailing list the
 * recipient came from (used for unsubscribe tracking).
 *
 * Table:  email_recipients
 * PK:     id (BIGINT UNSIGNED AUTO_INCREMENT)
 *
 * Factory patterns:
 *   // Find-or-create a persisted recipient for a specific email send
 *   $r = Recipient::forEmail($conn, $emailId, 'alice@example.com', [
 *       'name' => 'Alice', 'surname' => 'Smith',
 *   ], RecipientType::TO);
 *
 *   // Lightweight value object (not persisted) — e.g. iterating list members
 *   $r = Recipient::make('alice@example.com', 'Alice', 'Smith', RecipientType::CC);
 */
class Recipient
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'email_recipients';
  protected static array  $_db_fields   = [
    'id', 'email_id', 'mlist_id', 'type', 'address', 'name', 'surname', '_created',
  ];

  public ?int    $id       = null;
  public ?int    $email_id = null;   // FK → emails.id  (null = list-only member)
  public ?int    $mlist_id = null;   // FK → mailing_lists.id
  public string  $type     = RecipientType::TO->value;
  public ?string $address  = null;
  public ?string $name     = null;
  public ?string $surname  = null;

  protected ?string $_created = null;

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
   * Find-or-create a persisted recipient tied to a specific email row.
   *
   * @param string|array{email?: string, name?: string, surname?: string} $contact
   * @throws ValidationException|\RuntimeException
   */
  public static function forEmail(
    SQLDatabase   $conn,
    int           $emailId,
    string|array  $contact,
    RecipientType $type    = RecipientType::TO,
    ?int          $mlistId = null,
  ): self {
    $instance = new self($conn);
    [$address, $name, $surname] = $instance->_normaliseContact($contact);

    $found = self::findBySql(
      'SELECT * FROM :db:.:tbl: WHERE `email_id` = ? AND `address` = ? AND `type` = ? LIMIT 1',
      [$emailId, $address, $type->value],
    );
    if ($found) {
      return $found[0];
    }

    $instance->email_id = $emailId;
    $instance->address  = $address;
    $instance->name     = $name;
    $instance->surname  = $surname;
    $instance->type     = $type->value;
    $instance->mlist_id = $mlistId;

    if (!$instance->save()) {
      throw new \RuntimeException("Recipient::forEmail() — failed to persist recipient {$address}.");
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
  }

  /**
   * Build a transient (non-persisted) Recipient value object.
   * Useful when iterating mailing-list members at send time.
   */
  public static function make(
    string        $address,
    string        $name    = '',
    string        $surname = '',
    RecipientType $type    = RecipientType::TO,
    ?int          $mlistId = null,
  ): self {
    $address = Validator::field('address', $address)->email()->value();
    if ($address === false) {
      throw new ValidationException("Recipient::make() — invalid address.");
    }

    $instance          = new self();
    $instance->address = $address;
    $instance->name    = $name !== '' ? $name : null;
    $instance->surname = $surname !== '' ? $surname : null;
    $instance->type    = $type->value;
    $instance->mlist_id = $mlistId;

    return $instance;
  }

  // -------------------------------------------------------------------------
  // Accessors
  // -------------------------------------------------------------------------

  public function displayName(): string
  {
    if (empty($this->name)) {
      return '';
    }
    return trim("{$this->name}" . (!empty($this->surname) ? " {$this->surname}" : ''));
  }

  public function getAddress(): string
  {
    $display = $this->displayName();
    return $display !== '' ? "{$display} <{$this->address}>" : ($this->address ?? '');
  }

  public function recipientType(): RecipientType
  {
    return RecipientType::from($this->type);
  }

  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

  /** @return array{string, string|null, string|null} */
  private function _normaliseContact(string|array $contact): array
  {
    if (is_array($contact)) {
      $address = Validator::field('email', $contact['email'] ?? '')->email()->value();
      if ($address === false) {
        throw new ValidationException("Contact array missing valid [email] key.");
      }
      $name    = Validator::field('name',    $contact['name']    ?? '')->name()->value() ?: null;
      $surname = Validator::field('surname', $contact['surname'] ?? '')->name()->value() ?: null;
    } else if ($contact = Str::parseEmailName($contact)) {
      $address = Validator::field('email', $contact['email'] ?? '')->email()->value();
      if ($address === false) {
        throw new ValidationException("Contact array missing valid [email] key.");
      }
      $name    = Validator::field('name',    $contact['name']    ?? '')->name()->value() ?: null;
      $surname = Validator::field('surname', $contact['surname'] ?? '')->name()->value() ?: null;
    } else {
      $address = Validator::field('email', $contact)->email()->value();
      if ($address === false) {
        throw new ValidationException("Invalid email address: {$contact}");
      }
      $name    = null;
      $surname = null;
    }
    return [$address, $name, $surname];
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
