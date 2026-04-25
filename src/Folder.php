<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

/**
 * Email folder states — stored as-is in the `emails.folder` column.
 */
enum Folder: string
{
  case DRAFT  = 'DRAFT';
  case OUTBOX = 'OUTBOX';
  case SENT   = 'SENT';

  /** Returns true while the email content is still mutable. */
  public function isMutable(): bool
  {
    return match($this) {
      self::DRAFT, self::OUTBOX => true,
      self::SENT                => false,
    };
  }
}
