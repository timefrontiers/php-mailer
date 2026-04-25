<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Email\Attachment;
use TimeFrontiers\Mailer\Exception\DriverException;

/**
 * Contract for mail transport drivers.
 *
 * A driver receives a fully-resolved, substitution-complete payload and
 * returns the provider's message identifier on success, or throws
 * DriverException on any delivery failure.
 *
 * Adding a new provider:
 *   1. Create FooConfig implements DriverConfigInterface.
 *   2. Create FooDriver implements MailDriverInterface.
 *   3. Register in DriverFactory::fromConfig().
 */
interface MailDriverInterface
{
  /**
   * Dispatch a single message.
   *
   * @param  Attachment[] $attachments  Zero or more attachments.
   * @return string  Provider message ID (e.g. Mailgun's `<id@mg.domain>`).
   * @throws DriverException on any delivery failure.
   */
  public function send(
    Profile   $sender,
    Recipient $recipient,
    string    $subject,
    string    $bodyHtml,
    string    $bodyText,
    array     $headers     = [],
    array     $attachments = [],
  ): string;
}
