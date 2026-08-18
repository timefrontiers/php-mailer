# Changelog

## 1.1.1 — 2026-08-18

First release of the 1.1 line. `1.1.0` was prepared but never tagged: an
independent audit rejected that candidate, and the fixes below landed before
any tag existed, so the line starts at `1.1.1`.

### Fixed after audit

- `send()` and `queue()` returned their documented `bool` again instead of
  throwing. Validation *and* state resolution are now guarded in both entry
  points, so a v1.0 row loaded during the upgrade window — carrying no sender
  or driver snapshot — rejects as `false` with a recorded error rather than an
  uncaught exception. `ConfigException` is deliberately re-thrown: a broken
  bootstrap is a deployment fault, not a per-message outcome.
- The TO/CC/BCC delivery test called a PHPUnit method that does not exist and
  had therefore never executed. BCC privacy, CC absence, per-message
  rendering, and the three-row log assertion are now genuinely covered.
- `timefrontiers/php-database-object` is required at `^1.1.1`; the lock had
  pinned `v1.1.0`, whose `_create()` raises a `TypeError` on every PDO-MySQL
  insert into a typed integer primary key.
- `MigrationTest` started from the 1.1 schema, so it only ever proved
  idempotency. `schema/1.0/schema.sql` is now retained verbatim from `v1.0.9`
  and the test upgrades a real, populated v1.0 database — which is also what
  CI now exercises on MySQL 8.4.

### Added

- Conditional single-row queue claims, worker leases, recovery, backoff, and dead-letter states.
- Lease ownership renewal before dispatch and atomic expired-lease recovery takeover.
- Normalized per-recipient queue delivery and committed provider-attempt ledgers.
- Stable idempotency keys, unknown-outcome quarantine, and accepted/local-failure reconciliation.
- Additive typed send and queue results.
- Immutable sender and built-in driver snapshots.
- Stream-based php-file attachments with count, size, MIME, and read limits.
- Immutable ordered attachment snapshots for accepted queue jobs.
- Context-aware replacements and explicit unresolved-token policy.
- PHPUnit, PHPStan level 8, parallel linting, MySQL-backed MySQLi/PDO tests, and CI.
- Idempotent 1.0-to-1.1 migration and rollback/operator runbook.
- `schema/1.0/schema.sql`, the v1.0.9 schema retained verbatim so the
  migration suite upgrades a real v1.0 database rather than an already-1.1 one.

### Changed

- PHP floor is 8.5.
- Database Object, Validator, and php-file requirements are `^1.1`.
- TO/CC/BCC semantics are explicit individual delivery with no repeated CC/BCC lists.
- Standing mailing-list members use a dedicated non-null parent table.
- SMTP TLS modes and Mailgun regions are validated explicitly.
- Configuration is frozen after bootstrap.

### Deprecated

- Local-path `attachRaw()` for durable workflows.
- Queue sender/driver overrides; persisted snapshots are authoritative.

### Removed

- Unused direct runtime requirements on php-multiform and php-instance-error. Instance Error remains suggested for consumers.
