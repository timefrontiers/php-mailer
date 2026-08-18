<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Exception\ValidationException;
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;

/**
 * Sender profile — a verified email address + display name stored in
 * `mailer_profiles`. Profiles are looked up by address and auto-created
 * on first use via Profile::resolve().
 *
 * Table:  mailer_profiles
 * PK:     id (BIGINT UNSIGNED AUTO_INCREMENT)
 * Unique: address
 */
/** @phpstan-consistent-constructor */
class Profile
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'mailer_profiles';
  /** @var list<string> */
  protected static array  $_db_fields   = [
    'id', 'address', 'name', 'surname', '_author', '_created',
  ];

  public ?int    $id      = null;
  public ?string $address = null;
  public ?string $name    = null;
  public ?string $surname = null;

  protected ?string $_author  = null;
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
   * Find an existing profile by address, or create one if absent.
   *
   * @throws ValidationException on invalid input.
   */
  public static function resolve(
    SQLDatabase $conn,
    string      $address,
    string      $name,
    string      $surname = '',
  ): self {
    $instance = new self($conn);

    $address = Validator::field('address', $address)->email()->value();
    if ($address === false) {
      throw new ValidationException("Profile::resolve() — invalid email address.");
    }

    $validName = Validator::field('name', $name)->name()->value();
    if ($validName === false) {
      throw new ValidationException("Profile::resolve() — invalid name.");
    }

    $validSurname = Validator::field('surname', $surname)->name()->value() ?: '';
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($conn,
      "INSERT INTO `{$db}`.`mailer_profiles` (`address`,`name`,`surname`) VALUES (?,?,?) "
      . 'ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`)',
      [$address, $validName, $validSurname],
    );
    if ($result === false) {
      throw new \RuntimeException("Profile::resolve() — failed to persist profile for {$address}.");
    }
    $row = DatabaseGateway::fetchOne($conn,
      "SELECT `id`,`address`,`name`,`surname`,`_author`,`_created` FROM `{$db}`.`mailer_profiles` WHERE `address`=? LIMIT 1",
      [$address],
    );
    if (!is_array($row)) {
      throw new \RuntimeException('Profile::resolve() — persisted profile could not be reloaded.');
    }
    return self::_instantiateFromRow($row, $conn);
  }

  // -------------------------------------------------------------------------
  // Accessors
  // -------------------------------------------------------------------------

  /**
   * RFC 5322 display name — used by drivers for the From/To header display.
   * Returns empty string when name is not set (driver falls back to raw address).
   */
  public function displayName(): string
  {
    if (empty($this->name)) {
      return '';
    }
    return trim("{$this->name}" . (!empty($this->surname) ? " {$this->surname}" : ''));
  }

  /**
   * Full RFC 5322 address string: "First Last <email>" or just "email".
   */
  public function getAddress(): string
  {
    $display = $this->displayName();
    return $display !== '' ? "{$display} <{$this->address}>" : ($this->address ?? '');
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
        $instance->$key = $row[$key];
      }
    }
    return $instance;
  }
}
