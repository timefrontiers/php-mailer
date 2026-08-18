<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Driver\MailgunConfig;
use TimeFrontiers\Mailer\Driver\SmtpConfig;
use TimeFrontiers\Mailer\Driver\SmtpDriver;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Exception\UnknownDeliveryException;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Tests\Support\CapturingTransport;
use TimeFrontiers\Mailer\Tests\Support\ConfigFactory;

final class TransportConfigTest extends TestCase
{
  protected function setUp(): void
  {
    Config::reset();
    Config::set(ConfigFactory::create());
  }

  protected function tearDown(): void
  {
    Config::reset();
  }

  public function testSmtpModesAndCredentialsAreMappedDeliberately(): void
  {
    $none = new SmtpConfig('smtp.example.test', 25, '', '', 'none', 4.5);
    self::assertStringContainsString('auto_tls=false', $none->toDsn());
    self::assertStringContainsString('require_tls=false', $none->toDsn());

    $required = new SmtpConfig('smtp.example.test', 587, 'a+b@example.test', 'p@ss:/word', 'required-tls');
    self::assertStringStartsWith('smtp://a%2Bb%40example.test:p%40ss%3A%2Fword@', $required->toDsn());
    self::assertStringContainsString('require_tls=true', $required->toDsn());

    $implicit = new SmtpConfig('smtp.example.test', 465, 'user', 'pass', 'implicit-tls');
    self::assertStringStartsWith('smtps://', $implicit->toDsn());

    $opportunistic = new SmtpConfig('smtp.example.test', 587, '', '', 'opportunistic-tls');
    self::assertStringContainsString('auto_tls=true', $opportunistic->toDsn());
    self::assertStringContainsString('require_tls=false', $opportunistic->toDsn());
  }

  public function testSmtpTimeoutIsAppliedToTheSymfonySocket(): void
  {
    $driver = new SmtpDriver(new SmtpConfig('smtp.example.test', timeout: 4.5));
    $property = new \ReflectionProperty(SmtpDriver::class, 'transport');
    $transport = $property->getValue($driver);
    self::assertInstanceOf(SmtpTransport::class, $transport);
    $stream = $transport->getStream();
    self::assertInstanceOf(SocketStream::class, $stream);
    self::assertSame(4.5, $stream->getTimeout());
  }

  public function testInvalidSmtpAndMailgunSettingsFailAtConfigurationTime(): void
  {
    try {
      new SmtpConfig('bad host', 587);
      self::fail('Invalid SMTP host should fail.');
    } catch (\InvalidArgumentException) {
      self::assertTrue(true);
    }
    $this->expectException(\InvalidArgumentException::class);
    new MailgunConfig('mg.example.test', 'key-secret', 'asia');
  }

  public function testMailgunRegionUsesTheSymfonyRegionOption(): void
  {
    $config = new MailgunConfig('mg.example.test', 'key:secret', 'eu');
    self::assertSame('mailgun+api://key%3Asecret:mg.example.test@default?region=eu', $config->toDsn());
  }

  public function testBccIsAnEnvelopeRecipientButNotVisibleOnTheWire(): void
  {
    $transport = new CapturingTransport();
    $driver = new SmtpDriver(new SmtpConfig('smtp.example.test'), $transport);
    $sender = new Profile();
    $sender->address = 'sender@example.test';
    $sender->name = 'Sender';
    $recipient = Recipient::make('secret@example.test', type: RecipientType::BCC);

    self::assertSame('captured-message-id', $driver->send($sender, $recipient, 'Subject', '<p>Body</p>', 'Body'));
    self::assertNotNull($transport->sent);
    self::assertSame('secret@example.test', $transport->sent->getEnvelope()->getRecipients()[0]->getAddress());
    self::assertStringNotContainsStringIgnoringCase('Bcc:', $transport->sent->toString());
  }

  public function testTransportExceptionsAreRedactedAndClassifiedAsUnknown(): void
  {
    $transport = new CapturingTransport();
    $transport->throw = true;
    $driver = new SmtpDriver(new SmtpConfig('smtp.example.test'), $transport);
    $sender = new Profile();
    $sender->address = 'sender@example.test';
    $recipient = Recipient::make('to@example.test');

    try {
      $driver->send($sender, $recipient, 'Subject', '<p>Body</p>', 'Body');
      self::fail('Unknown transport result should throw.');
    } catch (UnknownDeliveryException $exception) {
      self::assertStringNotContainsString('secret transport failure', $exception->getMessage());
    }
  }
}
