<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use TimeFrontiers\File\File;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Exception\DriverException;
use TimeFrontiers\SQLDatabase;

/**
 * Immutable attachment value object passed to the mail driver at send time.
 *
 * Two construction paths:
 *
 *   // System file — already stored in php-file; attachment row persisted to DB.
 *   $att = Attachment::fromFile($file);
 *
 *   // Raw / transient — a local path not managed by php-file; never persisted.
 *   $att = Attachment::fromPath('/tmp/invoice.pdf', 'application/pdf', 'Invoice.pdf');
 *
 * Content is loaded lazily on first call to getContent().
 */
final class Attachment
{
  private function __construct(
    /** Display filename sent to the mail server. */
    public readonly string  $name,
    /** MIME type (e.g. 'application/pdf', 'image/png'). */
    public readonly string  $mimeType,
    /** Lazy content loader — called once by the driver at send time. */
    private readonly \Closure $loader,
    /**
     * file_meta.id from php-file. Non-null = persisted attachment.
     * Null = transient (fromPath) — not stored in email_attachments.
     */
    public readonly ?int $fileId = null,
    public readonly int $size = 0,
  ) {
    if ($this->name === '' || preg_match('/[\r\n\0]/', $this->name) === 1) {
      throw new \InvalidArgumentException('Attachment name is invalid.');
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+\/-]*$/D', $this->mimeType) !== 1) {
      throw new \InvalidArgumentException('Attachment MIME type is invalid.');
    }
    if ($this->size < 0) {
      throw new \InvalidArgumentException('Attachment size cannot be negative.');
    }
  }

  // -------------------------------------------------------------------------
  // Factory methods
  // -------------------------------------------------------------------------

  /**
   * Build from a php-file File instance (persisted attachment).
   */
  public static function fromFile(File $file): self
  {
    if ($file->id === null) {
      throw new \InvalidArgumentException('Queued attachments must reference a persisted php-file object.');
    }
    return new self(
      name:     $file->nice_name !== '' ? $file->nice_name : $file->name(),
      mimeType: $file->type(),
      loader:   static fn(): mixed => $file->openReadStream(),
      fileId:   $file->id,
      size:     $file->size(),
    );
  }

  public static function fromFileId(SQLDatabase $conn, int $fileId): self
  {
    $resolver = Config::get()->fileResolver;
    $file = $resolver === null ? File::findById($fileId, $conn) : $resolver($conn, $fileId);
    if (!$file instanceof File) {
      throw new DriverException('Persisted attachment could not be loaded.');
    }
    return self::fromFile($file);
  }

  /**
   * Build from a local filesystem path (transient — not stored in DB).
   *
   * @throws \InvalidArgumentException if the path is not readable.
   */
  public static function fromPath(string $path, string $mimeType, string $name): self
  {
    if (!is_readable($path)) {
      throw new \InvalidArgumentException("Cannot read attachment at path: {$path}");
    }
    $size = filesize($path);
    if ($size === false) {
      throw new \InvalidArgumentException("Cannot determine attachment size at path: {$path}");
    }
    return new self(
      name:     $name,
      mimeType: $mimeType,
      loader:   static fn(): mixed => fopen($path, 'rb'),
      size:     $size,
    );
  }

  // -------------------------------------------------------------------------
  // Accessors
  // -------------------------------------------------------------------------

  /** Load and return attachment bytes. Called once by the driver. */
  public function getContent(): string
  {
    $limit = Config::get()->maxAttachmentBytes;
    if ($this->size > $limit) {
      throw new DriverException('Attachment exceeds the configured individual size limit.');
    }
    $stream = ($this->loader)();
    if (!is_resource($stream)) {
      throw new DriverException('Attachment stream could not be opened.');
    }
    try {
      $content = stream_get_contents($stream, $limit + 1);
    } finally {
      fclose($stream);
    }
    if ($content === false) {
      throw new DriverException('Attachment stream could not be read.');
    }
    if (strlen($content) > $limit) {
      throw new DriverException('Attachment exceeds the configured individual size limit.');
    }
    if (strlen($content) !== $this->size) {
      throw new DriverException('Attachment stream length does not match persisted metadata.');
    }
    return $content;
  }

  /** True when backed by a php-file record (email_attachments row will exist). */
  public function isPersisted(): bool
  {
    return $this->fileId !== null;
  }
}
