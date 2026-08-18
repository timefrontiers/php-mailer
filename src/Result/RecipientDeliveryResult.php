<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Result;

final readonly class RecipientDeliveryResult implements \JsonSerializable
{
  public function __construct(
    public string $address,
    public string $type,
    public string $status,
    public ?string $providerMessageId = null,
    public ?string $errorCode = null,
    public ?string $idempotencyKey = null,
  ) {}

  /** @return array<string,string|null> */
  public function jsonSerialize(): array
  {
    return get_object_vars($this);
  }
}
