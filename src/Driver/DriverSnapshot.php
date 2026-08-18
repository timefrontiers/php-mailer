<?php

declare(strict_types=1);

namespace TimeFrontiers\Mailer\Driver;

use TimeFrontiers\Mailer\Exception\ConfigException;

final class DriverSnapshot
{
  /** @return array{driver:string,config:array<string,mixed>} */
  public static function capture(DriverConfigInterface $config): array
  {
    return match (true) {
      $config instanceof SmtpConfig => [
        'driver' => 'smtp',
        'config' => [
          'host' => $config->host,
          'port' => $config->port,
          'username' => $config->username,
          'password' => $config->password,
          'encryption' => $config->encryption,
          'timeout' => $config->timeout,
        ],
      ],
      $config instanceof MailgunConfig => [
        'driver' => 'mailgun',
        'config' => [
          'domain' => $config->domain,
          'apiKey' => $config->apiKey,
          'region' => $config->region,
        ],
      ],
      default => throw new ConfigException('Queued delivery requires a snapshot-capable built-in driver configuration.'),
    };
  }

  /** @param array<string,mixed> $config */
  public static function restore(string $driver, array $config): DriverConfigInterface
  {
    return match ($driver) {
      'smtp' => new SmtpConfig(
        host: (string) ($config['host'] ?? ''),
        port: (int) ($config['port'] ?? 587),
        username: (string) ($config['username'] ?? ''),
        password: (string) ($config['password'] ?? ''),
        encryption: (string) ($config['encryption'] ?? 'required-tls'),
        timeout: (float) ($config['timeout'] ?? 30.0),
      ),
      'mailgun' => new MailgunConfig(
        domain: (string) ($config['domain'] ?? ''),
        apiKey: (string) ($config['apiKey'] ?? ''),
        region: (string) ($config['region'] ?? 'us'),
      ),
      default => throw new ConfigException('Unknown persisted mail driver: ' . $driver),
    };
  }
}
