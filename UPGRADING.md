# Upgrading php-mailer from 1.0.x to 1.1.1

This migration was independently audited and accepted before release. See
`dev/php-mailer-v1.1-audit.md` in the Linktude workspace for the full record.

This package releases the 1.1 line as **`1.1.1`**; `1.1.0` was never tagged.
Require `^1.1.1`.

## Before migration

1. Stop every 1.0 mailer and queue worker.
2. Take and verify a restorable database backup.
3. Record every `email_queue.status = 'processing'` row for operator review.
4. Confirm the deployment uses PHP 8.5+, SQL Database 1.1, Database Object 1.1, Validator 1.1, and php-file 1.1.
5. Review database encryption-at-rest and access controls because sender and driver snapshots contain operational configuration. Never copy snapshots into logs or public APIs.

## Apply the migration

Run [`schema/migrations/1.1.0.sql`](schema/migrations/1.1.0.sql) in the mailer database using an account permitted to create/alter tables, indexes, constraints, and temporary migration procedures.

The migration is repeatable and performs these operations:

- adds immutable sender, driver, delivery-mode, claim, lease, retry, and reconciliation fields;
- creates normalized queue recipient and delivery-attempt tables;
- creates ordered queue attachment snapshots and backfills them from each queued email's attachment links;
- migrates valid legacy recipient JSON in original array order;
- moves standing mailing-list members to a non-null membership table;
- preserves duplicate legacy log rows in `email_log_v10_duplicates` before consolidating them for the new uniqueness rule;
- marks legacy `processing` rows as `reconciliation`;
- marks jobs without a complete driver snapshot as `reconciliation` rather than guessing from the worker default.
- materializes legacy outbox emails as reconciliation-only jobs and records outbox rows without senders in `email_queue_migration_exceptions`.

Malformed recipient JSON or invalid legacy addresses are not silently dispatched. Compare source JSON counts with `email_queue_recipients` and resolve exceptions manually.

## Operator handling of deployment-time jobs

Rows marked `legacy_in_flight_requires_review` may already have reached the provider. Inspect provider events and SMTP logs using the original queue time, sender, recipient, and provider metadata. Then choose one explicit outcome:

- provider accepted: record the provider message ID and mark the recipient/job accepted;
- provider definitely did not accept: create a reviewed new delivery attempt;
- outcome cannot be established: leave the job in reconciliation or close it as an operator-visible unknown outcome.

Never convert unknown rows to `pending` in bulk. A retry is safe only when the provider supports reuse of the same idempotency key or an operator proves non-acceptance.

## Application compatibility changes

- Existing `Email::make/load`, fluent setters, `addRecipient()`, `attach()`, `send(): bool`, `queue(): bool`, `Profile::resolve()`, and mailing-list entry points remain.
- `Email::lastDeliveryResult()` and `Queue::lastDispatchResult()` provide additive detail.
- Queue worker sender/driver overrides are deprecated; stored snapshots win.
- Delivery mode is explicitly `individual`: each TO/CC/BCC row gets one message.
- `attachRaw()` cannot be queued.
- `log_body: false` emails cannot be queued or reloaded for delivery without a future encrypted payload store.
- plain replacement strings are HTML-escaped; use `ReplacementValue::trustedHtml()` only for trusted application HTML.
- unresolved tokens reject by default.
- `Config::set()` is call-once and frozen.
- standing mailing-list membership now lives in `mailing_list_members`.
- direct `php-multiform` and `php-instance-error` runtime dependencies were removed; Instance Error remains an optional consumer suggestion.

## Verification after migration

Before starting workers:

```sql
SELECT status, COUNT(*) FROM email_queue GROUP BY status;
SELECT last_error_code, COUNT(*) FROM email_queue WHERE reconciliation_required = 1 GROUP BY last_error_code;
SELECT error_code, COUNT(*) FROM email_queue_migration_exceptions GROUP BY error_code;
SELECT COUNT(*) FROM email_queue_recipients;
SELECT COUNT(*) FROM email_queue_attachments;
SELECT status, COUNT(*) FROM email_delivery_attempts GROUP BY status;
```

Run `composer check` with a dedicated database. Confirm the integration suite reports executed assertions rather than skipped tests. Start one worker, verify claims and provider acceptance, then increase concurrency gradually while monitoring:

- expired leases;
- reconciliation jobs;
- unknown attempts;
- partial/dead-letter recipients;
- acceptance/log projection failures.

## Rollback

Application rollback and schema rollback are separate decisions.

1. Stop v1.1 workers.
2. Preserve all `email_delivery_attempts`, normalized recipient rows, provider IDs, unknown outcomes, and reconciliation decisions in an immutable export.
3. Roll application code back to the prior immutable release.
4. Do **not** allow the 1.0 worker to process rows created or touched by v1.1; it cannot interpret leases, partial outcomes, or acceptance records safely.
5. Restore the verified pre-migration database backup for a complete rollback, or keep the additive schema and quarantine all non-final jobs while a reviewed forward fix is prepared.

Dropping v1.1 columns/tables in place is not a safe operational rollback because it destroys idempotency and reconciliation evidence. Never resend a `processing`, `prepared`, `unknown`, `accepted_local_failure`, or `reconciliation` row merely because older code cannot represent it.
