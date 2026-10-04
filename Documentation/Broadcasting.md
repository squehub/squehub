# Broadcasting and optional realtime delivery

SqueHub v2 broadcasting sends an **application-authored message** to a selected adapter. Core includes an in-memory Array adapter for tests and an explicit Null adapter. It does not ship a WebSocket server, browser client, Redis Pub/Sub gateway, or a third-party provider adapter. Ordinary applications need none of those services.

Internal `Event::emit()` remains separate: it never broadcasts automatically. A synchronous or queued listener may deliberately call `Broadcast::send()` or `Broadcast::queue()` when an internal event should reach external consumers. A queued listener performs server work later; a broadcast publishes selected data to a provider. No ordering between independent workers or external consumers is promised.

## Enable broadcasting

`Config/Broadcasting.php` ships disabled:

```php
return [
    'enabled' => false,
    'driver' => 'null',
    'max_payload_bytes' => 32768,
];
```

For deterministic tests, set `enabled` to `true` and `driver` to `'array'`. An explicit `'null'` driver discards publications; calling Broadcast while disabled instead throws a safe `BroadcastException`. The default does not open a socket or require Queue, Redis, Node.js, or a worker. A custom adapter can be registered by an application/package provider after the core provider has registered its manager:

```php
use App\Plugins\Broadcast;

Broadcast::registerAdapter('realtime-provider', fn () => new MyProviderAdapter());
```

Set `'driver' => 'realtime-provider'` in configuration. Factories are called only when that adapter is first used. `MyProviderAdapter` implements the public adapter contract:

```php
use App\Plugins\{BroadcastAdapter, BroadcastMessage};

final class MyProviderAdapter implements BroadcastAdapter
{
    public function publish(BroadcastMessage $message): void
    {
        // Send the validated name, channel list, and payload using this provider's SDK.
    }
}
```

The adapter owns credentials, network transport, provider response interpretation, and any browser subscription protocol. Never put provider credentials in an event payload.

## Define a public message

`BroadcastEvent` is a small, explicit payload contract. It does not require native PHP serialization or a Model snapshot.

```php
namespace Project\Broadcasts;

use App\Plugins\{BroadcastEvent, Channel};

final class OrderUpdated implements BroadcastEvent
{
    public function __construct(
        private readonly int $orderId,
        private readonly string $status
    ) {}

    public function broadcastName(): string { return 'order.updated'; }

    public function broadcastChannels(): array
    {
        return [Channel::public('orders')];
    }

    public function broadcastPayload(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status];
    }
}
```

Publish immediately:

```php
use App\Plugins\Broadcast;

Broadcast::send(new OrderUpdated($order->id, 'shipped'));
```

`send()` invokes the configured adapter in the caller's process. An adapter failure throws a safe `BroadcastException`; it is not silently ignored. Adapter exception text is not returned because a custom provider may include credentials in it. Choose synchronous delivery when an immediate result is necessary and the adapter's latency is acceptable.

## Queue delivery

The same event can use the existing Queue:

```php
Broadcast::queue(
    new OrderUpdated($order->id, 'shipped'),
    queue: 'realtime',
    delay: 5,
    connection: 'database',
    afterCommit: true
);
```

The full signature is `queue(BroadcastEvent $event, string $queue = 'default', int $delay = 0, ?string $connection = null, bool $afterCommit = false, ?string $transactionConnection = null): void`. `afterCommit` uses Queue's existing transaction connection behavior. A rollback drops the deferred dispatch; a post-commit Queue failure cannot undo an already committed business transaction. Queue's configured default connection applies unless `connection` is given. The `sync` Queue executes immediately, including requested delays. Persistent Database/Redis Queue workers use the existing `php squehub queue:work` command; there is no broadcast-specific worker.

SqueHub captures the event's name, channels, and payload before enqueueing. The versioned internal job stores only that validated snapshot, not the event class, PHP object, Request, Session, Model, or a serialized closure. Queue's JSON codec and 60 KB envelope limit still apply. The broadcast message itself is limited to the configured maximum, at most 32 KB. Adapter configuration is resolved when the worker runs; provider secrets are not queued. An unsupported internal job version fails safely through ordinary Queue failure handling.

