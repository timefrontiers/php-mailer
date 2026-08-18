<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Integration;

/**
 * Exercises the real v1.0 -> 1.1 upgrade.
 *
 * This case deliberately starts from the retained `schema/1.0/schema.sql`
 * rather than the current 1.1 schema, so that it proves the migration itself
 * and not merely that re-running it against an already-migrated database is
 * idempotent.
 */
final class MigrationTest extends DatabaseTestCase
{
  protected function schemaFile(): string
  {
    return dirname(__DIR__, 2) . '/schema/1.0/schema.sql';
  }

  public function testUpgradesARealV10DatabaseAndIsIdempotent(): void
  {
    $pdo = $this->schemaPdo();

    // Precondition: this really is a v1.0 database.
    foreach (['email_queue_recipients', 'email_queue_attachments', 'email_delivery_attempts', 'mailing_list_members'] as $table) {
      self::assertSame([], $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(), "{$table} must not exist before the migration.");
    }

    $this->seedLegacyData();

    $migration = dirname(__DIR__, 2) . '/schema/migrations/1.1.0.sql';
    $this->runSqlScript($pdo, $migration);
    $this->runSqlScript($pdo, $migration);

    // New tables exist.
    foreach (['email_queue_recipients', 'email_queue_attachments', 'email_delivery_attempts', 'mailing_list_members'] as $table) {
      self::assertNotSame([], $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(), "{$table} must exist after the migration.");
    }

    // Legacy recipient JSON is expanded in order, with type preserved.
    $rows = $pdo->query(
      'SELECT `ordinal`,`address`,`type` FROM `email_queue_recipients` WHERE `queue_id` = 1 ORDER BY `ordinal`'
    )->fetchAll(\PDO::FETCH_ASSOC);
    self::assertSame([
      ['ordinal' => 1, 'address' => 'first@example.test', 'type' => 'to'],
      ['ordinal' => 2, 'address' => 'second@example.test', 'type' => 'to'],
    ], array_map(
      static fn(array $r): array => ['ordinal' => (int) $r['ordinal'], 'address' => $r['address'], 'type' => $r['type']],
      $rows,
    ));

    // A job whose recipient JSON cannot be parsed must not fabricate
    // recipients, and must not stay runnable.
    self::assertSame(
      [],
      $pdo->query('SELECT `id` FROM `email_queue_recipients` WHERE `queue_id` = 2')->fetchAll(),
      'Malformed recipient JSON must not produce recipients.',
    );
    self::assertNotSame(
      'pending',
      (string) $pdo->query('SELECT `status` FROM `email_queue` WHERE `id` = 2')->fetchColumn(),
      'A job with unparseable recipients must not remain runnable.',
    );

    // A row left mid-flight by the deployment must be quarantined, not resent.
    self::assertSame(
      'reconciliation',
      (string) $pdo->query('SELECT `status` FROM `email_queue` WHERE `id` = 3')->fetchColumn(),
      'A processing row at deployment time must be quarantined.',
    );

    // The NULL-key standing-list uniqueness hole is closed: three identical
    // NULL-email_id members collapse to one, with the originals retained.
    self::assertSame(
      1,
      (int) $pdo->query('SELECT COUNT(*) FROM `mailing_list_members`')->fetchColumn(),
    );
    self::assertSame(
      3,
      (int) $pdo->query('SELECT COUNT(*) FROM `email_recipients_v10_list_members`')->fetchColumn(),
      'The pre-migration rows must be retained as evidence.',
    );

    // Duplicate logs are deduplicated, with the originals retained.
    self::assertSame(
      1,
      (int) $pdo->query('SELECT COUNT(*) FROM `email_log`')->fetchColumn(),
    );
    self::assertSame(
      1,
      (int) $pdo->query('SELECT COUNT(*) FROM `email_log_v10_duplicates`')->fetchColumn(),
    );
  }

  private function seedLegacyData(): void
  {
    $db = $this->database;
    $this->execute($this->mysqli, "INSERT INTO `{$db}`.`mailer_profiles` (`address`,`name`) VALUES ('legacy-sender@example.test','Legacy')");

    // queue_id 1 — well-formed legacy recipient JSON, mixed contact shapes.
    $this->insertQueueRow(1, 'pending', json_encode([
      ['contact' => 'First <first@example.test>', 'replaceValues' => ['name' => 'First']],
      ['contact' => ['email' => 'second@example.test', 'name' => 'Second'], 'replaceValues' => ['name' => 'Second']],
    ], JSON_THROW_ON_ERROR));

    // queue_id 2 — recipient payload that is valid JSON but carries no
    // usable contact. Syntactically broken JSON cannot be seeded: the v1.0
    // `recipients` column is a JSON type and the engine rejects it outright,
    // so an uninterpretable shape is the reachable corruption case.
    $this->insertQueueRow(2, 'pending', '[{"unexpected":"shape"}]');

    // queue_id 3 — mid-flight when the deployment happened.
    $this->insertQueueRow(3, 'processing', json_encode([
      ['contact' => 'third@example.test', 'replaceValues' => []],
    ], JSON_THROW_ON_ERROR));

    // A standing list whose members carry NULL email_id. The v1.0 unique key
    // is (email_id, address, type), and NULLs never collide there, so the same
    // member can be stored repeatedly — the hole brief section 3 calls out.
    $this->execute($this->mysqli,
      "INSERT INTO `{$db}`.`mailing_lists` (`code`,`user`,`name`) VALUES ('218000000001','legacy','Standing')");
    for ($i = 0; $i < 3; $i++) {
      $this->execute(
        $this->mysqli,
        "INSERT INTO `{$db}`.`email_recipients` (`email_id`,`mlist_id`,`type`,`address`,`name`) VALUES (NULL,1,'to',?,?)",
        ['member@example.test', 'Member'],
      );
    }

    // One email with a real recipient, logged twice.
    $this->execute($this->mysqli,
      "INSERT INTO `{$db}`.`emails` (`code`,`user`,`sender_id`,`subject`,`body`) "
      . "VALUES ('421000000001','legacy',1,'Logged','<p>Logged</p>')");
    $this->execute($this->mysqli,
      "INSERT INTO `{$db}`.`email_recipients` (`email_id`,`type`,`address`,`name`) VALUES (1,'to','dup@example.test','Dup')");
    $recipientId = (int) $this->one($this->mysqli,
      "SELECT `id` FROM `{$db}`.`email_recipients` WHERE `email_id`=1 AND `address`='dup@example.test'")['id'];
    for ($i = 0; $i < 2; $i++) {
      $this->execute($this->mysqli,
        "INSERT INTO `{$db}`.`email_log` (`email_id`,`sender_id`,`recipient_id`,`sent`) VALUES (1,1,?,1)",
        [$recipientId],
      );
    }
  }

  private function insertQueueRow(int $id, string $status, string $recipientsJson): void
  {
    $this->execute(
      $this->mysqli,
      "INSERT INTO `{$this->database}`.`email_queue` (`id`,`status`,`sender_id`,`subject`,`body`,`recipients`,`driver`) "
      . "VALUES (?,?,1,'Legacy','<p>Legacy</p>',?,'smtp')",
      [$id, $status, $recipientsJson],
    );
  }
}
