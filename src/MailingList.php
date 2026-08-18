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
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;

/**
 * Mailing list stored in `mailing_lists`.
 *
 * A mailing list is a named group of recipients. Each standing member is a row
 * in `mailing_list_members` with a non-null `mailing_list_id`.
 * At send time the caller iterates `recipients()` and sends to each member.
 *
 * Table:   mailing_lists
 * PK:      id (BIGINT UNSIGNED AUTO_INCREMENT)
 * Unique:  code CHAR(15) — prefix 218, 12 random digits
 */
/** @phpstan-consistent-constructor */
class MailingList
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination;

  public const CODE_PREFIX  = '218';
  public const CODE_LENGTH  = 15;
  public const CODE_PATTERN = '/^218\d{8,12}$/';

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'mailing_lists';
  /** @var list<string> */
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

    $instance->user = Validator::field('user', $user)->pattern('/^(SYSTEM|087[0-9]{8,12})$/')->value()
      ?: throw new ValidationException("Invalid user code format.");

    $instance->code = $instance->_generateCode();

    if (!$instance->save()) {
      throw new \RuntimeException("MailingList::make() — failed to persist mailing list.");
    }

    $instance->id = (int) DatabaseGateway::insertId($instance->conn());
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
      'SELECT `id`,`code`,`user`,`name`,`_author`,`_created`,`_updated` FROM :db:.:tbl: WHERE `code` = ? LIMIT 1',
      [$code],
    );
    if ($found === false) {
      throw new \RuntimeException('Mailing-list lookup failed.');
    }
    return $found === [] ? null : $found[0];
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

    $db = Config::get()->dbName;
    $sql = "SELECT `id`,NULL AS `email_id`,`mailing_list_id` AS `mlist_id`,`type`,`address`,`name`,`surname`,`_created` FROM `{$db}`.`mailing_list_members` WHERE `mailing_list_id`=?";
    $params = [$this->id];

    if ($type !== null) {
      $sql    .= ' AND `type` = ?';
      $params[] = $type->value;
    }

    $sql .= ' ORDER BY `id` ASC';

    $rows = DatabaseGateway::fetchAll($this->conn(), $sql, $params);
    if ($rows === false) {
      throw new \RuntimeException('Mailing-list members could not be loaded.');
    }
    return array_values(array_map(fn(array $row): Recipient => Recipient::_instantiateFromRow($row, $this->conn()), $rows));
  }

  /**
   * Add a new member to this list.
   *
   * Returns a Recipient-compatible hydrated view of the standing member row.
   *
   * @param string|array{email?: string, name?: string, surname?: string} $contact
   * @throws ValidationException|\RuntimeException
   */
  public function addMember(
    SQLDatabase   $conn,
    string|array  $contact,
    RecipientType $type = RecipientType::TO,
  ): Recipient {
    if ($this->id === null) {
      throw new \LogicException('Mailing list must be persisted before adding members.');
    }
    if ($conn->getInstance() !== $this->conn()->getInstance()) {
      throw new \LogicException('Cross-database mailing-list membership is not supported.');
    }
    $transient = Recipient::fromContact($contact, $type);
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($conn,
      "INSERT INTO `{$db}`.`mailing_list_members` (`mailing_list_id`,`type`,`address`,`name`,`surname`) VALUES (?,?,?,?,?) "
      . 'ON DUPLICATE KEY UPDATE `id`=LAST_INSERT_ID(`id`),`name`=VALUES(`name`),`surname`=VALUES(`surname`)',
      [$this->id, $type->value, $transient->address, $transient->name, $transient->surname],
    );
    if ($result === false) {
      throw new \RuntimeException("Mailing-list member {$transient->address} could not be persisted.");
    }
    $persisted = DatabaseGateway::fetchOne($conn,
      "SELECT `id`,NULL AS `email_id`,`mailing_list_id` AS `mlist_id`,`type`,`address`,`name`,`surname`,`_created` FROM `{$db}`.`mailing_list_members` WHERE `mailing_list_id`=? AND `address`=? AND `type`=? LIMIT 1",
      [$this->id, $transient->address, $type->value],
    );
    if (!is_array($persisted)) {
      throw new \RuntimeException('Persisted mailing-list member could not be reloaded.');
    }
    return Recipient::_instantiateFromRow($persisted, $conn);
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

  /** @param array<string,mixed> $row */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null): static
  {
    $instance = $conn === null ? new static() : new static($conn);
    foreach (static::$_db_fields as $key) {
      if (array_key_exists($key, $row) && property_exists($instance, $key)) {
        $instance->$key = $key === 'id' && $row[$key] !== null ? (int) $row[$key] : $row[$key];
      }
    }
    return $instance;
  }
}
