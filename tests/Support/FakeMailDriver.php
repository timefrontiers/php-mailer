<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Support;

use TimeFrontiers\Mailer\Driver\IdempotentMailDriverInterface;
use TimeFrontiers\Mailer\Email\Recipient;
use TimeFrontiers\Mailer\Exception\DriverException;
use TimeFrontiers\Mailer\Exception\UnknownDeliveryException;
use TimeFrontiers\Mailer\Profile;

final class FakeMailDriver implements IdempotentMailDriverInterface
{
  /** @var array<string,list<string>> */
  public array $keysByAddress = [];
  /** @var list<array<string,mixed>> */
  public array $messages = [];
  /** @var array<string,int> */
  public array $definiteFailures = [];
  /** @var array<string,int> */
  public array $unknownFailures = [];

  public function failDefinitively(string $address, int $times = 1): void
  {
    $this->definiteFailures[$address] = $times;
  }

  public function failUnknown(string $address, int $times = 1): void
  {
    $this->unknownFailures[$address] = $times;
  }

  public function send(
    Profile $sender,
    Recipient $recipient,
    string $subject,
    string $bodyHtml,
    string $bodyText,
    array $headers = [],
    array $attachments = [],
  ): string {
    return $this->sendIdempotently('non-idempotent-test', $sender, $recipient, $subject, $bodyHtml, $bodyText, $headers, $attachments);
  }

  public function sendIdempotently(
    string $idempotencyKey,
    Profile $sender,
    Recipient $recipient,
    string $subject,
    string $bodyHtml,
    string $bodyText,
    array $headers = [],
    array $attachments = [],
  ): string {
    $address = (string) $recipient->address;
    $this->keysByAddress[$address][] = $idempotencyKey;
    if (($this->unknownFailures[$address] ?? 0) > 0) {
      $this->unknownFailures[$address]--;
      throw new UnknownDeliveryException('synthetic unknown outcome containing secret-provider-detail');
    }
    if (($this->definiteFailures[$address] ?? 0) > 0) {
      $this->definiteFailures[$address]--;
      throw new DriverException('synthetic rejection containing secret-provider-detail');
    }
    $this->messages[] = [
      'sender' => $sender->address,
      'recipient' => $address,
      'type' => $recipient->type,
      'subject' => $subject,
      'html' => $bodyHtml,
      'text' => $bodyText,
      'headers' => $headers,
      'attachments' => count($attachments),
    ];
    return 'fake-' . substr(hash('sha256', $idempotencyKey), 0, 24);
  }
}
