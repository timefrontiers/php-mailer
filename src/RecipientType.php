<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

/**
 * Email recipient addressing type — stored in `email_recipients.type`.
 */
enum RecipientType: string
{
  case TO       = 'to';
  case CC       = 'cc';
  case BCC      = 'bcc';
  case REPLY_TO = 'reply-to';

  /** Only a TO recipient triggers the direct send path in Email::send(). */
  public function isDirect(): bool
  {
    return $this === self::TO;
  }
}
