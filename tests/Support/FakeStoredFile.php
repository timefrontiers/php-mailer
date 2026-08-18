<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Support;

use TimeFrontiers\File\File;
use TimeFrontiers\SQLDatabase;

final class FakeStoredFile extends File
{
  public function __construct(
    private readonly SQLDatabase $connection,
    int $fileId,
    private readonly string $content,
    string $name,
    private readonly string $mime = 'text/plain',
  ) {
    $this->id = $fileId;
    $this->nice_name = $name;
  }

  public function conn(): SQLDatabase { return $this->connection; }
  public function openReadStream(): mixed
  {
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
      return false;
    }
    fwrite($stream, $this->content);
    rewind($stream);
    return $stream;
  }
  public function size(): int { return strlen($this->content); }
  public function type(): string { return $this->mime; }
  public function name(): string { return $this->nice_name; }
}
