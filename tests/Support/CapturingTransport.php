<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class CapturingTransport implements TransportInterface
{
  public ?RawMessage $message = null;
  public ?SentMessage $sent = null;
  public bool $throw = false;

  public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
  {
    if ($this->throw) {
      throw new \RuntimeException('secret transport failure');
    }
    $this->message = $message;
    $this->sent = new SentMessage($message, $envelope ?? Envelope::create($message));
    $this->sent->setMessageId('captured-message-id');
    return $this->sent;
  }

  public function __toString(): string
  {
    return 'capturing://';
  }
}
