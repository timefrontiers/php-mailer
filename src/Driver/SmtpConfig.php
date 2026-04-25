<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

/**
 * Configuration for the native SMTP driver (Symfony Mailer).
 *
 * Encryption options:
 *   'tls'  — STARTTLS upgrade on connection (port 587, recommended)
 *   'ssl'  — Implicit TLS from the start (port 465 / smtps://)
 *   'none' — Plaintext, no TLS (port 25, use only on trusted internal relays)
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
    /** 'tls' | 'ssl' | 'none' */
    public readonly string $encryption = 'tls',
  ) {}

  public function driverName(): string
  {
    return 'smtp';
  }

  /**
   * Build the Symfony Mailer DSN for this config.
   *
   * 'ssl' → smtps://user:pass@host:port  (implicit TLS)
   * 'tls' → smtp://user:pass@host:port   (STARTTLS — Symfony auto-negotiates)
   * 'none'→ smtp://user:pass@host:port   (no TLS)
   */
  public function toDsn(): string
  {
    $scheme = $this->encryption === 'ssl' ? 'smtps' : 'smtp';

    $auth = '';
    if ($this->username !== '') {
      $auth = rawurlencode($this->username);
      if ($this->password !== '') {
        $auth .= ':' . rawurlencode($this->password);
      }
      $auth .= '@';
    }

    return "{$scheme}://{$auth}{$this->host}:{$this->port}";
  }
}
