<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Mailer\Exception\ValidationException;
use TimeFrontiers\Mailer\Rendering\Renderer;
use TimeFrontiers\Mailer\ReplacementValue;

final class RendererTest extends TestCase
{
  public function testHtmlEscapesTextAndAllowsExplicitTrustedHtml(): void
  {
    self::assertSame(
      '&lt;b&gt;Alice&lt;/b&gt; <b>Admin</b>',
      Renderer::replace('%{name} %{role}', [
        'name' => '<b>Alice</b>',
        'role' => ReplacementValue::trustedHtml('<b>Admin</b>'),
      ], 'html', 'reject'),
    );
  }

  public function testPlainTextUsesTheSameCallSpecificValues(): void
  {
    self::assertSame('Hello Ada', Renderer::replace('Hello %{name}', ['name' => 'Ada'], 'plain', 'reject'));
  }

  public function testUnresolvedPoliciesAreExplicit(): void
  {
    self::assertSame('Hello %{name}', Renderer::replace('Hello %{name}', [], 'plain', 'preserve'));
    self::assertSame('Hello ', Renderer::replace('Hello %{name}', [], 'plain', 'empty'));
    $this->expectException(ValidationException::class);
    Renderer::replace('Hello %{name}', [], 'plain', 'reject');
  }

  public function testHeaderInjectionAndUnsafeUrlsAreRejected(): void
  {
    try {
      Renderer::replace('Hi %{name}', ['name' => "Alice\r\nBcc: victim@example.test"], 'header', 'reject');
      self::fail('Header injection should be rejected.');
    } catch (ValidationException) {
      self::assertTrue(true);
    }
    $this->expectException(ValidationException::class);
    ReplacementValue::url('javascript:alert(1)');
  }
}
