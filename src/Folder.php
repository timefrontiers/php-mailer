<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

/**
 * Email folder states — stored as-is in the `emails.folder` column.
 */
enum Folder: string
{
  case DRAFT  = 'draft';
  case OUTBOX = 'outbox';
  case SENT   = 'sent';

  /** Returns true while the email content is still mutable. */
  public function isMutable(): bool
  {
    return match($this) {
      self::DRAFT, self::OUTBOX => true,
      self::SENT                => false,
    };
  }
}
