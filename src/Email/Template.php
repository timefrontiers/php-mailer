<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use League\CommonMark\CommonMarkConverter;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Data\Random;
use TimeFrontiers\Validation\Validator;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Exception\ValidationException;

/**
 * Email template stored in `email_templates`.
 *
 * A template is the outer HTML shell of an email. The body content
 * is injected into the template via the %{body} token at send time.
 * Templates can be authored as HTML or Markdown (is_md = true).
 *
 * Table:   email_templates
 * PK:      id (BIGINT UNSIGNED AUTO_INCREMENT)
 * Unique:  code CHAR(15) — prefix 429, 12 random digits
 */
class Template
{
  use \TimeFrontiers\Helper\DatabaseObject,
      \TimeFrontiers\Helper\Pagination,
      \TimeFrontiers\Helper\HasErrors;

  public const CODE_PREFIX  = '429';
  public const CODE_LENGTH  = 15;
  public const CODE_PATTERN = '/^429\d{12}$/';

  protected static string $_primary_key = 'id';
  protected static string $_db_name     = '';
  protected static string $_table_name  = 'email_templates';
  protected static array  $_db_fields   = [
    'id', 'code', 'user', 'title', 'body', 'is_md', '_author', '_created', '_updated',
  ];

  public ?int    $id    = null;
  public ?string $code  = null;
  public ?string $user  = null;
  public ?string $title = null;
  public ?string $body  = null;
  public bool    $is_md = false;

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
   * Create and persist a new template.
   *
   * @throws ValidationException on bad input.
   */
  public static function make(
    SQLDatabase $conn,
    string      $title,
    string      $body,
    string      $user,
    bool        $isMd = false,
  ): self {
    $instance = new self($conn);

    $instance->title = Validator::field('title', $title)->text(min: 5, max: 128)->value()
      ?: throw new ValidationException("Template title must be 5–128 characters.");

    $instance->body = Validator::field('body', $body)->html(min: 56, max: 0)->value()
      ?: throw new ValidationException("Template body is too short (min 56 characters).");

    $instance->user  = Validator::field('user', $user)->pattern('/^[A-Z0-9]{14,16}$/')->value()
      ?: throw new ValidationException("Invalid user code format.");

    $instance->is_md = $isMd;
    $instance->code  = $instance->_generateCode();

    if (!$instance->save()) {
      throw new \RuntimeException("Template::make() — failed to persist template.");
    }

    $instance->id = (int) $instance->conn()->insertId();
    return $instance;
  }

  /**
   * Load a template by its public code.
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
  // Rendering
  // -------------------------------------------------------------------------

  /**
   * Return the rendered body, converting Markdown → HTML if is_md is true.
   * HTML entities are decoded so stored &amp; etc. survive round-trips cleanly.
   */
  public function render(): string
  {
    if (empty($this->body)) {
      return '';
    }
    $raw = html_entity_decode($this->body);
    if ($this->is_md) {
      $converter = new CommonMarkConverter(['html_input' => 'allow']);
      return (string) $converter->convert($raw);
    }
    return $raw;
  }

  /**
   * In-place pattern replacement on the stored body.
   * Does NOT save — call save() afterwards if persistence is needed.
   */
  public function replace(string $pattern, string $value): void
  {
    if (!empty($this->body)) {
      $this->body = str_replace($pattern, $value, $this->body);
    }
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
