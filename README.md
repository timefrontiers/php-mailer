# TimeFrontiers PHP Mailer

Email delivery, templating, mailing lists, attachments, and a durable worker queue for the TimeFrontiers ecosystem.

## Requirements

- PHP 8.5+
- MySQL 8.0.29+ or MariaDB 10.6+
- `timefrontiers/php-sql-database ^1.1`
- `timefrontiers/php-database-object ^1.1.1`
- `timefrontiers/php-file ^1.1`
- `timefrontiers/php-validator ^1.1`
- Symfony Mailer 7.x

The package retains `HasErrors` through Database Object. `timefrontiers/php-instance-error` is an optional consumer-side suggestion. `timefrontiers/php-multiform` has no mailer persistence responsibility and is no longer a runtime dependency.

## Installation and database

```bash
composer require timefrontiers/php-mailer:^1.1.1
```

The 1.1 line starts at `1.1.1`; `1.1.0` was never tagged.

For a fresh installation, apply [`schema/schema.sql`](schema/schema.sql). Existing 1.0 installations must follow [`UPGRADING.md`](UPGRADING.md) and apply [`schema/migrations/1.1.0.sql`](schema/migrations/1.1.0.sql) while old workers are stopped.

## Boot configuration

Configuration is validated and frozen on the first `Config::set()` call. Runtime mutation is rejected so a worker cannot silently change the driver or limits of an existing process.

```php
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Driver\SmtpConfig;

Config::set(new Config(
    dbName: 'mailer',
    mailServer: 'https://mail.example.com',
    driver: new SmtpConfig(
        host: 'smtp.example.com',
        port: 587,
        username: $_ENV['SMTP_USER'],
        password: $_ENV['SMTP_PASSWORD'],
        encryption: 'required-tls',
        timeout: 30.0,
    ),
    unresolvedTokenPolicy: 'reject',
    maxRenderedBytes: 2 * 1024 * 1024,
    maxAttachments: 20,
    maxAttachmentBytes: 24 * 1024 * 1024,
    maxTotalAttachmentBytes: 30 * 1024 * 1024,
    queueLeaseSeconds: 300,
    queueMaxAttempts: 5,
    queueBaseBackoffSeconds: 60,
));
```

SMTP modes are explicit:

- `required-tls`: STARTTLS is mandatory.
- `opportunistic-tls`: use STARTTLS when advertised.
- `implicit-tls`: TLS from connection start, normally port 465.
- `none`: disables Symfony automatic TLS deliberately; use only for a trusted relay.
- `tls` and `ssl` remain aliases for `required-tls` and `implicit-tls`.

Credentials are percent-encoded in the internal DSN. Do not log `toDsn()`. Mailgun accepts only the `us` and `eu` regions and passes the region through Symfony's documented `region` option.

## Sending an email

The established API is preserved:

```php
use TimeFrontiers\Mailer\Email;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\RecipientType;
use TimeFrontiers\Mailer\ReplacementValue;

$sender = Profile::resolve($db, 'hello@example.com', 'Example');

$email = Email::make(
    conn: $db,
    sender: $sender,
    subject: 'Hello %{name}',
    body: '<p>Hello %{name}</p>',
);

$email
    ->addRecipient('Ada <ada@example.net>');
$email->addRecipient('team@example.net', RecipientType::CC);
$email->addRecipient('audit@example.net', RecipientType::BCC);
$email->addRecipient('support@example.com', RecipientType::REPLY_TO);

$ok = $email->send([
    'name' => 'Ada & Grace',
]);

$detail = $email->lastDeliveryResult();
```

`send(): bool` remains the compatibility result. `lastDeliveryResult()` exposes additive per-recipient statuses, provider identifiers, error codes, idempotency keys, unknown outcomes, and accepted/local-reconciliation state.

### Recipient semantics and privacy

v1.1 uses the explicit `individual` delivery mode. Every TO, CC, and BCC row receives exactly one independent provider message. CC/BCC lists are not repeated for every TO recipient. A BCC recipient is used in that message's envelope but is removed from the serialized wire headers by Symfony Mailer. Reply-To recipients are headers only and are not dispatched.

All envelope recipients are represented in internal delivery rows and logs. Do not expose BCC delivery data through a user-visible log endpoint.

## Rendering and trust

Replacement keys remain bare keys such as `name`; the renderer applies `%{name}`. Plain strings are HTML-escaped in HTML output. Explicit modes are available:

```php
$email->replace('summary', ReplacementValue::trustedHtml('<strong>Approved</strong>'));
$email->replace('portal', ReplacementValue::url('https://example.com/account'));
$email->replace('display-name', ReplacementValue::header('Ada'));
```

- `trustedHtml()` bypasses HTML escaping and must only contain application-owned content.
- `url()` accepts absolute HTTP/HTTPS URLs.
- header replacements reject CR, LF, and NUL.
- unresolved tokens default to rejection; `preserve` and `empty` are explicit configuration alternatives.
- per-call values are applied consistently to subject, HTML, and plain text.
- template and rendered-message sizes are bounded.

