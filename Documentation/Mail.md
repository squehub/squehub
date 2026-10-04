# SqueHub v2 mail foundation

Named Resend and Postmark HTTP transports are available alongside SMTP and Array Mail. Both provider adapters passed guarded user-run live API qualification; Postmark's successful send used a same-domain recipient while that account's cross-domain sending awaited approval. See [HTTP Mail providers](ProviderMail.md) for configuration, request bounds, attachment differences, safe errors, and the exact qualification boundary.

[Doctor](Health.md) inspects Mail's default transport, SMTP host, sender, and production TLS policy without connecting to SMTP or sending a message.

Mail is an Application-owned service. `mailer()` resolves the current Application's `App\Mail\Mailer`; PHP's built-in `mail()` is not replaced. Synchronous Mail works from web handlers and CLI commands without a Session, database, Auth, Account Security, Cache, Events, Storage, or Logger provider. Queued Mail needs Queue; a Database Queue connection needs Database, while a [Redis Queue](RedisQueue.md) connection uses the shared Redis service. Register `MailServiceProvider` in a custom Application; the standard `Bootstrap/App.php` already registers it.

## Send a message

```php
use App\Plugins\MailMessage;

$message = (new MailMessage())
    ->to('user@example.com', 'Example User')
    ->subject('Welcome')
    ->text('Welcome to SqueHub.')
    ->html('<p>Welcome to SqueHub.</p>');

mailer()->send($message); // void; throws on failure
```

`MailMessage` also supports `from($address, $name)`, `cc()`, `bcc()`, `replyTo()`, and `attachBytes($contents, $filename, $mime)`. A configured default sender fills in when `from()` is absent. An explicit sender overrides it. At least one To, CC, or BCC recipient, a subject, and nonempty text or HTML content are required. BCC-only delivery is valid with SMTP and Array Mail; the Resend and Postmark HTTP APIs require a To recipient and reject BCC-only or CC-only messages before dispatch. `send()` prepares a snapshot, so applying the default sender never mutates the caller's draft. The text and HTML bodies are sent as authored; escape or sanitize untrusted HTML in application code. There is no remote attachment fetching or Mail-specific template abstraction in this foundation.

Address, display-name, subject, attachment filename, and MIME metadata are validated before transport I/O. Header control characters and path-like attachment filenames are rejected. The attachment API accepts bytes, not a filesystem path. Neither message objects nor the Array transport provide a generic serialization format for private message content.

## Configure transports

`Config/Mail.php` reads environment values and returns a `mail` map. The shipped default is `smtp`; it can boot without a host, credentials, or sender, but sending then fails clearly. Empty optional values in `.example.env` are treated as unset. Set `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_TIMEOUT`, `MAIL_VERIFY_PEER`, and `MAIL_ALLOW_SELF_SIGNED` as needed. `MAIL_TRANSPORT` selects the default named transport. Keep credentials in the environment, never in tracked config.

```php
return [
    'default' => 'smtp',
    'from' => ['address' => 'sender@example.com', 'name' => 'SqueHub'],
    'transports' => [
        'smtp' => [
            'driver' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587,
            'encryption' => 'tls', 'username' => $environment->get('MAIL_USERNAME'),
            'password' => $environment->get('MAIL_PASSWORD'), 'timeout' => 10,
            'verify_peer' => true, 'allow_self_signed' => false,
        ],
        'array' => ['driver' => 'array'],
        'resend' => [
            'driver' => 'resend',
            'api_key' => $environment->get('RESEND_API_KEY'),
            'timeout' => 10,
        ],
        'postmark' => [
            'driver' => 'postmark',
            'api_key' => $environment->get('POSTMARK_SERVER_TOKEN'),
            'timeout' => 10,
        ],
    ],
];
```

`tls` uses STARTTLS, `ssl` uses implicit TLS, and `none` disables opportunistic TLS. Peer verification is on by default; self-signed certificates are rejected by default. Use `none` only for a trusted local SMTP fixture or a deliberately secured network. SMTP authentication requires both username and password or neither. Port must be 1–65535 and timeout 1–120 seconds. The default named transport and driver map are checked at Application boot. Host, credentials, and SMTP settings are checked before the selected transport sends. Boot and `mailer()->transport()` do not open an SMTP socket. Each send creates a fresh PHPMailer instance, preventing recipient and body state from leaking across messages. SMTP debugging is disabled.

The SMTP integration suite exercises plain local SMTP and AUTH negotiation on loopback. It also confirms that `tls` requests STARTTLS and aborts when the fixture refuses it. Those SMTP tests do not perform a live TLS certificate handshake; the separate guarded Resend and Postmark tests reached their real provider APIs. The configured SMTPS mode relies on PHPMailer's encryption mapping; verify actual certificates and provider policy in the target deployment.

Configure additional names under `transports`, then select one explicitly:

```php
mailer()->send($message, via: 'transactional');
```

An unknown name is a configuration error. SMTP, Resend, and Postmark acceptance mean the selected service accepted the request; none proves inbox arrival. OAuth SMTP is not included. A retry after an uncertain transport outcome may duplicate delivery, so application retry policy needs its own idempotency decision. Resend and Postmark make one HTTPS attempt per Mail send; the Queue worker owns durable retries. The provider adapters need the existing `HttpServiceProvider`, which the standard bootstrap registers. In a custom Application that only registers `MailServiceProvider`, Array and SMTP Mail still work, while an HTTP provider send fails clearly until the HTTP Client service is registered.

## Translate message content

Use the Application-owned [Translation service](Internationalization.md) when composing a message. Mail sends the text and HTML it receives; it does not infer a recipient locale, translate a subject automatically, or treat catalog strings as trusted HTML.

