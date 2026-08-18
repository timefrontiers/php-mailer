<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
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
 * Native SMTP driver — dispatches via Symfony Mailer's SMTP transport.
 *
 * Supports STARTTLS (port 587), implicit TLS / SMTPS (port 465), and
 * plaintext (port 25) depending on SmtpConfig::$encryption.
 *
 * Requires: symfony/mailer.
 */
final class SmtpDriver implements MailDriverInterface
{
  private readonly TransportInterface $transport;

  public function __construct(SmtpConfig $config, ?TransportInterface $transport = null)
  {
    if ($transport !== null) {
      $this->transport = $transport;
      return;
    }
    try {
      $resolved = Transport::fromDsn($config->toDsn());
      if ($resolved instanceof SmtpTransport && $resolved->getStream() instanceof SocketStream) {
        $resolved->getStream()->setTimeout($config->timeout);
      }
      $this->transport = $resolved;
    } catch (\Throwable $e) {
      throw new DriverException('SMTP transport configuration is invalid.', previous: $e);
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
      throw new UnknownDeliveryException('SMTP delivery outcome is unknown; reconcile before retrying.', previous: $e);
    }

    $messageId = $sent?->getMessageId() ?? '';
    if ($messageId === '') {
      throw new UnknownDeliveryException('SMTP accepted a message without a usable message identifier; reconciliation is required.');
    }
    return $messageId;
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
