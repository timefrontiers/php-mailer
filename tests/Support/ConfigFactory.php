<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Support;

use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Driver\MailDriverInterface;
use TimeFrontiers\Mailer\Driver\SmtpConfig;

final class ConfigFactory
{
  /** @param (\Closure(\TimeFrontiers\Mailer\Driver\DriverConfigInterface):MailDriverInterface)|null $driverFactory */
  public static function create(
    string $dbName = 'mailer_test',
    ?\Closure $driverFactory = null,
    ?\Closure $clock = null,
    int $maxAttempts = 3,
    ?\Closure $fileResolver = null,
    int $maxAttachmentBytes = 25_165_824,
    int $maxTotalAttachmentBytes = 31_457_280,
  ): Config {
    return new Config(
      dbName: $dbName,
      mailServer: 'https://mail.example.test',
      driver: new SmtpConfig('smtp.example.test', 587, 'user', 'password', 'required-tls'),
      queueMaxAttempts: $maxAttempts,
      queueBaseBackoffSeconds: 1,
      maxAttachmentBytes: $maxAttachmentBytes,
      maxTotalAttachmentBytes: $maxTotalAttachmentBytes,
      driverFactory: $driverFactory,
      clock: $clock,
      fileResolver: $fileResolver,
    );
  }
}
