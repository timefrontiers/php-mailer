<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Exception\ValidationException;

/**
 * Sender profile — a verified email address + display name stored in
 * `mailer_profiles`. Profiles are looked up by address and auto-created
 * on first use via Profile::resolve().
 *
 * Table:  mailer_profiles
 * PK:     id (BIGINT UNSIGNED AUTO_INCREMENT)
 * Unique: address
 */
class Profile
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'mailer_profiles';
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

    $found = self::findBySql(
      'SELECT * FROM :db:.:tbl: WHERE `address` = ? LIMIT 1',
      [$address],
    );
    if ($found) {
      return $found[0];
    }

    $validName = Validator::field('name', $name)->name()->value();
    if ($validName === false) {
      throw new ValidationException("Profile::resolve() — invalid name.");
    }

    $instance->address = $address;
    $instance->name    = $validName;
    $instance->surname = Validator::field('surname', $surname)->name()->value() ?: '';

    if (!$instance->save()) {
      throw new \RuntimeException("Profile::resolve() — failed to persist profile for {$address}.");
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
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