CommonMark conversion is not sanitization. `trustedTemplateHtml: true` allows raw HTML only because persisted templates are assumed to be trusted application content. Set it to false to strip raw HTML; applications accepting untrusted author input still need a dedicated HTML sanitizer and authorisation boundary.

## Attachments

```php
use TimeFrontiers\File\File;

$storedFile = File::findByCode('583...', $db);
$email->attach($storedFile);
```

Persisted attachments are linked immediately and rehydrated when an email is loaded. Queue acceptance also records an ordered attachment-reference snapshot, so later edits to the source email cannot change an accepted job. Workers hydrate that snapshot through php-file's `openReadStream()` contract, so local, S3, and MinIO objects use the same bounded path. Count, individual size, total size, MIME allow-list, stream failure, and metadata-length mismatch are enforced.

`attachRaw()` remains available for immediate compatibility sends but is deprecated for durable workflows. A transient filesystem path is rejected at queue time.

## Durable queue

The compatibility email queue API remains:

```php
$email->queue($db, $sender, priority: 5);

// Worker process. The sender argument is retained for source compatibility;
// persisted sender/driver snapshots are authoritative.
$delivered = \TimeFrontiers\Mailer\Email\Queue::processNext(
    conn: $db,
    sender: $workerCompatibilityProfile,
    limit: 25,
);
```

For standalone personalized batches:

```php
use TimeFrontiers\Mailer\Email\Queue;

$queue = Queue::make($db, $sender, 'Welcome %{name}', '<p>Hello %{name}</p>');
$queue->addRecipient('ada@example.net', ['name' => 'Ada']);
$queue->addRecipient('grace@example.net', ['name' => 'Grace']);
$queue->enqueue();
```

Queue guarantees:

- a conditional update atomically claims one eligible row;
- an exact affected-row count of one is required;
- claims contain a worker ID, lease expiry, and attempt number;
- workers renew and verify lease ownership immediately before each provider call, while recovery first takes over an expired lease atomically;
- expired leases are recovered with bounded exponential backoff;
- retry exhaustion becomes `dead_letter`;
- recipients have normalized independent statuses and retry schedules;
- delivery attempts are committed before provider dispatch;
- idempotency keys are stable per logical recipient;
- provider acceptance is recorded before logging or folder projection;
- a prepared/unknown request is quarantined as `reconciliation`, never selected as an ordinary retry;
- successful and failed recipients produce `partial` or `partial_dead_letter`, not a false all-sent state;
- driver configuration and sender identity are snapshotted when work is created.
- queued subject, rendered bodies, Reply-To headers, recipient rows, and attachment references are accepted atomically with the email's outbox transition.

Built-in Symfony SMTP and Mailgun transports do not expose an idempotency header. Their interrupted request is therefore classified as unknown and requires review. Injected providers may implement `IdempotentMailDriverInterface` to receive the stable key.

### Redacted bodies

An email created with `log_body: false` cannot be queued or reloaded for delivery because the stored body is `***redacted***`. This package does not claim to provide an encrypted payload store. Add such a store as a separately reviewed feature before enabling durable redacted messages.

## Mailing lists

Public mailing-list code prefix `218` and email code prefix `421` remain unchanged. v1.1 moves standing membership to `mailing_list_members`, whose non-null `mailing_list_id` makes `UNIQUE(mailing_list_id,address,type)` effective. Per-email recipients remain in `email_recipients`.

## Error boundary

Mailer entities continue to expose canonical `HasErrors` data. Public errors use stable, non-sensitive messages such as `transport_outcome_unknown` and never include raw SMTP/provider exceptions or a complete DSN. Consumers may install `timefrontiers/php-instance-error` for rank-aware extraction.

## Quality checks

```bash
composer lint
composer test-unit
composer test-integration
composer analyse
composer check
```

Integration tests require `MAILER_TEST_HOST`, `MAILER_TEST_USER`, `MAILER_TEST_PASSWORD`, and optionally `MAILER_TEST_PORT`. They create and remove only randomly named databases matching `php_mailer_<pid>_<random>_test`. CI provisions MySQL and exercises MySQLi and PDO against the same queue.

The suite skips only when the host or user variables are absent. Once database variables are supplied, connection, schema, or authentication failures fail the suite so CI cannot silently pass without integration coverage.

## Release policy

Do not create or move a `v1.1.0` tag until an independent audit verifies:

- concurrent claim behavior on both adapters;
- lease recovery and dead-letter handling;
- provider-accepted/local-failure reconciliation;
- partial-recipient retry and stable idempotency keys;
- redacted-body rejection and attachment reload/stream behavior;
- the migration and rollback runbook;
- a clean `composer check` with the integration suite actually executed.

Use [`INDEPENDENT-AUDIT.md`](INDEPENDENT-AUDIT.md) as the audit record and release checklist. A local `composer check` that reports skipped integration tests is not release evidence.

License: MIT. See [`LICENSE`](LICENSE).
