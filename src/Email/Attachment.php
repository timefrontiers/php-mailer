<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Email;

use TimeFrontiers\File\File;

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
  ) {}

  // -------------------------------------------------------------------------
  // Factory methods
  // -------------------------------------------------------------------------

  /**
   * Build from a php-file File instance (persisted attachment).
   */
  public static function fromFile(File $file): self
  {
    return new self(
      name:     $file->nice_name !== '' ? $file->nice_name : $file->name(),
      mimeType: $file->type(),
      loader:   static fn(): string => (string) file_get_contents($file->fullPath()),
      fileId:   $file->id,
    );
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
    return new self(
      name:     $name,
      mimeType: $mimeType,
      loader:   static fn(): string => (string) file_get_contents($path),
    );
  }

  // -------------------------------------------------------------------------
  // Accessors
  // -------------------------------------------------------------------------

  /** Load and return attachment bytes. Called once by the driver. */
  public function getContent(): string
  {
    return ($this->loader)();
  }

  /** True when backed by a php-file record (email_attachments row will exist). */
  public function isPersisted(): bool
  {
    return $this->fileId !== null;
  }
}
