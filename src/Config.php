<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use TimeFrontiers\Mailer\Driver\DriverConfigInterface;
use TimeFrontiers\Mailer\Exception\ConfigException;

/**
 * Immutable top-level configuration for php-mailer.
 *
 * Initialise once — typically in the application bootstrap file —
 * before constructing any Mailer entity:
 *
 *   use TimeFrontiers\Mailer\Config;
 *   use TimeFrontiers\Mailer\Driver\MailgunConfig;   // or SmtpConfig
 *
 *   Config::set(new Config(
 *       dbName:     'msgservice',
 *       mailServer: 'https://mail.example.com',
 *       driver: new MailgunConfig(
 *           domain: 'mg.example.com',
 *           apiKey: 'key-…',
 *       ),
 *   ));
 *
 * All subsequent `new static()` calls (e.g. from DatabaseObject::_instantiateFromRow)
 * resolve configuration through Config::get() without re-passing it.
 */
final class Config
{
  private static ?self $_instance = null;

  public function __construct(
    /** MariaDB database name that owns the mailer tables. */
    public readonly string              $dbName,
    /** Base URL used in %{server} token substitution and read-in-browser links. */
    public readonly string              $mailServer,
    /** Driver config — determines which transport is used. */
    public readonly DriverConfigInterface $driver,
    /**
     * Plain-text fallback body sent alongside the HTML version.
     * May contain any %{…} tokens that the email's replace registry supports.
     */
    public readonly string $plainTextTemplate = 'Hello %{name}, if you cannot read the HTML version of this email you can view it online at %{server}/message/read/%{code}',
  ) {}

  // -------------------------------------------------------------------------
  // Static registry
  // -------------------------------------------------------------------------

  public static function set(self $config): void
  {
    self::$_instance = $config;
  }

  /**
   * @throws ConfigException if Config::set() has not been called.
   */
  public static function get(): self
  {
    if (self::$_instance === null) {
      throw new ConfigException(
        'TimeFrontiers\\Mailer: Config not initialised. Call Config::set() before using any Mailer class.'
      );
    }
    return self::$_instance;
  }

  public static function has(): bool
  {
    return self::$_instance !== null;
  }

  /** Reset — intended for tests only. */
  public static function reset(): void
  {
    self::$_instance = null;
  }
}
