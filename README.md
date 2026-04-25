# timefrontiers/php-mailer

Email sending, templating, mailing lists, and delivery queuing for the TimeFrontiers ecosystem.

Supports Mailgun and native SMTP out of the box. Additional drivers can be added by implementing `MailDriverInterface`. Attachment support is provided via `timefrontiers/php-file` (persisted files) or raw filesystem paths (transient).

---

## Requirements

- PHP 8.2+
- MySQL 8.0+ / MariaDB 10.6+
- `timefrontiers/php-file ^1.0`
- `symfony/mailer ^7.0`

---

## Installation

```bash
composer require timefrontiers/php-mailer
```

---

## Database

Run `schema/msgservice.sql` to create the required tables in your database.

Migrating from `linktude/php-mailer`? Run `schema/migrate_lnk_to_tf.sql` inside your existing database — see [Migration](#migration) below.

---

## Bootstrap

Call `Config::set()` once at application startup (e.g. in your `bootstrap.php` or `require_once` entry point):

```php
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Driver\MailgunConfig;
use TimeFrontiers\Mailer\Driver\SmtpConfig;

// Mailgun
Config::set(new Config(
    dbName:   'msgservice',
    mailServer: 'mg.example.com',
    driver:   new MailgunConfig(
        domain: 'mg.example.com',
        apiKey: 'key-xxxxxxxxxxxx',
        region: 'us',   // 'us' or 'eu'
    ),
));

// — or — native SMTP
Config::set(new Config(
    dbName:   'msgservice',
    mailServer: 'smtp.example.com',
    driver:   new SmtpConfig(
        host:       'smtp.example.com',
        port:       587,
        username:   'user@example.com',
        password:   'secret',
        encryption: 'tls',   // 'tls' | 'ssl' | 'none'
    ),
));
```

---

## Sending an email

```php
use TimeFrontiers\Mailer\Email;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\RecipientType;

// 1. Resolve a sender profile (find-or-create by address)
$sender = Profile::resolve($conn, 'hello@example.com', 'Example', 'Team');

// 2. Create a draft
$email = Email::make(
    conn:    $conn,
    subject: 'Welcome to Example',
    body:    '<p>Hello %{name}, thanks for joining!</p>',
    user:    $currentUserCode,
);

// 3. Add recipients
$email->addRecipient($conn, 'alice@example.com', RecipientType::TO);
$email->addRecipient($conn, ['email' => 'bob@example.com', 'name' => 'Bob'], RecipientType::CC);

// 4. Register token replacements (applied at render time)
$email->replace('%{name}', 'Alice');

// 5. Send immediately
$driverConfig = Config::get()->driver;
$email->send($conn, $sender, $driverConfig);
```

---

## Markdown body

Pass `isMd: true` to `Email::make()` or `setBody()` — the body is converted from Markdown to HTML at render time via `league/commonmark`.

```php
$email = Email::make($conn, 'Hello', '# Welcome\n\nThanks for joining!', $user, isMd: true);
```

---

## Templates

Templates are outer HTML shells with a `%{body}` token where the email body is injected.

```php
use TimeFrontiers\Mailer\Email\Template;

$template = Template::make($conn, 'Default Shell', '<html>...%{body}...</html>', $user);
$email->setTemplate($template);
```

---

## Attachments

```php
use TimeFrontiers\File\File;

// Persisted — backed by timefrontiers/php-file; recorded in email_attachments
$file = File::load($conn, $fileCode);
$email->attach($file);

// Transient — raw filesystem path; not stored in email_attachments
$email->attachRaw('/tmp/invoice.pdf', 'application/pdf', 'Invoice.pdf');
```

---

## Queuing for deferred delivery

```php
// Move to OUTBOX and create pending log entries
$email->queue($conn, $sender, priority: 3);

// In your queue runner — load OUTBOX emails and send:
$pending = Email::findBySql('SELECT * FROM :db:.:tbl: WHERE folder = ?', ['OUTBOX']);
foreach ($pending as $e) {
    $e->send($conn, $sender, Config::get()->driver);
}
```

---

## Mailing lists

```php
use TimeFrontiers\Mailer\MailingList;

// Create a list
$list = MailingList::make($conn, 'Newsletter', $userCode);

// Add members
$list->addMember($conn, ['email' => 'alice@example.com', 'name' => 'Alice']);
$list->addMember($conn, 'bob@example.com');

// Load and iterate at send time
$list    = MailingList::load($conn, $listCode);
$members = $list->recipients(RecipientType::TO);

foreach ($members as $member) {
    $email = Email::make($conn, 'Newsletter — April', $body, $userCode);
    $email->addRecipient($conn, $member->address, RecipientType::TO, mlistId: $list->id);
    $email->replace('%{name}', $member->displayName() ?: 'Subscriber');
    $email->send($conn, $sender, Config::get()->driver);
}
```

---

## Delivery log

Each send creates an `EmailLog` row per TO recipient:

```php
use TimeFrontiers\Mailer\Log\EmailLog;

$log = EmailLog::loadById($conn, $logId);
$log->markRead();     // recipient opened the email
```

---

## Adding a new driver

1. Create a typed config class implementing `DriverConfigInterface`:

```php
final class SendGridConfig implements DriverConfigInterface {
    public function __construct(public readonly string $apiKey) {}
    public function driverName(): string { return 'sendgrid'; }
    public function toDsn(): string { return "sendgrid+api://{$this->apiKey}@default"; }
}
```

2. Create the driver implementing `MailDriverInterface`.
3. Add one arm to `DriverFactory::fromConfig()`.

---

## Code prefixes

| Entity       | Prefix | Example            |
|--------------|--------|--------------------|
| Email        | `421`  | `421394827163058`  |
| Email thread | `497`  | `497018263748291`  |
| Template     | `429`  | `429847392016453`  |
| Mailing list | `218`  | `218736402918374`  |

---

## Migration

Run the migration script inside the database that holds your existing tables:

```bash
mysql -u root -p your_database < schema/migrate_lnk_to_tf.sql
```

What the migration does, in order:
- Adds `id BIGINT UNSIGNED AUTO_INCREMENT` PK to `emails`, `email_templates`, and `mailing_lists` (which used `code CHAR(14)` as PK); sequential ids are back-filled before the PK swap
- Widens `code` from `CHAR(14)` to `CHAR(15)` across all tables
- Renames `mailing_lists.title` → `name`; drops `description`
- Adds `_updated` to `email_templates` and `mailing_lists`
- Migrates `email_recipients`: replaces char-code columns `email` / `mlist` with integer FK columns `email_id` / `mlist_id`; back-fills via JOIN; tightens `type` to ENUM
- Migrates `email_attachments`: replaces `email` (code) with `email_id` (int FK); renames `fid` → `file_id`
- Migrates `email_log`: replaces `email` (code) with `email_id` (int FK); renames `sender` → `sender_id` and `recipient` → `recipient_id`
- Adds `is_md`, `template_id`, `sender_id` to `emails`; back-fills `template_id`; drops `template`, `header`, `origin`, `replace_pattern`, `thread`

---

## License

MIT — see [LICENSE](LICENSE).
