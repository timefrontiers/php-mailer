# php-mailer v1.1 independent audit handoff

## Status and release constraint

This working tree is an **unreleased v1.1 candidate** based on commit `7091e7dc3e823f4ea6e03ce5c8e3c5dced9a4c6a` (`master`, after tag `v1.0.9`). No commit, `v1.1.0` tag, package publication, push, or deployment is part of this handoff.

The auditor must remain independent of the implementation work. Audit acceptance must be recorded before anyone creates or moves a `v1.1.0` tag. A partial pass, a suite containing skipped integration tests, or a review limited to static analysis is not approval to release.

## Candidate scope

The candidate changes these security- and delivery-critical boundaries:

- atomic queue claims, lease ownership/renewal, expired-lease takeover, bounded retry, partial outcomes, and dead-letter states;
- normalized per-recipient state and a pre-provider delivery-attempt ledger with stable idempotency keys;
- unknown-outcome quarantine and provider-accepted/local-persistence reconciliation;
- individual TO/CC/BCC delivery semantics, Reply-To-only headers, BCC privacy, and unique logging;
- immutable sender, driver, rendered content, recipient, header, and attachment snapshots for queued work;
- php-file stream hydration and attachment count, MIME, individual, total, and stream-read bounds;
- context-aware rendering, unresolved-token policy, and trusted-template controls;
- explicit SMTP TLS modes, socket timeout, credential encoding, Mailgun region validation, and injectable fakes;
- the idempotent 1.0-to-1.1 migration and operator rollback/reconciliation runbook;
- PHP 8.5 and coordinated TimeFrontiers 1.1 dependencies.

Public compatibility entry points remain `Email::make/load`, fluent setters, `addRecipient()`, `attach()`, `send(): bool`, `queue(): bool`, `Profile::resolve()`, and mailing-list operations. Public entity prefixes remain `421` for email and `218` for mailing lists.

## Implementation-side evidence (2026-08-13)

The following checks passed in the candidate working tree on PHP 8.5.7:

```text
composer validate --strict   PASS
composer audit --locked      PASS — no advisories
composer lint                PASS — 44 files
composer test-unit           PASS — 16 tests, 34 assertions
composer analyse             PASS — PHPStan level 8, 32 source files
git diff --check             PASS
```

Implementation-side `composer.lock` SHA-256: `42a66cd84cb9dc77c99c6c2d99b09d931376d6776d4b139ce0411e0bfc085afe`.

`composer check` exits successfully, but on this workstation its 12-test database suite is skipped because database variables are intentionally absent. A direct configured integration attempt against `127.0.0.1:3306` failed because the local MariaDB server requested the unsupported `auth_gssapi_client` authentication plugin. This is an unresolved evidence gap, not a test pass. Once database variables are supplied, infrastructure failures fail rather than skip the suite. The GitHub Actions workflow provisions MySQL 8.4, but its result must be obtained and reviewed independently.

## Mandatory automated audit

From a clean checkout of the candidate, install exactly the locked dependencies and run:

```bash
composer install --no-interaction
composer validate --strict
composer audit --locked
composer check
composer test-integration -- --display-skipped
git diff --check
```

Acceptance requires all commands to exit zero and the final integration command to report **zero skipped tests**. Configure `MAILER_TEST_HOST`, `MAILER_TEST_PORT`, `MAILER_TEST_USER`, and `MAILER_TEST_PASSWORD` for an isolated database principal allowed to create and drop only audit databases. Confirm the tests create names matching `php_mailer_<pid>_<random>_test` and remove only those databases.

Run the database suite with both SQL Database facades exercised in the same run:

- MySQLi worker/connection paths;
- PDO worker/connection paths.

Run the migration twice against a populated copy of a v1.0 schema on MySQL 8.4 or later. Because the package also documents MariaDB 10.6 support, repeat the migration audit on MariaDB 10.6 or later before accepting that compatibility claim. Compare row counts and sample payloads before/after, including malformed legacy recipient JSON, standing list members, duplicate logs, outbox mail, queued attachments, and deployment-time `processing` rows.

## Mandatory behavioral review

The auditor should independently prove each item rather than relying only on the implementation tests:

1. Race two workers against one eligible job and verify exactly one conditional claim succeeds.
2. Expire a lease before dispatch, after recipient claim, and during a simulated provider request; confirm ownership loss prevents a second ordinary send and uncertain work becomes reconciliation-visible.
3. Exhaust definite failures and verify independent recipient backoff and terminal dead-letter state without resending accepted recipients.
4. Simulate provider acceptance followed by delivery-attempt, recipient-projection, log, and folder persistence failures. Verify accepted mail never becomes an ordinary retry.
5. Simulate an interrupted provider request. Verify the original idempotency key remains stable and the job cannot be reclaimed without explicit reconciliation.
6. Deliver multiple TO, CC, and BCC recipients. Verify one provider call and one log per envelope recipient, no repeated CC/BCC lists, no BCC wire header, and Reply-To is not dispatched.
7. Change process defaults and the worker compatibility sender after queue acceptance. Verify the stored sender and driver snapshot remains authoritative.
8. Change an email's subject, body, recipients, Reply-To values, and attachments after queue acceptance. Verify the accepted queue payload does not change.
9. Exercise local, S3-like, and MinIO-like php-file streams, missing objects, read failures, metadata-length mismatch, count limits, MIME limits, individual limits, and aggregate limits.
10. Verify call-specific replacements in subject, HTML, and plain text; HTML escaping; trusted HTML; header injection rejection; URL validation; unresolved-token modes; and render limits.
11. Verify all SMTP modes at the constructed Symfony transport, including cleartext `none`, opportunistic STARTTLS, required STARTTLS, implicit TLS, credential encoding, and the configured socket timeout. Verify Mailgun US/EU endpoints and rejection of other regions.
12. Verify `Config::set()` is call-once, hydrated entities retain their supplied connection, and database failure is distinguishable from not-found.

## Migration and operations review

Review `schema/migrations/1.1.0.sql` and `UPGRADING.md` together. In particular:

- old workers are stopped and a restorable backup is verified before migration;
- every legacy `processing` job and every job without a complete transport snapshot is quarantined;
- `email_queue_migration_exceptions`, `email_recipients_v10_list_members`, and `email_log_v10_duplicates` retain review evidence;
- normalized recipient order/type and ordered attachment references are preserved;
- standing mailing-list uniqueness uses a non-null parent key;
- rollback never discards attempt, provider-ID, unknown-outcome, or reconciliation evidence;
- sender/driver snapshots are protected as secrets by database encryption-at-rest, least-privilege access, backup controls, and log redaction.

## Release decision record

The independent auditor should append or attach a record containing:

- candidate commit SHA and `composer.lock` hash;
- database engine/version and PHP version;
- complete command output or CI URLs;
- explicit confirmation of zero skipped integration tests;
- migration before/after evidence and reconciliation counts;
- findings, required fixes, and retest evidence;
- final decision: `APPROVED FOR v1.1.0 TAG` or `REJECTED`;
- auditor identity and UTC timestamp.

Until that record says `APPROVED FOR v1.1.0 TAG`, v1.1 remains unreleased.
