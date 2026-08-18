<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer;

use TimeFrontiers\Mailer\Exception\ValidationException;

final readonly class ReplacementValue implements \JsonSerializable
{
  private function __construct(
    public string $value,
    public string $mode,
  ) {}

  public static function text(string $value): self { return new self($value, 'text'); }
  public static function trustedHtml(string $value): self { return new self($value, 'trusted-html'); }
  public static function header(string $value): self { return new self($value, 'header'); }

  public static function url(string $value): self
  {
    $parts = parse_url($value);
    if ($parts === false || !isset($parts['scheme']) || !in_array(strtolower((string) $parts['scheme']), ['https', 'http'], true)) {
      throw new ValidationException('Replacement URL must be an absolute HTTP or HTTPS URL.');
    }
    return new self($value, 'url');
  }

  /** @return array{value:string,mode:string} */
  public function jsonSerialize(): array
  {
    return ['value' => $this->value, 'mode' => $this->mode];
  }

  /** @param array{value?:mixed,mode?:mixed} $data */
  public static function fromArray(array $data): self
  {
    $value = (string) ($data['value'] ?? '');
    return match ((string) ($data['mode'] ?? 'text')) {
      'trusted-html' => self::trustedHtml($value),
      'header' => self::header($value),
      'url' => self::url($value),
      default => self::text($value),
    };
  }
}
