<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Integration;

use TimeFrontiers\Mailer\Driver\SmtpConfig;
use TimeFrontiers\Mailer\Email\Queue;
use TimeFrontiers\Mailer\Profile;

final class QueueSafetyTest extends DatabaseTestCase
{
  public function testMySQLiAndPdoWorkersCannotClaimTheSameJob(): void
  {
    $sender = $this->sender($this->mysqli);
    $queue = Queue::make($this->mysqli, $sender, 'Race test', '<p>Race body</p>');
    $queue->addRecipient('race@example.test')->enqueue();

    self::assertNotNull(Queue::claimNext($this->mysqli, 'worker-mysqli-0001'));
    self::assertNull(Queue::claimNext($this->pdo, 'worker-pdo-0000002'));
    $row = $this->one($this->pdo, "SELECT `status`,`worker_id`,`attempts` FROM `{$this->database}`.`email_queue` WHERE `id`=?", [$queue->id]);
    self::assertSame('processing', $row['status']);
    self::assertSame('worker-mysqli-0001', $row['worker_id']);
    self::assertSame(1, (int) $row['attempts']);
  }

  public function testExpiredLeaseRecoveryBacksOffAndEventuallyDeadLetters(): void
  {
    $sender = $this->sender($this->mysqli);
    $queue = Queue::make($this->mysqli, $sender, 'Lease test', '<p>Lease body</p>');
    $queue->addRecipient('lease@example.test')->enqueue();
    self::assertNotNull(Queue::claimNext($this->mysqli, 'worker-expired-0001'));
    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`email_queue` SET `lease_expires_at`='2000-01-01 00:00:00' WHERE `id`=?", [$queue->id]);

    self::assertSame(1, Queue::recoverExpiredLeases($this->pdo));
    self::assertSame('retry', $this->one($this->pdo, "SELECT `status` FROM `{$this->database}`.`email_queue` WHERE `id`=?", [$queue->id])['status']);

    $dead = Queue::make($this->mysqli, $sender, 'Dead letter', '<p>Dead body</p>');
    $dead->max_attempts = 1;
    self::assertTrue($dead->save());
    $dead->addRecipient('dead@example.test')->enqueue();
    self::assertNotNull(Queue::claimNext($this->mysqli, 'worker-expired-0002'));
    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`email_queue` SET `lease_expires_at`='2000-01-01 00:00:00' WHERE `id`=?", [$dead->id]);
    self::assertSame(1, Queue::recoverExpiredLeases($this->pdo));
    self::assertSame('dead_letter', $this->one($this->pdo, "SELECT `status` FROM `{$this->database}`.`email_queue` WHERE `id`=?", [$dead->id])['status']);
  }

  public function testPartialRecipientFailureRetriesIndependentlyWithStableKey(): void
  {
    $sender = $this->sender($this->mysqli);
    $this->driver->failDefinitively('retry@example.test');
    $queue = Queue::make(
      $this->mysqli,
      $sender,
      'Hello %{name}',
      '<p>Hello %{name}</p>',
      driver: new SmtpConfig('snapshot.example.test', 2526, 'snapshot-user', 'snapshot-pass'),
    );
    $queue->addRecipient('ok@example.test', ['name' => 'Ok']);
    $queue->addRecipient('retry@example.test', ['name' => 'Retry']);
    self::assertTrue($queue->enqueue());
    self::assertSame(1, $queue->dispatch());
    self::assertSame('partial', $this->one($this->mysqli, "SELECT `status` FROM `{$this->database}`.`email_queue` WHERE `id`=?", [$queue->id])['status']);

    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`email_queue` SET `next_attempt_at`=CURRENT_TIMESTAMP WHERE `id`=?", [$queue->id]);
    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`email_queue_recipients` SET `next_attempt_at`=CURRENT_TIMESTAMP WHERE `queue_id`=? AND `status`='retry'", [$queue->id]);
    $pdoSender = Profile::resolve($this->pdo, 'worker-default@example.test', 'Worker', 'Default');
    self::assertSame(1, Queue::processNext($this->pdo, $pdoSender, 1));
    self::assertSame('sent', $this->one($this->mysqli, "SELECT `status` FROM `{$this->database}`.`email_queue` WHERE `id`=?", [$queue->id])['status']);
    self::assertCount(2, $this->driver->keysByAddress['retry@example.test']);
    self::assertSame($this->driver->keysByAddress['retry@example.test'][0], $this->driver->keysByAddress['retry@example.test'][1]);
    self::assertInstanceOf(SmtpConfig::class, $this->resolvedConfigs[0]);
    self::assertSame(2526, $this->resolvedConfigs[0]->port);
    self::assertSame(['sender@example.test', 'sender@example.test'], array_column($this->driver->messages, 'sender'));
  }

  public function testUnknownOutcomeIsQuarantinedAndNeverNormallyReclaimed(): void
  {
    $sender = $this->sender($this->mysqli);
    $this->driver->failUnknown('unknown@example.test');
    $queue = Queue::make($this->mysqli, $sender, 'Unknown', '<p>Unknown</p>');
    $queue->addRecipient('unknown@example.test')->enqueue();
    self::assertSame(0, $queue->dispatch());
    $row = $this->one($this->mysqli, "SELECT `status`,`reconciliation_required`,`last_error_code` FROM `{$this->database}`.`email_queue` WHERE `id`=?", [$queue->id]);
    self::assertSame('reconciliation', $row['status']);
    self::assertSame(1, (int) $row['reconciliation_required']);
    self::assertSame('unknown_provider_outcome', $row['last_error_code']);
    self::assertNull(Queue::claimNext($this->pdo, 'worker-review-0001'));
    self::assertCount(1, $this->driver->keysByAddress['unknown@example.test']);
  }

  public function testDatabaseStateRejectsAStaleBuilderAfterQueueAcceptance(): void
  {
    $sender = $this->sender($this->mysqli);
    $queue = Queue::make($this->mysqli, $sender, 'Immutable recipients', '<p>Body</p>');
    $queue->addRecipient('first@example.test')->enqueue();

    $stale = new Queue($this->pdo);
    $stale->id = $queue->id;
    $stale->status = 'building';
    $this->expectException(\LogicException::class);
    $stale->addRecipient('too-late@example.test');
  }

  private function sender(\TimeFrontiers\SQLDatabase $connection): Profile
  {
    return Profile::resolve($connection, 'sender@example.test', 'Queue', 'Sender');
  }
}
