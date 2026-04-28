<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Email\Attachment;
use TimeFrontiers\Mailer\Exception\DriverException;

/**
 * Mailgun driver — dispatches via the Mailgun HTTP API using
 * symfony/mailgun-mailer as the transport.
 *
 * Requires: symfony/mailer, symfony/mailgun-mailer, symfony/http-client.
 */
final class MailgunDriver implements MailDriverInterface
{
  private readonly TransportInterface $transport;

  public function __construct(MailgunConfig $config)
  {
    try {
      $this->transport = Transport::fromDsn($config->toDsn());
    } catch (\Throwable $e) {
      throw new DriverException(
        "[Mailgun] Failed to initialise transport: {$e->getMessage()}",
        previous: $e,
      );
    }
  }

  public function send(
    Profile   $sender,
    Recipient $recipient,
    string    $subject,
    string    $bodyHtml,
    string    $bodyText,
    array     $headers     = [],
    array     $attachments = [],
  ): string {
    $email = $this->_buildMessage(
      $sender, $recipient, $subject, $bodyHtml, $bodyText, $headers, $attachments,
    );

    try {
      $sent = $this->transport->send($email);
    } catch (\Throwable $e) {
      throw new DriverException(
        "[Mailgun] Dispatch failed: {$e->getMessage()}",
        previous: $e,
      );
    }

    $msgId = $sent?->getMessageId() ?? '';
    if ($msgId === '') {
      throw new DriverException('[Mailgun] Transport returned an empty message ID.');
    }

    return $msgId;
  }

  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

  private function _buildMessage(
    Profile   $sender,
    Recipient $recipient,
    string    $subject,
    string    $bodyHtml,
    string    $bodyText,
    array     $headers,
    array     $attachments,
  ): MimeEmail {
    $email = (new MimeEmail())
      ->from(new Address($sender->address ?? '', $sender->displayName()))
      ->to(new Address($recipient->address ?? '', $recipient->displayName()))
      ->subject($subject)
      ->html($bodyHtml)
      ->text($bodyText);

    foreach ($headers as $name => $value) {
      $addresses = is_array($value) ? $value : [$value];
      switch (strtolower((string) $name)) {
        case 'cc':
          $email->addCc(...array_map(fn($a) => Address::create((string) $a), $addresses));
          break;
        case 'bcc':
          $email->addBcc(...array_map(fn($a) => Address::create((string) $a), $addresses));
          break;
        case 'reply-to':
          $email->replyTo(...array_map(fn($a) => Address::create((string) $a), $addresses));
          break;
        default:
          $headerValue = implode(', ', $addresses);
          $email->getHeaders()->addTextHeader((string) $name, $headerValue);
      }
    }

    foreach ($attachments as $att) {
      if (!$att instanceof Attachment) {
        continue;
      }
      $email->addPart(new DataPart(
        body:        $att->getContent(),
        filename:    $att->name,
        contentType: $att->mimeType,
      ));
    }

    return $email;
  }
}
