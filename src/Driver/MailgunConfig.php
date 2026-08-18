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
  ) {
    if ($this->domain === '' || preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D', $this->domain) !== 1) {
      throw new \InvalidArgumentException('Mailgun domain is invalid.');
    }
    if (trim($this->apiKey) === '') {
      throw new \InvalidArgumentException('Mailgun API key is required.');
    }
    if (!in_array($this->region, ['us', 'eu'], true)) {
      throw new \InvalidArgumentException('Mailgun region must be us or eu.');
    }
  }

  public function driverName(): string
  {
    return 'mailgun';
  }

  /**
   * Build the Symfony Mailer DSN for this config.
   * Format: mailgun+api://KEY:DOMAIN@default?region=us|eu
   */
  public function toDsn(): string
  {
    return sprintf(
      'mailgun+api://%s:%s@default?region=%s',
      rawurlencode($this->apiKey),
      rawurlencode($this->domain),
      rawurlencode($this->region),
    );
  }
}
