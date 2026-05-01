# timefrontiers/php-mailer

Email sending, templating, bulk queuing, and delivery logging for the TimeFrontiers ecosystem.

Supports **Mailgun** and native **SMTP** out of the box. Additional drivers can be added by implementing `MailDriverInterface`. Attachment support is provided via `timefrontiers/php-file` (persisted files) or raw filesystem paths (transient).

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

Run `schema/schema.sql` against your target database to create all required tables:

```
mailer_profiles     — verified sender identities
email_templates     — reusable HTML/Markdown shells
mailing_lists       — named recipient groups
emails              — core email records (DRAFT → OUTBOX → SENT)
email_recipients    — TO / CC / BCC / Reply-To per email or list
email_attachments   — maps emails to php-file File records
email_log           — per-recipient delivery tracking
email_queue         — bulk personalized send queue (see Queue)
```

> **Note:** `email_queue` references `mailer_profiles` via FK. Run the full schema in order, or use the two-step approach documented in the schema file comments.

---

## Bootstrap

Call `Config::set()` **once** at application startup before using any mailer class:

```php
use TimeFrontiers\Mailer\Config;
use TimeFrontiers\Mailer\Driver\MailgunConfig;
use TimeFrontiers\Mailer\Driver\SmtpConfig;

// Mailgun
Config::set(new Config(
    dbName:     'msgservice',
    mailServer: 'https://mail.example.com',
    driver: new MailgunConfig(
        domain: 'mg.example.com',
        apiKey: 'key-xxxxxxxxxxxx',
        region: 'us',   // 'us' or 'eu'
    ),
));

// — or — native SMTP
Config::set(new Config(
    dbName:     'msgservice',
    mailServer: 'https://mail.example.com',
    driver: new SmtpConfig(
        host:       'smtp.example.com',
        port:       587,
        username:   'user@example.com',
        password:   'secret',
        encryption: 'tls',   // 'tls' | 'ssl' | 'none'
    ),
));
```

### Registering templates in Config

Associate template codes and token variable lists with named message types. This lets `Email::make()` resolve the correct template automatically.

```php
Config::set(new Config(
    dbName:     'msgservice',
    mailServer: 'https://mail.example.com',
    driver:     new MailgunConfig(...),
    templates: [
        'default' => [
            'templateCode' => '42912345678',          // email_templates.code
            'replaceVars'  => ['user-name', 'user-surname'],
        ],
        'order-confirm' => [
            'templateCode' => '42999999999',
            'replaceVars'  => ['order-id', 'total', 'user-name'],
        ],
    ],
));
```

---

## Sending an email

### `Email::make()` signature

```php
Email::make(
    SQLDatabase              $conn,
    Profile                  $sender,
    string                   $subject,
    string                   $body,
    string                   $user         = 'SYSTEM',
    ?string                  $message_type = 'default',
    int|string|Template|null $template     = null,
    ?DriverConfigInterface   $driver       = null,
    bool                     $log_body     = true,
): Email
```

| Parameter | Description |
|-----------|-------------|
| `$sender` | `Profile` instance — the From address. |
| `$message_type` | Used to look up `Config::templates` for template + token defaults. Pass `null` to skip Config template lookup entirely (no template, no replaceVars seeding). |
| `$template` | Explicit override: pass an `int` id, `string` code, or `Template` instance. `null` = use config lookup. |
| `$driver` | Transport override. `null` = use `Config::get()->driver`. |
| `$log_body` | `false` → body saved as `***redacted***` in DB (use for OTP / sensitive codes). Email is still delivered correctly. |

### Basic example

```php
use TimeFrontiers\Mailer\Email;
use TimeFrontiers\Mailer\Profile;
use TimeFrontiers\Mailer\RecipientType;

// Resolve a sender profile (find-or-create by address)
$sender = Profile::resolve($conn, 'hello@example.com', 'Example', 'Team');

// Create a draft — template resolved from Config['default']
$email = Email::make(
    $conn,
    $sender,
    'Welcome to Example, %{user-name}!',
    '<p>Hi %{user-name}, thanks for joining.</p>',
    $currentUserCode,   // platform user code or 'SYSTEM'
    'default',          // message_type — matches Config::templates key
);

// Add recipients (no $conn needed — uses internally stored connection)
$email->addRecipient('alice@example.com', RecipientType::TO);
$email->addRecipient(['email' => 'bob@example.com', 'name' => 'Bob'], RecipientType::CC);
$email->addRecipient('replies@example.com', RecipientType::REPLY_TO);

// Send — bare-key token map applied to subject + body
$email->send([
    'user-name'    => 'Alice',
    'user-surname' => 'Smith',
]);
```

