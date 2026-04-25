<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

/**
 * Configuration for the Mailgun API driver.
 *
 * Usage:
 *   new MailgunConfig(domain: 'mg.example.com', apiKey: 'key-…')
 *   new MailgunConfig(domain: 'mg.example.eu', apiKey: 'key-…', region: 'eu')
 */
final class MailgunConfig implements DriverConfigInterface
{
  public function __construct(
    /** Mailgun sending domain (e.g. mg.example.com). */
    public readonly string $domain,
    /** Mailgun private API key (key-…). */
    public readonly string $apiKey,
    /** API region: 'us' (default) or 'eu'. */
    public readonly string $region = 'us',
  ) {}

  public function driverName(): string
  {
    return 'mailgun';
  }

  /**
   * Build the Symfony Mailer DSN for this config.
   * Format: mailgun+api://KEY:DOMAIN@default  (US)
   *         mailgun+api://KEY:DOMAIN@eu        (EU)
   */
  public function toDsn(): string
  {
    $host = $this->region === 'eu' ? 'eu' : 'default';
    return sprintf(
      'mailgun+api://%s:%s@%s',
      rawurlencode($this->apiKey),
      rawurlencode($this->domain),
      $host,
    );
  }
}
