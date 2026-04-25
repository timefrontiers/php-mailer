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

  public function __construct(SmtpConfig $config)
  {
    try {
      $this->transport = Transport::fromDsn($config->toDsn());
    } catch (\Throwable $e) {
      throw new DriverException(
        "[SMTP] Failed to initialise transport: {$e->getMessage()}",
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
        "[SMTP] Dispatch failed: {$e->getMessage()}",
        previous: $e,
      );
    }

    return $sent?->getMessageId() ?? '';
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
      $email->getHeaders()->addTextHeader((string) $name, (string) $value);
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
