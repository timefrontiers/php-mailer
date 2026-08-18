<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\Email\Recipient;

interface IdempotentMailDriverInterface extends MailDriverInterface
{
  /**
   * @param array<string,string|list<string>> $headers
   * @param list<\TimeFrontiers\Mailer\Email\Attachment> $attachments
   */
  public function sendIdempotently(
    string $idempotencyKey,
    Profile $sender,
    Recipient $recipient,
    string $subject,
    string $bodyHtml,
    string $bodyText,
    array $headers = [],
    array $attachments = [],
  ): string;
}
