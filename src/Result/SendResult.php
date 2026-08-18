<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Result;

final readonly class SendResult implements \JsonSerializable
{
  /** @param list<RecipientDeliveryResult> $recipients */
  public function __construct(
    public array $recipients,
    public bool $localReconciliationRequired = false,
  ) {}

  public function acceptedCount(): int
  {
    return count(array_filter($this->recipients, static fn(RecipientDeliveryResult $r): bool => $r->status === 'accepted'));
  }

  public function allAccepted(): bool
  {
    return $this->recipients !== [] && $this->acceptedCount() === count($this->recipients);
  }

  public function hasUnknownOutcomes(): bool
  {
    return array_any($this->recipients, static fn(RecipientDeliveryResult $r): bool => $r->status === 'unknown');
  }

  /** @return array<string,mixed> */
  public function jsonSerialize(): array
  {
    return [
      'accepted' => $this->acceptedCount(),
      'total' => count($this->recipients),
      'unknown' => $this->hasUnknownOutcomes(),
      'localReconciliationRequired' => $this->localReconciliationRequired,
      'recipients' => $this->recipients,
    ];
  }
}
