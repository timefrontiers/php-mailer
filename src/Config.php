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
    /**
     * Template registry indexed by message_type.
     * Format:
     *   [
     *     'default' => ['templateCode' => '42912345678', 'replaceVars' => ['user-name', 'user-surname']],
     *     'order'   => ['templateCode' => '42999999999', 'replaceVars' => ['order-id', 'total']],
     *   ]
     * A null or missing templateCode means send with no template for that type.
     *
     * @var array<string, array{templateCode: string|null, replaceVars: string[]}>
     */
    public readonly array $templates = [],
    /** reject | preserve | empty */
    public readonly string $unresolvedTokenPolicy = 'reject',
    /** Trusted templates may contain raw HTML; user-authored untrusted HTML is unsupported. */
    public readonly bool $trustedTemplateHtml = true,
    public readonly int $maxTemplateBytes = 1_048_576,
    public readonly int $maxRenderedBytes = 2_097_152,
    public readonly int $maxAttachments = 20,
    public readonly int $maxAttachmentBytes = 25_165_824,
    public readonly int $maxTotalAttachmentBytes = 31_457_280,
    /** @var list<string> Empty means every non-executable MIME accepted by php-file is allowed. */
    public readonly array $allowedAttachmentMimeTypes = [],
    public readonly int $queueLeaseSeconds = 300,
    public readonly int $queueMaxAttempts = 5,
    public readonly int $queueBaseBackoffSeconds = 60,
    /** @var (\Closure(DriverConfigInterface):\TimeFrontiers\Mailer\Driver\MailDriverInterface)|null */
    public readonly ?\Closure $driverFactory = null,
    /** @var (\Closure():\DateTimeImmutable)|null Deterministic queue clock for tests and controlled workers. */
    public readonly ?\Closure $clock = null,
    /** @var (\Closure(\TimeFrontiers\SQLDatabase,int):\TimeFrontiers\File\File)|null */
    public readonly ?\Closure $fileResolver = null,
  ) {
    if (preg_match('/^[A-Za-z0-9_]+$/D', $this->dbName) !== 1) {
      throw new ConfigException('Mailer database name contains unsupported characters.');
    }
    $url = parse_url($this->mailServer);
    if ($url === false || !isset($url['scheme'], $url['host']) || !in_array(strtolower((string) $url['scheme']), ['https', 'http'], true)) {
      throw new ConfigException('Mailer server must be an absolute HTTP or HTTPS URL.');
    }
    if (!in_array($this->unresolvedTokenPolicy, ['reject', 'preserve', 'empty'], true)) {
      throw new ConfigException('Unresolved token policy must be reject, preserve, or empty.');
    }
    foreach ([$this->maxTemplateBytes, $this->maxRenderedBytes, $this->maxAttachments, $this->maxAttachmentBytes, $this->maxTotalAttachmentBytes, $this->queueLeaseSeconds, $this->queueMaxAttempts, $this->queueBaseBackoffSeconds] as $limit) {
      if ($limit < 1) {
        throw new ConfigException('Mailer size, retry, and lease limits must be positive integers.');
      }
    }
    if ($this->maxAttachmentBytes > $this->maxTotalAttachmentBytes) {
      throw new ConfigException('Individual attachment limit may not exceed the total attachment limit.');
    }
  }

  // -------------------------------------------------------------------------
  // Static registry
  // -------------------------------------------------------------------------

  public static function set(self $config): void
  {
    if (self::$_instance !== null) {
      throw new ConfigException('TimeFrontiers\\Mailer: Config is already initialised and cannot be replaced at runtime.');
    }
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

  /**
   * Return the template config entry for a given message type, or null if not found.
   *
   * @return array{templateCode: string|null, replaceVars: string[]}|null
   */
  public function getTemplate(string $type): ?array
  {
    return $this->templates[$type] ?? null;
  }

  public function now(): \DateTimeImmutable
  {
    $now = $this->clock === null ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : ($this->clock)();
    if (!$now instanceof \DateTimeImmutable) {
      throw new ConfigException('Mailer clock must return DateTimeImmutable.');
    }
    return $now->setTimezone(new \DateTimeZone('UTC'));
  }

  /** Reset — intended for tests only. */
  public static function reset(): void
  {
    self::$_instance = null;
  }
}
