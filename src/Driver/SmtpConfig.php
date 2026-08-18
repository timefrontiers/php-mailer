<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

/**
 * Configuration for the native SMTP driver (Symfony Mailer).
 *
 * Encryption options:
 *   'required-tls'      — require STARTTLS (port 587, recommended)
 *   'opportunistic-tls' — use STARTTLS when advertised
 *   'implicit-tls'      — TLS from connection start (port 465)
 *   'none'              — disable Symfony automatic TLS deliberately
 *
 * Usage:
 *   new SmtpConfig(host: 'smtp.mailhog.local', port: 1025, encryption: 'none')
 *   new SmtpConfig(host: 'smtp.gmail.com', port: 587, username: 'u', password: 'p')
 */
final class SmtpConfig implements DriverConfigInterface
{
  public function __construct(
    public readonly string $host,
    public readonly int    $port       = 587,
    public readonly string $username   = '',
    public readonly string $password   = '',
    /** required-tls | opportunistic-tls | implicit-tls | none; tls/ssl are legacy aliases. */
    public readonly string $encryption = 'required-tls',
    public readonly float $timeout = 30.0,
  ) {
    if ($this->host === '' || preg_match('/[\s\/@?#]/', $this->host) === 1) {
      throw new \InvalidArgumentException('SMTP host is invalid.');
    }
    if ($this->port < 1 || $this->port > 65535) {
      throw new \InvalidArgumentException('SMTP port must be between 1 and 65535.');
    }
    if (($this->username === '') !== ($this->password === '')) {
      throw new \InvalidArgumentException('SMTP username and password must either both be set or both be empty.');
    }
    if (!in_array($this->encryption, ['required-tls', 'opportunistic-tls', 'implicit-tls', 'none', 'tls', 'ssl'], true)) {
      throw new \InvalidArgumentException('Unknown SMTP encryption mode.');
    }
    if (!is_finite($this->timeout) || $this->timeout < 0.1 || $this->timeout > 300.0) {
      throw new \InvalidArgumentException('SMTP timeout must be between 0.1 and 300 seconds.');
    }
  }

  public function driverName(): string
  {
    return 'smtp';
  }

  /**
   * Build the Symfony Mailer DSN for this config.
   *
   * Credentials are percent-encoded and callers must never log this value.
   */
  public function toDsn(): string
  {
    $mode = match ($this->encryption) {
      'tls' => 'required-tls',
      'ssl' => 'implicit-tls',
      default => $this->encryption,
    };
    $scheme = $mode === 'implicit-tls' ? 'smtps' : 'smtp';

    $auth = '';
    if ($this->username !== '') {
      $auth = rawurlencode($this->username);
      if ($this->password !== '') {
        $auth .= ':' . rawurlencode($this->password);
      }
      $auth .= '@';
    }

    $host = str_contains($this->host, ':') ? '[' . trim($this->host, '[]') . ']' : $this->host;
    $query = [
      'auto_tls' => $mode === 'none' ? 'false' : 'true',
      'require_tls' => $mode === 'required-tls' ? 'true' : 'false',
    ];
    return "{$scheme}://{$auth}{$host}:{$this->port}?" . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }
}