```php
use App\Plugins\{Mail, MailMessage, Translation};

$locale = 'fr'; // Select a supported locale from your trusted recipient preference.
$message = (new MailMessage())
    ->to('user@example.com')
    ->subject(Translation::get('mail.welcome_subject', locale: $locale))
    ->text(Translation::get('mail.welcome_body', ['name' => 'Ada'], locale: $locale));

Mail::send($message, via: 'resend');
```

The example uses keys in `Project/Translations/fr/mail.json`, for example:

```json
{
  "welcome_subject": "Bienvenue",
  "welcome_body": "Bonjour :name"
}
```

For `Mail::queue($message)`, this translation happens before enqueue and the resulting strings are stored in Queue's sensitive payload. A worker does not retranslate that `MailMessage`. To choose a locale at worker time, use an application Queue job or a queueable Notification that carries an explicit locale or reconstructs a trusted recipient preference. Queue does not copy the dispatching request's locale into a job.

## Queue a message

`send()` remains synchronous. `queue()` dispatches the framework-owned `SendMailMessage` Queue job; the existing Queue worker later reconstructs the message and calls the ordinary Mailer. A `sync` Queue connection executes immediately, including a positive delay.

```php
use App\Plugins\{Mail, MailMessage};

$message = (new MailMessage())
    ->to('user@example.com')
    ->subject('Welcome')
    ->text('Hello');

Mail::send($message); // Synchronous
Mail::queue($message, connection: 'database', queue: 'mail', delay: 60,
    via: 'transactional'); // Requires a configured transactional Mail transport
```

`mailer()->queue()` has the same arguments. Omitting `connection` uses Queue's configured default; the queue name defaults to `default`. The selected Mail transport is stored only by logical name and resolved using worker-time configuration. SMTP credentials and transport objects are never put into the Queue payload. Queue persistence failure propagates; no delivery is reported as accepted. Mail diagnostics count the actual worker send, not database enqueue.

Set `afterCommit: true` when queued Mail depends on database changes in the current transaction:

```php
Mail::queue($message, queue: 'mail', afterCommit: true);
Mail::queue($message, queue: 'mail', afterCommit: true,
    transactionConnection: 'accounts');
```

`mailer()->queue()` supports the same named arguments. Mail delegates transaction timing to Queue. Outside a managed transaction, dispatch is immediate; rollback discards pending work, and nested transactions wait for the outermost commit. The Queue connection and transaction connection are separate choices. Failure to persist Queue work after a successful business commit cannot undo that commit. See [Queue](Queue.md).

Queued Mail stores an explicit versioned JSON representation of sender override, To/CC/BCC, reply-to, subject, text, and HTML. Byte attachments are **rejected** for queued delivery; they are not silently dropped. The Queue codec bounds the entire encoded job to 60,000 bytes before either database or sync dispatch. Message content and recipient addresses in Queue tables are sensitive and need database access controls and retention. A worker may deliver and crash before acknowledgement, so duplicate Mail is possible under Queue's at-least-once contract. Queue owns retry/backoff and failed-job recording; Mail adds no separate worker or retry loop. See [Queue](Queue.md).

## Array transport and diagnostics

The Array transport is process-local and performs no delivery. It keeps independent message snapshots for deterministic tests:

```php
$outbox = mailer()->transport('array');
$messages = $outbox->messages();
$outbox->clear();
```

Use `ArrayMailTransport` when inspecting the outbox; a named transport may instead be SMTP. Separate Application instances have separate array outboxes. Messages are retained in memory until `clear()` or the process ends, so avoid exposing the outbox in production interfaces.

`diagnostics()->snapshot()['mail']` contains only `attempts`, `sent`, `failures`, and `time_ms`. The duration measures transport sending, excluding application message construction and validation. Counters reset for each Kernel request; outside an active HTTP request, sending still works without adding request metrics. No address, recipient, subject, body, attachment, transport name, credential, or token enters automatic mail diagnostics. Routine sends produce no automatic log or Event. A Mail failure crossing HTTP is handled by the existing top-level exception handler as a 500; production responses remain generic. SMTP provider errors are translated without preserving their potentially sensitive text in the public exception chain.

## Account tokens and legacy Mail

Account Security issues reset and verification tokens but does not send them. A trusted application controller or service may use a token once to compose a `MailMessage`, send it through `mailer()`, and return the same public response for known and unknown accounts. Apply an explicit [rate limiter](RateLimiting.md) to public issuance routes. Never log the token, put it in diagnostics, or use it as a mail subject. See [account security](AccountSecurity.md).

The historical `App\Core\Mail` fluent builder and its `template()`/`layout()` resolution remain available for compatibility. It still returns `bool` from `send()` and uses PHPMailer directly; it does not use the v2 Mailer or its diagnostics. It reads the same `Config/Mail.php` environment-backed SMTP settings, and its send failures no longer publish PHPMailer error details. New code should use `MailMessage` and `mailer()`. The legacy email-template path precedence is preserved. Migration from legacy templates requires composing an explicit text or HTML body; v2 Mail does not silently reinterpret old template directives.

The [Notifications foundation](Notifications.md) supplies an explicit Mail channel above `Mailer`; direct `mailer()->send()` remains supported. Notification classes return ordinary `MailMessage` content while the recipient supplies the address. Mail adds no database migration of its own; persistent queued delivery uses Queue's existing tables. A dedicated Mail template abstraction and automatic reset or verification delivery remain later work; application code can already compose translated content explicitly.

## Application-facing Plugins import

Application code may import `App\Plugins\Mail`, `App\Plugins\MailMessage`, `App\Plugins\MailAddress`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
