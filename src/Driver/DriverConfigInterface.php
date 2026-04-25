<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

/**
 * Marker interface for driver-specific configuration objects.
 *
 * Implement this interface on every driver config class so that
 * DriverFactory can resolve the correct driver at runtime, and so
 * Config can accept any provider config through a single typed property.
 *
 * Example implementations: MailgunConfig, SmtpConfig.
 * Adding a new provider: create FooConfig implements DriverConfigInterface,
 * create FooDriver implements MailDriverInterface, register in DriverFactory.
 */
interface DriverConfigInterface
{
  /** Return a human-readable name for this driver (e.g. 'mailgun', 'smtp'). */
  public function driverName(): string;
}
