<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Persistence;

use TimeFrontiers\SQLDatabase;

/** Typed access to the SQLDatabase facade's adapter-compatible methods. */
final class DatabaseGateway
{
  /**
   * @param list<mixed> $params
   * @phpstan-impure Executes a database statement and changes adapter state.
   */
  public static function execute(SQLDatabase $conn, string $sql, array $params = []): \mysqli_result|\PDOStatement|bool
  {
    return $conn->getInstance()->execute($sql, $params);
  }

  /**
   * @param list<mixed> $params
   * @return list<array<string,mixed>>|false
   */
  public static function fetchAll(SQLDatabase $conn, string $sql, array $params = []): array|false
  {
    $rows = $conn->getInstance()->fetchAll($sql, $params);
    if ($rows === false) {
      return false;
    }
    $normalized = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        throw new \UnexpectedValueException('Database adapter returned a non-array result row.');
      }
      $normalizedRow = [];
      foreach ($row as $key => $value) {
        if (is_string($key)) {
          $normalizedRow[$key] = $value;
        }
      }
      $normalized[] = $normalizedRow;
    }
    return $normalized;
  }

  /**
   * @param list<mixed> $params
   * @return array<string,mixed>|false
   */
  public static function fetchOne(SQLDatabase $conn, string $sql, array $params = []): array|false
  {
    $row = $conn->getInstance()->fetchOne($sql, $params);
    if ($row === false) {
      return false;
    }
    $normalized = [];
    foreach ($row as $key => $value) {
      if (is_string($key)) {
        $normalized[$key] = $value;
      }
    }
    return $normalized;
  }

  public static function insertId(SQLDatabase $conn): int|string
  {
    return $conn->getInstance()->insertId();
  }

  /** @phpstan-impure Reads the result of the most recently executed statement. */
  public static function affectedRows(SQLDatabase $conn): int
  {
    return $conn->getInstance()->affectedRows();
  }
}