Queue remains **at least once**. An adapter can publish successfully and the worker can fail before acknowledgement; a retry may publish the same message again. Consumers and external side effects must tolerate duplicates. No exactly-once or distributed-delivery claim is made.

## Private channels

`Channel::private('orders.42')` marks a channel that needs a subscription authorization rule. Register a rule explicitly during application/package boot:

```php
use App\Plugins\Broadcast;
use App\Plugins\Gate;
use App\Plugins\Authenticatable;

Broadcast::privateChannel(
    'orders.{order}',
    static function (Authenticatable $identity, array $parameters): bool {
        return Gate::manager()
            ->forIdentity($identity)
            ->allows('orders.view')
            && (string) $identity->authIdentifier() === $parameters['order'];
    }
);
```

The first pattern segment must be literal. A later `{name}` segment captures one bounded channel segment. Duplicate registrations fail, and overlapping rules that match the same actual channel fail closed rather than allowing registration order to choose the result. A private publication without any registered rule fails before adapter delivery. A private subscription check returns false for an unknown channel or missing identity. Rules may also name a Container-resolved class implementing `App\Plugins\ChannelAuthorizer` with `authorize(Authenticatable $identity, array $parameters): bool`.

`Broadcast::authorizePrivate($channel)` checks the **current server-side Auth identity**. It never trusts a browser-supplied `user_id`. A Session identity with an unfinished MFA challenge is a guest and cannot authorize. A named API token guard must be selected by route middleware such as `RequireToken::guard('api')` before authorization. Token abilities remain an additional restriction; add `RequireTokenAbility::named('broadcast.subscribe')` when an API route accepts tokens. Gate/Policy/RBAC decisions remain separate and can be used inside the registered rule, as above. A token ability does not itself grant the channel.

SqueHub does not install a provider-neutral authorization endpoint. External providers have different signed subscription handshakes; a generic success JSON would not secure them. An application or provider adapter may expose its own route using the normal Router, Auth, CSRF, CORS, Rate Limit, trusted-proxy, and base-path policies. For example, an application-owned browser POST route can validate its `channel` field, call `Broadcast::authorizePrivate()`, and return a provider-specific authorization response **only after** the check succeeds. `APP_BASE_PATH` applies to that route through the normal Kernel. A provider adapter must enforce the same server authorization decision at the actual subscription handshake; merely registering a callback in SqueHub does not secure a separate provider by itself. Do not replace Auth with a Signed URL or client identity claim.

## Data and browser safety

Names use bounded ASCII segments; controls, CRLF, slashes, empty segments, and excessive lengths are rejected. A message has one to sixteen unique channels and a bounded JSON-compatible payload. Nested arrays, finite numbers, strings, booleans, and null are supported. Objects, resources, invalid UTF-8, and excessive nesting are rejected before an adapter or Queue sees them. Obvious credential-bearing field names are rejected recursively using SqueHub's shared sensitive-key policy.

This field-name check is a guardrail, **not semantic secret detection**. A secret can still be placed under an innocent-looking key. Applications must deliberately construct safe public projections: IDs and approved fields are preferable to full Models, Request objects, Sessions, API resources with hidden data, credentials, tokens, TOTP secrets, or recovery codes. Queue payload storage may contain the selected broadcast data and must be protected as application data. Exceptions and Queue failed-job metadata do not print the broadcast payload by default.

A browser connecting to an external WebSocket provider may need an application-configured CSP `connect-src` entry. SqueHub does not broaden CSP automatically. Public/private channels are publication and subscription contracts, not a WebSocket implementation. The Array adapter is an inspectable in-process outbox; Null is an explicit discard path. Neither sends data to a browser.

See [Events](Events.md), [Queue](Queue.md), [Authentication](Authentication.md), [Authorization](Authorization.md), [RBAC](RBAC.md), [MFA](MFA.md), [Browser Security Policy](BrowserSecurityPolicy.md), and [Deployment](Deployment.md).
