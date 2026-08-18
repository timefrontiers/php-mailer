<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Integration;

use TimeFrontiers\Mailer\Email;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\Tests\Support\FakeStoredFile;

final class EmailDeliveryTest extends DatabaseTestCase
{
  /**
   * send() and queue() are documented as returning bool. Every reachable
   * validation rejection must surface as false plus a HasErrors entry, never
   * as an exception escaping to `if (!$email->send())`.
   */
  public function testValidationRejectionsReturnFalseInsteadOfThrowing(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');

    $noRecipients = Email::make($this->mysqli, $sender, 'No recipients', '<p>x</p>');
    self::assertFalse($noRecipients->send());
    self::assertTrue($noRecipients->hasErrors('send'));

    $noRecipientsQueued = Email::make($this->mysqli, $sender, 'No recipients', '<p>x</p>');
    self::assertFalse($noRecipientsQueued->queue($this->mysqli, $sender));
    self::assertTrue($noRecipientsQueued->hasErrors('queue'));

    $headerInjection = Email::make($this->mysqli, $sender, 'Subject %{v}', '<p>%{v}</p>');
    $headerInjection->addRecipient('to@example.test');
    self::assertFalse($headerInjection->send(['v' => "a\r\nBcc: sneak@example.test"]));
    self::assertTrue($headerInjection->hasErrors('send'));
    self::assertSame([], $this->driver->messages, 'A rejected send must dispatch nothing.');
  }

  /**
   * A persisted email whose sender or driver snapshot is missing - the state a
   * freshly migrated v1.0 row can be in - must also reject as false, not throw.
   */
  public function testMissingPersistedSnapshotsRejectAsFalse(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');

    $noDriver = Email::make($this->mysqli, $sender, 'No driver', '<p>x</p>');
    $noDriver->addRecipient('to@example.test');
    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`emails` SET `driver`=NULL WHERE `id`=?", [$noDriver->id]);
    $reloaded = Email::load($this->mysqli, (string) $noDriver->code);
    self::assertInstanceOf(Email::class, $reloaded);
    self::assertFalse($reloaded->send());
    self::assertTrue($reloaded->hasErrors('send'));

    $noSender = Email::make($this->mysqli, $sender, 'No sender', '<p>x</p>');
    $noSender->addRecipient('to@example.test');
    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`emails` SET `sender_snapshot`=NULL WHERE `id`=?", [$noSender->id]);
    $reloadedSender = Email::load($this->mysqli, (string) $noSender->code);
    self::assertInstanceOf(Email::class, $reloadedSender);
    self::assertFalse($reloadedSender->send());
    self::assertTrue($reloadedSender->hasErrors('send'));

    self::assertSame([], $this->driver->messages, 'Neither rejection may dispatch.');

    // queue() resolves the driver snapshot in its own prologue, before the
    // shared delivery guard, so it needs its own coverage. A v1.0 row loaded
    // during the upgrade window carries no snapshot and reaches exactly here.
    $noDriverQueued = Email::make($this->mysqli, $sender, 'No driver queued', '<p>x</p>');
    $noDriverQueued->addRecipient('to@example.test');
    $this->execute($this->mysqli, "UPDATE `{$this->database}`.`emails` SET `driver`=NULL WHERE `id`=?", [$noDriverQueued->id]);
    $reloadedQueued = Email::load($this->mysqli, (string) $noDriverQueued->code);
    self::assertInstanceOf(Email::class, $reloadedQueued);
    self::assertFalse($reloadedQueued->queue($this->mysqli, $sender));
    self::assertTrue($reloadedQueued->hasErrors('queue'));

    $queued = $this->one(
      $this->mysqli,
      "SELECT COUNT(*) AS `c` FROM `{$this->database}`.`email_queue` WHERE `subject`=?",
      ['No driver queued'],
    );
    self::assertSame(0, (int) $queued['c'], 'A rejected queue must not enqueue.');
  }

  public function testToCcAndBccAreEachDeliveredExactlyOnceAndUseCallValuesInBothBodies(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');
    $email = Email::make($this->mysqli, $sender, 'Hello %{name}', '<p>Hello %{name}</p>');
    $email->addRecipient('to@example.test');
    $email->addRecipient('cc@example.test', RecipientType::CC);
    $email->addRecipient('bcc@example.test', RecipientType::BCC);

    self::assertTrue($email->send(['name' => '<Ada>']));
    self::assertCount(3, $this->driver->messages);
    self::assertEqualsCanonicalizing(
      ['to@example.test', 'cc@example.test', 'bcc@example.test'],
      array_column($this->driver->messages, 'recipient'),
    );
    foreach ($this->driver->messages as $message) {
      self::assertSame('Hello <Ada>', $message['subject']);
      self::assertSame('<p>Hello &lt;Ada&gt;</p>', $message['html']);
      self::assertSame('Hello <Ada>', $message['text']);
      self::assertArrayNotHasKey('Bcc', $message['headers']);
      self::assertArrayNotHasKey('Cc', $message['headers']);
    }
    $log = $this->one($this->mysqli, "SELECT COUNT(*) AS `total` FROM `{$this->database}`.`email_log` WHERE `email_id`=? AND `sent`=1", [$email->id]);
    self::assertSame(3, (int) $log['total']);
  }

