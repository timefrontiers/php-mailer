<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Exception\ConfigException;
use TimeFrontiers\Mailer\Result\RecipientDeliveryResult;
use TimeFrontiers\Mailer\Result\SendResult;
use TimeFrontiers\Mailer\Tests\Support\ConfigFactory;

final class ConfigAndResultTest extends TestCase
{
  protected function tearDown(): void
  {
    Config::reset();
  }

  public function testConfigurationIsFrozenAfterBootstrap(): void
  {
    Config::set(ConfigFactory::create());
    $this->expectException(ConfigException::class);
    Config::set(ConfigFactory::create());
  }

  public function testPartialAndUnknownResultsRemainRepresentable(): void
  {
    $result = new SendResult([
      new RecipientDeliveryResult('ok@example.test', 'to', 'accepted', 'provider-1'),
      new RecipientDeliveryResult('retry@example.test', 'cc', 'failed', errorCode: 'provider_rejected'),
      new RecipientDeliveryResult('review@example.test', 'bcc', 'unknown', errorCode: 'network_unknown'),
    ]);
    self::assertSame(1, $result->acceptedCount());
    self::assertFalse($result->allAccepted());
    self::assertTrue($result->hasUnknownOutcomes());
  }
}
