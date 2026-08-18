<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use TimeFrontiers\{SQLDatabase, Str};
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Exception\ValidationException;
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;

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
/** @phpstan-consistent-constructor */
class Recipient
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'email_recipients';
  /** @var list<string> */
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
    if ($emailId < 1) {
      throw new ValidationException('Recipient::forEmail() requires a persisted email id.');
    }
    $instance = new self($conn);
    [$address, $name, $surname] = $instance->_normaliseContact($contact);

    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($conn,
      "INSERT INTO `{$db}`.`email_recipients` (`email_id`,`mlist_id`,`type`,`address`,`name`,`surname`) VALUES (?,?,?,?,?,?) "
      . 'ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`name`=VALUES(`name`),`surname`=VALUES(`surname`),`mlist_id`=VALUES(`mlist_id`)',
      [$emailId, $mlistId, $type->value, $address, $name, $surname],
    );
    if ($result === false) {
      throw new \RuntimeException("Recipient::forEmail() — failed to persist recipient {$address}.");
    }
    $row = DatabaseGateway::fetchOne($conn,
      "SELECT `id`,`email_id`,`mlist_id`,`type`,`address`,`name`,`surname`,`_created` FROM `{$db}`.`email_recipients` WHERE `email_id`=? AND `address`=? AND `type`=? LIMIT 1",
      [$emailId, $address, $type->value],
    );
    if (!is_array($row)) {
      throw new \RuntimeException('Recipient::forEmail() — persisted recipient could not be reloaded.');
    }
    return self::_instantiateFromRow($row, $conn);
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

  /** @param string|array{email?:string,name?:string,surname?:string} $contact */
  public static function fromContact(string|array $contact, RecipientType $type = RecipientType::TO): self
  {
    $normaliser = new self();
    [$address, $name, $surname] = $normaliser->_normaliseContact($contact);
    return self::make($address, $name ?? '', $surname ?? '', $type);
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

  /**
   * @param string|array{email?:string,name?:string,surname?:string} $contact
   * @return array{string, string|null, string|null}
   */
  private function _normaliseContact(string|array $contact): array
  {
    if (is_array($contact)) {
      $address = Validator::field('email', $contact['email'] ?? '')->email()->value();
      if ($address === false) {
        throw new ValidationException("Contact array missing valid [email] key.");
      }
      $name    = Validator::field('name',    $contact['name']    ?? '')->name()->value() ?: null;
      $surname = Validator::field('surname', $contact['surname'] ?? '')->name()->value() ?: null;
    } else if ($parsed = Str::parseEmailName($contact)) {
      $address = Validator::field('email', $parsed['email'] ?? '')->email()->value();
      if ($address === false) {
        throw new ValidationException("Contact array missing valid [email] key.");
      }
      $name    = Validator::field('name',    $parsed['name']    ?? '')->name()->value() ?: null;
      $surname = Validator::field('surname', $parsed['surname'] ?? '')->name()->value() ?: null;
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

  /** @param array<string,mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $instance = $conn === null ? new static() : new static($conn);
    foreach (static::$_db_fields as $key) {
      if (array_key_exists($key, $row) && property_exists($instance, $key)) {
        $value = $row[$key];
        $instance->$key = $value;
      }
    }
    return $instance;
  }
}