### Token replacements

Tokens in subject and body use the `%{key}` syntax. Pass bare keys (without `%{}`) to `send()`:

```php
// Body: "<p>Hi %{user-name} %{user-surname},</p>"
$email->send([
    'user-name'    => 'Alice',
    'user-surname' => 'Smith',
]);
// Renders: "<p>Hi Alice Smith,</p>"
```

Replacements are merged on top of the `replaceVars` defaults seeded from `Config::templates`. Per-call values always win.

### Sensitive content — `$log_body = false`

```php
// OTP or password-reset email — code must not be stored in the database
$email = Email::make(
    $conn, $sender,
    'Your verification code',
    '<p>Your code is: <strong>%{otp-code}</strong>. Expires in 10 minutes.</p>',
    $userCode,
    'default',
    null,     // template
    null,     // driver
    false,    // log_body — body saved as ***redacted*** in DB
);
$email->addRecipient($recipientEmail, RecipientType::TO);
$email->send(['otp-code' => '123 4567 8']);
```

---

## Templates

Templates are outer HTML shells. The email body is injected via the `%{body}` token at render time. Both `%{body}` (new) and `%{message}` (legacy) are supported for backward compatibility.

```php
use TimeFrontiers\Mailer\Email\Template;

// Create and persist a new template
$template = Template::make(
    $conn,
    'Default Shell',
    '<html><body style="font-family:sans-serif">%{body}</body></html>',
    $userCode,
);

// Look up an existing template by id or code
$template = Template::findById(42);           // by int id
$template = Template::findById('42912345678'); // by string code

// Attach to an email explicitly (overrides Config lookup)
$email->setTemplate($template);
```

---

## Attachments

```php
use TimeFrontiers\File\File;

// Persisted — backed by timefrontiers/php-file; row written to email_attachments
$file = File::load($conn, $fileCode);
$email->attach($file);

// Transient — raw filesystem path; not stored in email_attachments
$email->attachRaw('/var/invoices/inv-001.pdf', 'application/pdf', 'Invoice.pdf');
```

---

## Deferred delivery (OUTBOX queue)

```php
// Move to OUTBOX and create pending EmailLog entries
$email->queue($conn, $sender, priority: 3);

// In your cron / queue runner — load OUTBOX emails and dispatch
$pending = Email::findBySql(
    'SELECT * FROM :db:.:tbl: WHERE `folder` = ?', ['outbox']
);
foreach ($pending as $e) {
    $e->send();
}
```

---

## Bulk personalized sending — `Email\Queue`

`Email\Queue` is designed for newsletters, campaigns, and any batch send where each recipient receives a personalized copy. The template shell is applied once at queue-creation time; per-recipient token replacements are applied at dispatch time.

```php
use TimeFrontiers\Mailer\Email\Queue;

$queue = Queue::make(
    $conn,
    $sender,
    'Hi %{user-name} — your monthly update',
    '<p>Dear %{user-name} %{user-surname},<br>Here is your update...</p>',
    'default',    // message_type — resolves template from Config
);

// Add recipients with their per-recipient token values
$queue->addRecipient('john@doe.com', [
    'user-name'    => 'John',
    'user-surname' => 'Doe',
]);
$queue->addRecipient(['name' => 'Jane', 'email' => 'jane@doe.com'], [
    'user-name'    => 'Jane',
    'user-surname' => 'Doe',
]);
$queue->addRecipient('plain@example.com', []);

// Dispatch immediately
$sent = $queue->dispatch();   // returns count of successfully sent recipients

// — or — leave as 'pending' and let the cron runner handle it
Queue::processNext($conn, $sender, limit: 50);
```

Queue recipients are **not** persisted to `email_recipients` — they are stored as JSON inside `email_queue.recipients`. This keeps the queue lightweight for large batches.

---

## Delivery log

Each `send()` creates one `EmailLog` row per TO recipient:

```php
use TimeFrontiers\Mailer\Log\EmailLog;

$log = EmailLog::loadById($conn, $logId);
$log->markRead();   // recipient opened the email
```

---

## Folder states

| Value | Constant | Description |
|-------|----------|-------------|
| `draft` | `Folder::DRAFT` | Not yet queued or sent |
| `outbox` | `Folder::OUTBOX` | Queued for deferred delivery |
| `sent` | `Folder::SENT` | All recipients dispatched |

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

2. Create the driver class implementing `MailDriverInterface`.
3. Add one arm to `DriverFactory::fromConfig()`.

---

## Code prefixes

| Entity       | Prefix | Example            |
|--------------|--------|--------------------|
| Email        | `421`  | `421394827163058`  |
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
