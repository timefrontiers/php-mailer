<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the shipped SQL against engine-specific syntax.
 *
 * `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` and its siblings are MariaDB
 * extensions that MySQL rejects with a parse error. Because the local audit
 * database is MariaDB, a regression here passes every local gate and only
 * surfaces on the MySQL CI job. This test catches it without a database.
 */
final class SchemaPortabilityTest extends TestCase
{
  /**
   * MariaDB-only `IF [NOT] EXISTS` clauses on ALTER TABLE sub-commands.
   * `CREATE TABLE IF NOT EXISTS`, `DROP TABLE/PROCEDURE IF EXISTS`, and
   * `IF NOT EXISTS (SELECT ...)` inside a routine are portable and allowed.
   */
  private const MARIADB_ONLY = '/\b(?:ADD|MODIFY|CHANGE)\s+COLUMN\s+IF\s+(?:NOT\s+)?EXISTS'
    . '|\bADD\s+(?:INDEX|KEY|CONSTRAINT|UNIQUE)\s+IF\s+(?:NOT\s+)?EXISTS'
    . '|\bDROP\s+(?:COLUMN|INDEX|KEY|CONSTRAINT|FOREIGN\s+KEY)\s+IF\s+EXISTS/i';

  /** @return array<string, array{string}> */
  public static function sqlFileProvider(): array
  {
    $root = dirname(__DIR__, 2);
    $files = array_merge(
      glob($root . '/schema/*.sql') ?: [],
      glob($root . '/schema/migrations/*.sql') ?: [],
      glob($root . '/schema/1.0/*.sql') ?: [],
    );
    self::assertNotEmpty($files);

    $cases = [];
    foreach ($files as $file) {
      $cases[basename(dirname($file)) . '/' . basename($file)] = [$file];
    }

    return $cases;
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('sqlFileProvider')]
  public function testShippedSqlAvoidsMariaDbOnlySyntax(string $file): void
  {
    $sql = file_get_contents($file);
    self::assertIsString($sql);

    // Strip line comments so explanatory prose cannot trip the check.
    $stripped = preg_replace('/^\s*--.*$/m', '', $sql);
    self::assertIsString($stripped);

    preg_match_all(self::MARIADB_ONLY, $stripped, $matches);
    self::assertSame(
      [],
      $matches[0],
      basename($file) . ' uses MariaDB-only ALTER syntax that MySQL rejects: '
      . implode(', ', array_unique($matches[0])),
    );
  }

  public function testTheDetectorActuallyMatchesTheSyntaxItGuardsAgainst(): void
  {
    // A vacuous guard is worse than none, so prove the pattern fires.
    foreach ([
      'ALTER TABLE `t` ADD COLUMN IF NOT EXISTS `c` INT;',
      'ALTER TABLE `t` DROP COLUMN IF EXISTS `c`;',
      'ALTER TABLE `t` ADD INDEX IF NOT EXISTS `i` (`c`);',
    ] as $mariaDbOnly) {
      self::assertSame(1, preg_match(self::MARIADB_ONLY, $mariaDbOnly), $mariaDbOnly);
    }

    foreach ([
      'CREATE TABLE IF NOT EXISTS `t` (`id` INT);',
      'DROP PROCEDURE IF EXISTS `p`;',
      'DROP TABLE IF EXISTS `t`;',
      'IF NOT EXISTS (SELECT 1 FROM information_schema.columns) THEN',
    ] as $portable) {
      self::assertSame(0, preg_match(self::MARIADB_ONLY, $portable), $portable);
    }
  }
}
