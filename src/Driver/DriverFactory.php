<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

use TimeFrontiers\Mailer\Exception\MailerException;

/**
 * Resolves a DriverConfigInterface to its concrete MailDriverInterface.
 *
 * To add a new provider:
 *   1. Create FooConfig implements DriverConfigInterface.
 *   2. Create FooDriver implements MailDriverInterface.
 *   3. Add a case below.
 */
final class DriverFactory
{
  public static function fromConfig(DriverConfigInterface $config): MailDriverInterface
  {
    return match(true) {
      $config instanceof MailgunConfig => new MailgunDriver($config),
      $config instanceof SmtpConfig    => new SmtpDriver($config),
      default => throw new MailerException(
        'No driver registered for config type: ' . $config::class
      ),
    };
  }
}
