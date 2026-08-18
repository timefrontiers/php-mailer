<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Rendering;

use TimeFrontiers\Mailer\ReplacementValue;
use TimeFrontiers\Mailer\Exception\ValidationException;

final class Renderer
{
  /**
   * @param array<string, string|ReplacementValue> $values
   */
  public static function replace(string $text, array $values, string $context, string $unresolvedPolicy): string
  {
    foreach ($values as $key => $raw) {
      if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $key) !== 1) {
        throw new ValidationException('Replacement keys must use letters, numbers, dots, underscores, or hyphens.');
      }
      $value = $raw instanceof ReplacementValue ? $raw : ReplacementValue::text((string) $raw);
      $replacement = self::forContext($value, $context);
      $text = str_replace('%{' . $key . '}', $replacement, $text);
    }

    if (preg_match('/%\{[A-Za-z0-9][A-Za-z0-9._-]*\}/', $text) === 1) {
      $text = match ($unresolvedPolicy) {
        'preserve' => $text,
        'empty' => (string) preg_replace('/%\{[A-Za-z0-9][A-Za-z0-9._-]*\}/', '', $text),
        default => throw new ValidationException('Rendered message contains unresolved replacement tokens.'),
      };
    }

    return $text;
  }

  private static function forContext(ReplacementValue $value, string $context): string
  {
    if ($context === 'header') {
      if (preg_match('/[\r\n\0]/', $value->value) === 1) {
        throw new ValidationException('Header replacement values may not contain control characters.');
      }
      return $value->value;
    }

    if ($context === 'html') {
      if ($value->mode === 'trusted-html') {
        return $value->value;
      }
      if ($value->mode === 'url') {
        return htmlspecialchars($value->value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
      }
      return htmlspecialchars($value->value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    return $value->value;
  }
}
