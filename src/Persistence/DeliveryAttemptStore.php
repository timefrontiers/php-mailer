<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Persistence;

use TimeFrontiers\Mailer\Config;
use TimeFrontiers\SQLDatabase;

final class DeliveryAttemptStore
{
  public function __construct(private readonly SQLDatabase $conn) {}

  public static function idempotencyKey(string $scope): string
  {
    return hash('sha256', 'timefrontiers/php-mailer:v1.1:' . $scope);
  }

  public function prepare(
    ?int $emailId,
    ?int $recipientId,
    ?int $queueRecipientId,
    int $attemptNo,
    string $idempotencyKey,
    string $driver,
    ?string $workerId = null,
  ): int {
    if ($this->conn->inTransaction()) {
      throw new \LogicException('Provider delivery attempts must be prepared outside an ambient transaction so the record commits before dispatch.');
    }
    $db = Config::get()->dbName;
    $result = DatabaseGateway::execute($this->conn,
      "INSERT INTO `{$db}`.`email_delivery_attempts` "
      . '(`email_id`,`recipient_id`,`queue_recipient_id`,`attempt_no`,`idempotency_key`,`driver`,`worker_id`,`status`,`started_at`) '
      . "VALUES (?,?,?,?,?,?,?,'prepared',CURRENT_TIMESTAMP)",
      [$emailId, $recipientId, $queueRecipientId, $attemptNo, $idempotencyKey, $driver, $workerId],
    );
    if ($result === false || $this->conn->affectedRows() !== 1) {
      throw new \RuntimeException('Delivery attempt could not be committed before provider dispatch.');
    }
    $id = (int) DatabaseGateway::insertId($this->conn);
    if ($id < 1) {
      throw new \RuntimeException('Delivery attempt insert did not return an identifier.');
    }
    return $id;
  }

  public function nextImmediateAttempt(int $emailId, int $recipientId): int
  {
    $db = Config::get()->dbName;
    $row = DatabaseGateway::fetchOne($this->conn,
      "SELECT COALESCE(MAX(`attempt_no`),0)+1 AS `next_attempt` FROM `{$db}`.`email_delivery_attempts` WHERE `email_id`=? AND `recipient_id`=?",
      [$emailId, $recipientId],
    );
    if (!is_array($row)) {
      throw new \RuntimeException('Delivery attempt sequence could not be read.');
    }
    return max(1, (int) $row['next_attempt']);
  }

  /** @return array{status:string,provider_message_id:?string,idempotency_key:string}|null */
  public function latestImmediateOutcome(int $emailId, int $recipientId): ?array
  {
    $db = Config::get()->dbName;
    $row = DatabaseGateway::fetchOne($this->conn,
      "SELECT `status`,`provider_message_id`,`idempotency_key` FROM `{$db}`.`email_delivery_attempts` WHERE `email_id`=? AND `recipient_id`=? ORDER BY `attempt_no` DESC,`id` DESC LIMIT 1",
      [$emailId, $recipientId],
    );
    if ($row === false) {
      return null;
    }
    return [
      'status' => (string) $row['status'],
      'provider_message_id' => isset($row['provider_message_id']) ? (string) $row['provider_message_id'] : null,
      'idempotency_key' => (string) $row['idempotency_key'],
    ];
  }

  public function markAccepted(int $attemptId, string $providerMessageId): bool
  {
    if ($providerMessageId === '') {
      return false;
    }
    return $this->transition(
      $attemptId,
      "`status`='accepted',`provider_message_id`=?,`accepted_at`=CURRENT_TIMESTAMP,`completed_at`=CURRENT_TIMESTAMP",
      [$providerMessageId],
    );
  }

  public function markFailed(int $attemptId, string $errorCode): bool
  {
    return $this->transition(
      $attemptId,
      "`status`='failed',`error_code`=?,`completed_at`=CURRENT_TIMESTAMP",
      [$errorCode],
    );
  }

  public function markUnknown(int $attemptId, string $errorCode): bool
  {
    return $this->transition(
      $attemptId,
      "`status`='unknown',`error_code`=?,`completed_at`=CURRENT_TIMESTAMP",
      [$errorCode],
    );
  }

  public function markLocalFailure(int $attemptId, string $errorCode): bool
  {
    return $this->transition(
      $attemptId,
      "`status`='accepted_local_failure',`error_code`=?,`completed_at`=CURRENT_TIMESTAMP",
      [$errorCode],
      ['accepted'],
    );
  }

  /**
   * @param list<mixed> $params
   * @param list<string> $from
   */
  private function transition(int $attemptId, string $set, array $params, array $from = ['prepared']): bool
  {
    $db = Config::get()->dbName;
    $placeholders = implode(',', array_fill(0, count($from), '?'));
    $result = DatabaseGateway::execute($this->conn,
      "UPDATE `{$db}`.`email_delivery_attempts` SET {$set} WHERE `id`=? AND `status` IN ({$placeholders})",
      [...$params, $attemptId, ...$from],
    );
    return $result !== false && $this->conn->affectedRows() === 1;
  }
}
