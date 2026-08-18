<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\File\File;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Email\Attachment;
use TimeFrontiers\Mailer\Exception\DriverException;
use TimeFrontiers\Mailer\Tests\Support\ConfigFactory;

final class AttachmentTest extends TestCase
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

  public function testPhpFileStreamsWorkWithoutLocalFullPath(): void
  {
    $file = new class('remote bytes') extends File {
      public function __construct(private string $content) { $this->id = 41; $this->nice_name = 'remote.txt'; }
      public function openReadStream(): mixed { $stream = fopen('php://temp', 'w+b'); fwrite($stream, $this->content); rewind($stream); return $stream; }
      public function size(): int { return strlen($this->content); }
      public function type(): string { return 'text/plain'; }
      public function name(): string { return 'object-key.txt'; }
    };
    $attachment = Attachment::fromFile($file);
    self::assertSame('remote bytes', $attachment->getContent());
  }

  public function testReadFailureNeverBecomesAnEmptyAttachment(): void
  {
    $file = new class extends File {
      public function __construct() { $this->id = 42; $this->nice_name = 'broken.txt'; }
      public function openReadStream(): mixed { return false; }
      public function size(): int { return 10; }
      public function type(): string { return 'text/plain'; }
      public function name(): string { return 'broken.txt'; }
    };
    $this->expectException(DriverException::class);
    Attachment::fromFile($file)->getContent();
  }

  public function testLocalPathUsesTheSameBoundedStreamContract(): void
  {
    $path = tempnam(sys_get_temp_dir(), 'mailer-unit-');
    self::assertNotFalse($path);
    file_put_contents($path, 'local bytes');
    try {
      self::assertSame('local bytes', Attachment::fromPath($path, 'text/plain', 'local.txt')->getContent());
    } finally {
      @unlink($path);
    }
  }

  public function testConfiguredSizeLimitIsEnforcedBeforeReading(): void
  {
    Config::reset();
    Config::set(ConfigFactory::create(maxAttachmentBytes: 4));
    $file = new class extends File {
      public function __construct() { $this->id = 43; $this->nice_name = 'large.txt'; }
      public function openReadStream(): mixed { return fopen('php://temp', 'w+b'); }
      public function size(): int { return 5; }
      public function type(): string { return 'text/plain'; }
      public function name(): string { return 'large.txt'; }
    };
    $this->expectException(DriverException::class);
    Attachment::fromFile($file)->getContent();
  }
}