  public function testUnknownImmediateOutcomeUsesSameLedgerAndBlocksDuplicateRetry(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');
    $email = Email::make($this->mysqli, $sender, 'Unknown', '<p>Unknown</p>');
    $email->addRecipient('unknown@example.test');
    $this->driver->failUnknown('unknown@example.test');

    self::assertFalse($email->send());
    self::assertFalse($email->send());
    self::assertCount(1, $this->driver->keysByAddress['unknown@example.test']);
    self::assertTrue($email->lastDeliveryResult()?->hasUnknownOutcomes() ?? false);
  }

  public function testProviderAcceptanceFollowedByLoggingFailureDoesNotBecomeRetry(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');
    $email = Email::make($this->mysqli, $sender, 'Accepted', '<p>Accepted</p>');
    $email->addRecipient('accepted@example.test');
    $this->execute($this->mysqli, "DROP TABLE `{$this->database}`.`email_log`");

    self::assertTrue($email->send());
    self::assertTrue($email->lastDeliveryResult()?->localReconciliationRequired ?? false);
    self::assertTrue($email->send());
    self::assertCount(1, $this->driver->keysByAddress['accepted@example.test']);
    $attempt = $this->one($this->mysqli, "SELECT `status`,`provider_message_id` FROM `{$this->database}`.`email_delivery_attempts` WHERE `email_id`=?", [$email->id]);
    self::assertSame('accepted_local_failure', $attempt['status']);
    self::assertNotEmpty($attempt['provider_message_id']);
  }

  public function testRedactedBodyAndTransientAttachmentCannotBeQueued(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');
    $redacted = Email::make($this->mysqli, $sender, 'Redacted', '<p>Secret</p>', log_body: false);
    $redacted->addRecipient('recipient@example.test');
    self::assertFalse($redacted->queue($this->mysqli, $sender));

    $path = tempnam(sys_get_temp_dir(), 'mailer-');
    self::assertNotFalse($path);
    file_put_contents($path, 'ephemeral');
    try {
      $transient = Email::make($this->mysqli, $sender, 'Attachment', '<p>Attached</p>');
      $transient->addRecipient('recipient2@example.test');
      $transient->attachRaw($path, 'text/plain', 'ephemeral.txt');
      self::assertFalse($transient->queue($this->mysqli, $sender));
    } finally {
      @unlink($path);
    }
  }

  public function testQueueHydratesTheImmutablePersistedAttachmentSnapshot(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');
    $first = new FakeStoredFile($this->mysqli, 101, 'remote-one', 'one.txt');
    $later = new FakeStoredFile($this->mysqli, 102, 'remote-two', 'two.txt');
    $this->files = [101 => $first, 102 => $later];
    $email = Email::make($this->mysqli, $sender, 'Attachment snapshot', '<p>Attached</p>');
    $email->addRecipient('snapshot@example.test');
    $email->attach($first);
    self::assertTrue($email->queue($this->mysqli, $sender));

    // A later edit to the source email must not change the accepted queue payload.
    $email->attach($later);
    self::assertSame(1, \TimeFrontiers\Mailer\Email\Queue::processNext($this->mysqli, $sender, 1));
    self::assertSame(1, $this->driver->messages[0]['attachments']);
  }

  public function testQueueFreezesTheRenderedSubjectBeforeRuntimeReplacementStateIsLost(): void
  {
    $sender = Profile::resolve($this->mysqli, 'sender@example.test', 'Email', 'Sender');
    $email = Email::make($this->mysqli, $sender, 'Hello %{name}', '<p>Hello %{name}</p>');
    $email->addRecipient('snapshot-subject@example.test');
    $email->replace('name', 'Queue');
    self::assertTrue($email->queue($this->mysqli, $sender));
    self::assertSame(1, \TimeFrontiers\Mailer\Email\Queue::processNext($this->mysqli, $sender, 1));
    self::assertSame('Hello Queue', $this->driver->messages[0]['subject']);
  }
}
