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
use TimeFrontiers\Mailer\Exception\UnknownDeliveryException;
use TimeFrontiers\Mailer\RecipientType;

/**
 * Mailgun driver — dispatches via the Mailgun HTTP API using
 * symfony/mailgun-mailer as the transport.
 *
 * Requires: symfony/mailer, symfony/mailgun-mailer, symfony/http-client.
 */
final class MailgunDriver implements MailDriverInterface
{
  private readonly TransportInterface $transport;

  public function __construct(MailgunConfig $config, ?TransportInterface $transport = null)
  {
    if ($transport !== null) {
      $this->transport = $transport;
      return;
    }
    try {
      $this->transport = Transport::fromDsn($config->toDsn());
    } catch (\Throwable $e) {
      throw new DriverException('Mailgun transport configuration is invalid.', previous: $e);
    }
  }

  /**
   * @param array<string,string|list<string>> $headers
   * @param list<Attachment> $attachments
   */
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
      throw new UnknownDeliveryException('Mailgun delivery outcome is unknown; reconcile before retrying.', previous: $e);
    }

    $msgId = $sent?->getMessageId() ?? '';
    if ($msgId === '') {
      throw new UnknownDeliveryException('Mailgun accepted a message without a usable message identifier; reconciliation is required.');
    }

    return $msgId;
  }

  // -------------------------------------------------------------------------
  // Internal helpers
  // -------------------------------------------------------------------------

  /**
   * @param array<string,string|list<string>> $headers
   * @param list<Attachment> $attachments
   */
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
      ->subject($subject)
      ->html($bodyHtml)
      ->text($bodyText);

    $address = new Address($recipient->address ?? '', $recipient->displayName());
    match ($recipient->recipientType()) {
      RecipientType::TO => $email->to($address),
      RecipientType::CC => $email->cc($address),
      RecipientType::BCC => $email->bcc($address),
      RecipientType::REPLY_TO => throw new DriverException('Reply-To entries are not delivery recipients.'),
    };

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
