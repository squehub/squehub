# SqueHub v2 Notifications foundation

Notifications describe a delivery intent; channels perform delivery. The Application owns one `NotificationManager` through `NotificationServiceProvider`, registered by the standard bootstrap. Delivery is synchronous unless a Notification explicitly implements `ShouldQueue`. This subsystem is separate from the historical `App\Core\Notification` and `App\Components\Notification` session-flash helpers, whose APIs remain available.

```text
Notification → NotificationManager → channel → Mailer when mail is selected
```

## Define and send

```php
use App\Plugins\MailMessage;
use App\Plugins\Notification;

final class WelcomeNotification extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Welcome')->text('Welcome to SqueHub.');
    }
}

notifications()->send($recipient, new WelcomeNotification());
```

`via()` returns ordered channel names. Built-ins are `mail` and `array`. `[]` succeeds without delivery. Repeated names run once, retaining first-occurrence order. Names must be bounded identifiers; unknown names fail before any channel runs. A custom channel implements `NotificationChannel` and can be registered once through `NotificationManager::registerChannel($name, $channelOrLazyClosure)`. Factories resolve only when selected and their instances are cached by that Application's manager.

## Recipient routes and the Notifiable trait

Mail requires an explicit `routeNotificationForMail()` method returning `string`, `MailAddress`, or `null`. The method may live on a Model or ordinary PHP object. A missing, null, or invalid route fails clearly; SqueHub does not guess an `email` property. `MailAddress` can carry a display name.

```php
use App\Plugins\Notifiable;

final class User extends \App\Plugins\Model
{
    use Notifiable;

    public function routeNotificationForMail(): ?string
    {
        return $this->getAttribute('email');
    }
}

$user->notify(new WelcomeNotification());
```

`Notifiable` only delegates to `notifications()->send()`. It adds no Model attributes, dirty state, relation, serialization field, or database behavior. It also works on plain objects. In a process with multiple Application instances, resolve the manager from the intended Application container when the global helper's current Application is ambiguous.

For an address without a persisted recipient:

```php
notifications()->route('mail', 'user@example.com')
    ->send(new WelcomeNotification());
```

`route('mail', ...)` also accepts `MailAddress`. The anonymous object retains its route and originating manager. A queueable Notification captures that explicit address in its sensitive Queue payload at enqueue time; ordinary synchronous routes are not persisted. Other anonymous route kinds are not supported yet.

## Queueable Notifications

Application code opts in with both explicit JSON-safe Notification data and an explicit recipient identity for object recipients:

```php
use App\Plugins\{Notification, ShouldQueue, QueueNotifiable, MailMessage, Notifiable};

final class WelcomeNotice extends Notification implements ShouldQueue
{
    public function __construct(private string $message) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Welcome')->text($this->message);
    }
    public function toQueuePayload(): array { return ['message' => $this->message]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['message']);
    }
    public function queueConnection(): ?string { return 'database'; }
    public function queueName(): string { return 'notifications'; }
    public function queueDelay(): int { return 60; }
    public function queueAfterCommit(): bool { return true; }
}

final class UserRecipient implements QueueNotifiable
{
    use Notifiable;
    public function __construct(public string $id) {}
    public function notificationQueueIdentity(): array { return ['id' => $this->id]; }
    public static function resolveNotificationQueueIdentity(array $identity): static
    {
        // Look up the current recipient in the application's trusted store;
        // throw if it no longer exists.
        return new static((string) $identity['id']);
    }
    public function routeNotificationForMail(): string
    {
        // Read the current address from the trusted store.
        return 'user@example.com';
    }
}

$recipient->notify(new WelcomeNotice('Hello'));
```

Only `ShouldQueue` Notifications are enqueued. `queueConnection()`, `queueName()`, and `queueDelay()` are optional overrides on the base Notification; defaults are Queue's configured connection, `default`, and zero seconds. A queueable Notification can additionally override `queueAfterCommit(): bool` to return `true`, and `queueTransactionConnection(): ?string` to follow a named Database connection. Queue owns the transaction hook. Without the opt-in, dispatch behavior remains immediate. Outside a managed transaction, after-commit dispatch is immediate; rollback discards pending work and nested transactions wait for the outermost commit. There is one Queue job per Notification, covering all channels in declared order. Object recipients must implement `QueueNotifiable`; their identity payload is stored, and the worker reconstructs them to read the **current** route. An anonymous mail route is captured at enqueue time. Entire Model objects and arbitrary PHP object graphs are never serialized. Missing recipients fail the job; Queue retries and eventually records a safe failure. The Notification payload and recipient identity in Queue tables are sensitive persisted data. The Queue codec enforces versioned JSON and a 60,000-byte bound.

