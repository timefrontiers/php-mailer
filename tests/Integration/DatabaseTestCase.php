<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Driver\DriverConfigInterface;
use TimeFrontiers\Mailer\Persistence\DatabaseGateway;
use TimeFrontiers\Mailer\Tests\Support\ConfigFactory;
use TimeFrontiers\Mailer\Tests\Support\FakeMailDriver;
use TimeFrontiers\Mailer\Tests\Support\FakeStoredFile;
use TimeFrontiers\SQLDatabase;

abstract class DatabaseTestCase extends TestCase
{
  protected SQLDatabase $mysqli;
  protected SQLDatabase $pdo;
  protected FakeMailDriver $driver;
  /** @var list<DriverConfigInterface> */
  protected array $resolvedConfigs = [];
  protected string $database;
  /** @var array<int,FakeStoredFile> */
  protected array $files = [];
  private ?\PDO $admin = null;

  protected function setUp(): void
  {
    $host = getenv('MAILER_TEST_HOST') ?: '';
    $user = getenv('MAILER_TEST_USER') ?: '';
    $password = getenv('MAILER_TEST_PASSWORD') ?: '';
    $port = (int) (getenv('MAILER_TEST_PORT') ?: 3306);
    if ($host === '' || $user === '') {
      self::markTestSkipped('Set MAILER_TEST_HOST and MAILER_TEST_USER to run database integration tests.');
    }
    $this->database = 'php_mailer_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
    try {
      $this->admin = new \PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
      );
      $this->admin->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      $schemaConnection = new \PDO(
        "mysql:host={$host};port={$port};dbname={$this->database};charset=utf8mb4",
        $user,
        $password,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
      );
      $this->runSqlScript($schemaConnection, $this->schemaFile());
      $this->mysqli = new SQLDatabase($host, $user, $password, $this->database, true, (string) $port);
      $this->pdo = SQLDatabase::pdo('mysql', $host, $port, $this->database, $user, $password);
    } catch (\Throwable $exception) {
      $this->dropDatabase();
      throw new \RuntimeException('Configured integration database is unavailable: ' . $exception->getMessage(), 0, $exception);
    }

    $this->driver = new FakeMailDriver();
    Config::reset();
    Config::set(ConfigFactory::create(
      dbName: $this->database,
      driverFactory: function (DriverConfigInterface $config): FakeMailDriver {
        $this->resolvedConfigs[] = $config;
        return $this->driver;
      },
      fileResolver: function (SQLDatabase $connection, int $fileId): FakeStoredFile {
        if (!isset($this->files[$fileId])) {
          throw new \RuntimeException('Synthetic file was not registered.');
        }
        return $this->files[$fileId];
      },
    ));
  }

  /**
   * Schema this case starts from. Overridden by MigrationTest so that it
   * begins at the retained v1.0 schema and exercises the real upgrade path.
   */
  protected function schemaFile(): string
  {
    return dirname(__DIR__, 2) . '/schema/schema.sql';
  }

  protected function tearDown(): void
  {
    Config::reset();
    $this->dropDatabase();
  }

  /** @return array<string,mixed> */
  protected function one(SQLDatabase $conn, string $sql, array $params = []): array
  {
    $row = DatabaseGateway::fetchOne($conn, $sql, $params);
    self::assertIsArray($row);
    return $row;
  }

  protected function execute(SQLDatabase $conn, string $sql, array $params = []): void
  {
    self::assertNotFalse(DatabaseGateway::execute($conn, $sql, $params));
  }

  protected function runSqlScript(\PDO $pdo, string $path): void
  {
    $sql = file_get_contents($path);
    if ($sql === false) {
      throw new \RuntimeException('Schema is unavailable.');
    }
    $delimiter = ';';
    $statement = '';
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
      if (trim($statement) === '' && (trim($line) === '' || str_starts_with(ltrim($line), '--'))) {
        continue;
      }
      if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
        $delimiter = $matches[1];
        continue;
      }
      $statement .= $line . "\n";
      if (!str_ends_with(rtrim($statement), $delimiter)) {
        continue;
      }
      $statementToRun = trim(substr(rtrim($statement), 0, -strlen($delimiter)));
      if ($statementToRun !== '') {
        $query = $pdo->query($statementToRun);
        if ($query !== false) {
          do {
            if ($query->columnCount() > 0) {
              $query->fetchAll();
            }
          } while ($query->nextRowset());
          $query->closeCursor();
        }
      }
      $statement = '';
    }
    if (trim($statement) !== '') {
      throw new \RuntimeException('Schema contains an unterminated statement.');
    }
  }

  protected function schemaPdo(): \PDO
  {
    return new \PDO(
      'mysql:host=' . (getenv('MAILER_TEST_HOST') ?: '127.0.0.1')
      . ';port=' . (getenv('MAILER_TEST_PORT') ?: '3306')
      . ';dbname=' . $this->database . ';charset=utf8mb4',
      getenv('MAILER_TEST_USER') ?: '',
      getenv('MAILER_TEST_PASSWORD') ?: '',
      [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
    );
  }

  private function dropDatabase(): void
  {
    if ($this->admin === null || !isset($this->database) || preg_match('/^php_mailer_[0-9]+_[a-f0-9]{8}_test$/D', $this->database) !== 1) {
      return;
    }
    $this->admin->exec("DROP DATABASE IF EXISTS `{$this->database}`");
    $this->admin = null;
  }
}