A selected queueable Notification may deliberately include an [explicit typed data envelope](ApplicationData.md#explicit-typed-queue-payloads) in its existing `toQueuePayload()` array, then decode it through the Application's registered `TypedPayloadRegistry` where its reconstruction/delivery code has that service. This does not change `ShouldQueue`, recipient reconstruction, channel order, after-commit dispatch, or retry behavior. Plain Notification payload arrays remain supported; no notification or Model is automatically converted to typed data. Register the same alias and version in sender and worker Applications, and keep Queue storage private. A retried Notification may repeat already completed channel delivery.

**Typed Notification payload composition is implemented and qualified** on the supported Windows and user-run native Linux qualification paths. See [verification status](Status.md).

The standard `php squehub queue:work` worker handles these jobs with Database or [Redis Queue](RedisQueue.md). On `sync`, `notify()` executes the job immediately; delay does not sleep. Queue owns retries and backoff. On a retry, channels that succeeded before another channel failed can run again. A crash after delivery but before acknowledgement can also repeat delivery. Notifications and Mail are **at least once**, not exactly once; design sensitive deliveries accordingly. No Notification-specific worker, retry table, or database inbox is added.

## Channels and failure behavior

The Mail channel calls `toMail()`, requires a `MailMessage`, copies it, adds the explicit recipient, and delegates to the existing `Mailer`. The representation must not pre-address To, CC, or BCC recipients; this prevents a private notification from adding unintended recipients. Sender defaults, MIME, attachments, SMTP, named Mail transports, validation, and Mail diagnostics remain owned by [Mail](Mail.md). It uses the configured default Mail transport; `toMail()` does not select a `via` transport. Set `MAIL_TRANSPORT` to `resend` or `postmark` when that provider should deliver ordinary Mail notifications. Those HTTP providers require the resolved route as a To recipient, which the channel supplies. Provider-specific limits and live qualification status are in [HTTP Mail providers](ProviderMail.md). An invalid representation or route is a `NotificationException`. A safe Mail failure is chained inside a generic Notification delivery exception. Arbitrary application/channel exceptions, including an application-thrown `NotificationException`, are translated without publishing their possibly sensitive messages.

## Locale for Mail notifications

Notification content can use the Application-owned [Translation service](Internationalization.md). A synchronous `toMail()` runs in the current request or CLI locale scope. A queued `toMail()` runs in the worker after the notification and recipient are reconstructed. Queue does not copy the request locale into its envelope. Carry a validated locale explicitly in the Notification's JSON-safe payload, or load a trusted recipient preference at worker time.

```php
use App\Plugins\{MailMessage, Notification, ShouldQueue, Translation};

final class LocalizedWelcome extends Notification implements ShouldQueue
{
    public function __construct(private string $locale) {}

    public function via(mixed $notifiable): array { return ['mail']; }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(Translation::get('mail.welcome_subject', locale: $this->locale))
            ->text(Translation::get('mail.welcome_body', locale: $this->locale));
    }

    public function toQueuePayload(): array { return ['locale' => $this->locale]; }

    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['locale']);
    }
}

// The application chooses a supported locale before enqueueing.
notifications()->route('mail', 'user@example.com')
    ->send(new LocalizedWelcome('fr'));
```

The worker uses its own current translation catalogs. The locale is application data in the sensitive Queue payload; validate it against supported locales before dispatch. A missing catalog key follows Translation's documented fallback/key behavior. Translation does not make HTML safe automatically; escape or sanitize user content before building HTML Mail.
Place `LocalizedWelcome` in an autoloadable `Project` class file for a persistent Queue worker, and provide the `mail.welcome_subject` and `mail.welcome_body` keys in the selected locale's catalog. The corresponding catalog format is shown in [Mail](Mail.md).

The Array notification channel stores only notification **class names** in `notifications()`. It retains no notification instance, recipient, token, or body. `clear()` empties it. It is independent of the Array **Mail** transport, which stores prepared mail messages for explicit tests. Array notifications work without a Mail provider.

For multiple channels, SqueHub runs the declared order and stops on the first failure. Earlier successful deliveries remain completed and are not rolled back; later channels do not run. Synchronous delivery has no automatic retry. Queueable delivery inherits Queue retries without transactional compensation. A 500 crossing HTTP uses the existing safe exception boundary. Routine successful delivery does not create a log or Event.

## Diagnostics and account tokens

`diagnostics()->snapshot()['notifications']` contains only `attempts`, `queued`, `sent`, `failures`, `channel_deliveries`, and `time_ms`. A synchronous manager call increments `attempts`; a successfully accepted queue dispatch increments both `attempts` and `queued`. For after-commit delivery, `attempts` records successful registration and `queued` increments only when the commit callback dispatches. A rollback leaves `queued` unchanged. `sent` means every requested channel completed, including an empty channel list; worker delivery updates it in the worker's own diagnostics context. `failures` counts a failed enqueue or failed channel delivery, with a sync Queue delivery failure counted once. `channel_deliveries` counts successful individual channels. Time covers manager enqueue or channel delivery. Values reset per Kernel request; separate processes do not share one snapshot. No recipient, route, notification class, subject, body, token, or payload is recorded. Mail delivery through Notifications also increments Mail's separate counters once.

Account Security issues reset and verification tokens but never delivers them automatically. Application code may create a Notification containing the issued token and send it to the matching **trusted identity route**. Use the same public response for known and unknown accounts and apply explicit [rate limiting](RateLimiting.md). Never use an unverified request address as the delivery route for an account token. See [Account Security](AccountSecurity.md).

Synchronous Notifications require no database schema or configuration file. The manager and Array channel do not require Database, Session, Auth, AccountSecurity, Cache, Events, Storage, HTTP, Queue, or Scheduler. Queueable delivery requires Queue; the database connection uses Queue's existing tables. Separate Applications own separate managers, channel caches, Array records, and diagnostics. Mail is required only when the Mail channel runs.

Database inboxes, SMS/push/webhook channels, notification preferences, a dedicated notification template system, automatic locale selection, and bulk campaigns are later work. Application-authored translated Notifications are available through Translation now.

## Application-facing Plugins import

Application code may import `App\Plugins\Notification`, `App\Plugins\ShouldQueue`, `App\Plugins\QueueNotifiable`, `App\Plugins\Notifiable`, `App\Plugins\Notifications`, `App\Plugins\MailMessage`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
