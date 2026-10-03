# SqueHub v2.0.0 webhooks

The native [Application Contract](ApplicationContract.md) can declare outgoing SqueHub-profile event payloads, and [SDK generation](SdkGeneration.md) may provide types for them. Generated API clients do not verify incoming signatures, act as webhook receivers, send outgoing events, or configure a destination. The Webhook subsystem continues to own those operations.

The [Application Contract](ApplicationContract.md) can explicitly declare an outgoing event type with `Contract::webhook($type, $dataSchema)`. OpenAPI export places it under top-level `webhooks`, using the SqueHub event envelope and signature headers; the declaration neither sends an event nor registers a peer. An incoming receiver is an HTTP route and appears under `paths` only if that route has an explicit public contract. Do not place signing secrets in examples.

The wider [API verification gate](ApiVerification.md) retains the Phase 12F signature, replay, and local loopback regressions. A contracted incoming receiver can have explicit Kernel cases; an outgoing event declaration alone is not evidence that a remote peer accepted delivery.

SqueHub webhooks connect an application to **explicitly configured SqueHub-profile peers**. Outgoing events use a canonical JSON envelope and an HMAC-SHA-256 signature. Incoming sources verify the exact request body before decoding it, then can atomically claim an event receipt before application work. The subsystem builds on the existing [HTTP client](HttpClient.md), [Queue](Queue.md), [Database](Database.md), [CSRF](Csrf.md), and [Diagnostics](Diagnostics.md) services. It has no separate webhook worker.

This is a first-party protocol profile. It does not implement a vendor's webhook signature format or the general RFC 9421 HTTP Message Signatures standard. A GitHub, Stripe, or other provider endpoint needs its own verified adapter. Webhook signatures authenticate a configured peer; they do not replace application authorization or make arbitrary event data trustworthy.

## Configure named peers

`Config/Webhooks.php` starts with no endpoints or sources. Add only the peers the application uses. Keep signing secrets in the application environment or a secret manager; do not commit their values. Generate a distinct high-entropy secret for each peer and direction, for example from 32 random bytes. Do not reuse `APP_KEY`.

For initial secret provisioning, a local PHP process can generate a printable 64-character hex value from 32 random bytes: `bin2hex(random_bytes(32))`. Transfer it through a protected channel and store it in the application's private environment. Never generate a secret in a browser response, log it, or place it in a route or committed config file. The outbound and inbound peer must agree on the same secret for that *direction*; the two directions may use different keys.

```php
// Config/Webhooks.php; $environment is supplied by SqueHub.
return [
    'endpoints' => [
        'billing' => [
            'url' => 'https://billing.example.com/hooks/orders',
            'secret' => $environment->get('BILLING_WEBHOOK_SECRET'),
            'max_attempts' => 2,
            'retry_delay_ms' => 200,
        ],
    ],
    'sources' => [
        'billing' => [
            'secret' => $environment->get('BILLING_INBOUND_SECRET'),
        ],
    ],
    'receipt_store' => 'database',
    'receipt_connection' => null,
    'delivery_store' => 'database',
    'delivery_connection' => null,
    'receipt_retention_days' => 30,
    'delivery_retention_days' => 30,
    'receipt_lease_seconds' => 300,
    'timestamp_tolerance' => 300,
    'max_body_bytes' => 32768,
];
```

Keep any keys already present in your local `Config/Webhooks.php` when editing it. An endpoint sends; a source receives. A name contains only ASCII letters, digits, `.`, `_`, and `-`, starts with a letter or digit, and is at most 64 bytes through the public manager. Unknown names fail as configuration errors. Secrets must be 32–512 bytes with no control characters. A minimum length check is not a measure of actual entropy.

Outgoing URLs require HTTPS. Webhook delivery forces TLS peer verification even if the general HTTP Client was configured with `verify_peer => false`; a relaxed application HTTP setting cannot weaken this transport. The explicit `allow_local_http => true` option permits plain HTTP **only** for `localhost`, `127.0.0.1`, or `::1`, for local tests. URLs with credentials or fragments and direct IP literals or obvious local hostnames are rejected for normal destinations. A configured URL is never taken from request input. These checks do not defend against DNS rebinding or an otherwise hostile configured DNS name; production networks should also restrict outbound traffic and DNS resolution.

The source accepts at most two previous secrets during rotation:

```php
'sources' => [
    'billing' => [
        'secret' => $environment->get('BILLING_INBOUND_SECRET'),
        'previous_secrets' => [
            [
                'secret' => $environment->get('BILLING_OLD_SECRET'),
                'expires_at' => (int) $environment->get('BILLING_OLD_SECRET_EXPIRES_AT'),
            ],
        ],
    ],
],
```

Set `BILLING_OLD_SECRET_EXPIRES_AT` to a **fixed** Unix epoch value selected during rotation. Each rotation expiry must be positive and no more than 30 days ahead when the source is resolved. Do not recompute `now + 7 days` at every boot, which would perpetually extend trust in the old key. An old secret is accepted only while `now < expires_at`. Outgoing delivery signs with the currently configured endpoint secret; a queued worker resolves current configuration when it runs. Coordinate secret rotation with the peer before removing its old key.

## Create and send an event

Application code can import `App\Plugins\Webhook`; the `webhooks()` helper resolves the same Application-owned manager. Create one logical event and reuse it when several endpoints should receive the same event ID and body:

```php
use App\Plugins\Webhook;

$event = Webhook::event('order.paid', [
    'order_id' => (string) $orderId,
    'total_minor' => 4999,
]);

$result = Webhook::endpoint('billing')->send($event);

if (!$result->successful()) {
    // Inspect safe status/category metadata and choose an application action.
}
```

For a single destination, `Webhook::endpoint('billing')->send('order.paid', ['order_id' => '123'])` creates the event for you. Supplying both an existing `WebhookEvent` and a second data array is rejected. Event creation is local; `send()` performs the HTTP request immediately. `WebhookDeliveryResult` exposes `eventId()`, `deliveryId()`, `status(): ?int`, `successful(): bool`, `attempts(): int`, `failureCategory(): ?string`, and `retryable(): bool`. It does not retain the request or response body, URL, headers, or secret. A failed HTTP delivery normally returns a result; invalid configuration/event data or a storage infrastructure failure raises an exception rather than pretending that delivery succeeded.

An event is one logical fact; a delivery is one attempt group to one endpoint. Event IDs begin `evt_`, delivery IDs begin `whd_`. Reusing the event object preserves its ID and exact body across endpoints while each `send()` gets a new delivery ID. Do not treat these IDs as HTTP request IDs or authentication credentials.

### Envelope and signing profile

The UTF-8 JSON body has exactly these top-level fields, in this order:

```json
{"version":1,"id":"evt_<32 URL-safe characters>","type":"order.paid","created_at":"2026-09-26T12:00:00Z","data":{"order_id":"123"}}
```

The shown ID is illustrative, not a valid literal ID to send. The actual envelope is canonical, compact JSON generated by `WebhookEvent`; its maximum size is **32,768 bytes**. Types use bounded lowercase dotted/underscored/hyphenated names, such as `order.paid`. `data` accepts JSON-compatible arrays and scalar values, with no arbitrary PHP objects, resources, non-finite floats, or excessive nesting. `created_at` is UTC with whole-second precision. Do not put sensitive data in an event unless the receiving peer and every Queue/storage path are authorized to retain it.

Every outbound POST uses `Content-Type: application/json` and these headers:

| Header | Value |
| --- | --- |
| `SqueHub-Webhook-Id` | Logical event ID |
| `SqueHub-Webhook-Delivery-Id` | Delivery ID |
| `SqueHub-Webhook-Timestamp` | Current Unix epoch seconds |
| `SqueHub-Webhook-Signature` | `v1=` followed by 64 lowercase hex HMAC-SHA-256 characters |

The HMAC input is the exact byte sequence `v1\n<timestamp>\n<event-id>\n<delivery-id>\n<raw-body>`. A receiver must verify the bytes it captured, before reformatting or decoding JSON. The sender disables redirects, limits the response body to 4,096 bytes, and never exposes that body through its result. Different signing protocols are not interchangeable with this profile.

### Outgoing retry policy

Any 2xx response succeeds. Inline retry is bounded by `max_attempts` (1–3, default 1) and `retry_delay_ms` (0–1000, default 0). Only connection/timeout failures and HTTP `408`, `429`, `500`, `502`, `503`, and `504` are retryable. Other statuses, including redirects and ordinary 4xx responses, are terminal. A retry reuses the same event body and delivery ID while signing with a fresh timestamp. The sender does not follow `Location`. It considers a valid `Retry-After` hint on a retryable response, but caps that hint at the endpoint's 1,000 ms inline delay bound. Attempt count is reported; the remote peer may have processed a request even if the client experienced a connection failure. A response body exceeding the 4,096-byte transport bound also yields a non-retryable transport failure with an **unknown remote outcome**: the peer may already have completed the work. SqueHub does not retry that failure automatically. Make remote processing idempotent. The shared timing boundary is documented in [Retries](Retries.md).

## Queue delivery and transaction timing

Queue is explicit. It uses the existing Queue connection and worker; it does not change synchronous `send()`:

```php
use App\Plugins\Webhook;

$ticket = Webhook::endpoint('billing')->queue(
    'order.paid',
    ['order_id' => (string) $orderId],
    queue: 'webhooks',
    delay: 30,
    connection: 'database',
);

$eventId = $ticket->eventId();
$deliveryId = $ticket->deliveryId();
```

The default queue name is `default`; a null connection selects Queue's configured default. With `sync`, the Queue job runs during the call, including when a positive delay is supplied. Database and Redis Queue connections persist work for the ordinary `php squehub queue:work` worker. A `WebhookDeliveryTicket` identifies scheduled work; it is not proof that the peer accepted it. The internal versioned Queue job stores the endpoint **name**, canonical body, event ID, and delivery ID. It stores no endpoint URL, secret, or signature, and the worker resolves the endpoint's current configuration. The body can contain private application data, so protect Queue tables, Redis, failed-job storage, backups, and worker access. Queue's encoded payload limit also applies.

Queue is an optional dependency of this subsystem. Synchronous `send()` and incoming `verify()`/`handle()` can run in an Application that has no Queue service. In that setup, `queue()` and `afterCommit()` fail clearly when called; registering the Webhook provider does not implicitly install or boot Queue.

When an event corresponds to a business transaction, use the existing after-commit mechanism:

```php
$ticket = Webhook::endpoint('billing')->afterCommit(
    'order.paid',
    ['order_id' => (string) $orderId],
    queue: 'webhooks',
    connection: 'database',
    transactionConnection: 'orders',
);
```

Queue dispatch waits for the outermost managed transaction commit; a rollback discards it. With no active managed transaction, it dispatches immediately. `transactionConnection` selects the business database transaction to observe, separately from the Queue connection. The returned IDs do not prove that a later commit or Queue persistence succeeded. A Queue failure **after** business commit cannot roll that commit back; this is not a transactional outbox. Use the [Queue guide](Queue.md) for worker retries, failed jobs, reservation expiry, and `afterCommit()` semantics.

A queued worker retries a **transient** webhook result using Queue's normal backoff and attempt limit. A terminal remote rejection is acknowledged without more worker retries. The sender's bounded inline HTTP attempts happen within one Queue job attempt. Queue delivery is **at least once**: a worker can send successfully and die before Queue acknowledgement or before its delivery outcome is recorded, so another worker may send it again. The event/delivery IDs remain stable across a Queue replay. The delivery metadata store can suppress a later replay when it already contains a terminal result, but it is not an exactly-once guarantee. If a Queue reservation expires while a slow worker is still sending, two workers can attempt the same delivery concurrently. The receiver should deduplicate by source and **event ID** and keep business side effects idempotent. Queue's failed-job record is authoritative when worker retries are exhausted. Queue passes the current attempt and maximum attempts to its internal delivery job at execution time; only an intermediate transient outcome records `retrying`. A final exhausted attempt, or a transient outcome on the `sync` connection with no later attempt, records terminal `failed`. The attempt context is internal and is not persisted in the webhook job payload. Applications should decide how and when to inspect a safe `send()` result or failed Queue job metadata.

### Outgoing delivery metadata

`delivery_store => 'database'` records safe metadata in the explicitly migrated `webhook_deliveries` table. `delivery_connection => null` uses the default Database connection. The table records event and delivery IDs, endpoint name, state, accumulated HTTP attempt count, last HTTP status or failure category, and timestamps. It stores **no event body, URL, signing secret, signature, or response body**. A metadata row starts when synchronous send or worker execution actually begins, not when a Queue job is merely enqueued. It finishes as `succeeded` or `failed`, or is marked `retrying` only when Queue owns another worker attempt after a transient result. The `array` store is for isolated, one-process use and loses records when that Application ends. Neither store is a complete audit log or a replacement for Queue persistence.

Metadata pruning is explicit. The configured `delivery_retention_days` defaults to 30 and is applied by the combined `prune()` call shown below. The lower-level method accepts an explicit Unix timestamp cutoff:

```php
$counts = webhooks()->prune(); // ['receipts' => int, 'deliveries' => int]
$removed = webhooks()->pruneDeliveries(time() - 30 * 86400);
```

Only terminal `succeeded` or `failed` deliveries completed before the Unix timestamp cutoff are removed. Do not prune records still needed for reconciliation. There is no webhook delivery dashboard, retry CLI, or automatic pruning process in this phase.

## Receive a SqueHub-profile webhook

Configure a named source, install the receipt migration, then define an explicit POST route. `verify()` authenticates and parses one delivery; `handle()` additionally claims the event ID, runs the callback once per active receipt, and marks it processed. A duplicate returns `false` without calling the callback:

```php
use App\Plugins\{Request, Route, VerifiedWebhook, Webhook};

Route::path('/hooks/billing')->post(
    function (Request $request) {
        $processed = Webhook::source('billing')->handle(
            $request,
            static function (VerifiedWebhook $event): void {
                if ($event->type() !== 'order.paid') {
                    return;
                }

                $data = $event->data();
                // Validate expected fields, then perform idempotent business work.
            },
        );

        return response()->json(['processed' => $processed]);
    }
);
```

`VerifiedWebhook` exposes `source()`, `id()`, `type()`, `createdAt()`, `data()`, and `deliveryId()`. `verify()` deliberately does not claim a receipt; it suits callers that own a separate atomic idempotency mechanism. Do not use `verify()` alone when the application expects `handle()`'s duplicate suppression. The callback may throw. `handle()` marks its claim failed and throws a safe processing exception so the sender can retry. If the callback commits a side effect and crashes before the receipt is marked processed, that side effect may be repeated; use a domain-level idempotency key for operations that must tolerate this window.

Incoming verification requires `POST`, `application/json`, one unambiguous copy of each signing header, a body within the configured maximum, a timestamp within `timestamp_tolerance` seconds of the current clock, a valid signature under the active or unexpired previous secrets, and a canonical envelope whose event ID matches its header. Malformed metadata, stale/future timestamps, bad signatures, and invalid body formatting all produce the same generic HTTP 400 `Invalid webhook.` response. No credential or payload is returned to the caller. Accurate sender and receiver clocks matter. `Request::capture()` reads the request body before the Webhook verifier checks its 32 KiB bound, so production web server and PHP ingress limits must also constrain oversized requests before framework capture.

The example returns HTTP 200 after synchronous handling, including a duplicate that required no callback. An application can choose 204 for a no-body success or 202 only after it has actually persisted work for asynchronous processing. SqueHub does not force a universal success status. The webhook envelope's `version` is independent of HTTP [API version metadata](ApiVersioning.md); `Request::apiVersion()` does not select a webhook decoder. Configured [CORS](Cors.md) rules govern browser reads, not server-to-server signature verification, and CORS is not a replacement for the signature.

Global [CSRF](Csrf.md) middleware runs before route middleware or the controller. Exclude the **exact** webhook path in `Config/Csrf.php` when this endpoint is authenticated by the SqueHub signature, for example `'except' => ['/hooks/billing']`. Do not exclude unrelated browser routes or rely on CORS as authentication. Your endpoint can additionally apply [rate limiting](RateLimiting.md) with an application-chosen key; no universal webhook limiter is installed automatically.

### Receipt store, concurrency, and pruning

`receipt_store => 'database'` is the configured default. Run `php squehub migrate` to install the explicit `webhook_receipts` and `webhook_deliveries` tables before accepting incoming deliveries or sending outgoing deliveries with the default stores. `receipt_connection => null` uses the default Database connection; a configured name selects another connection. The database receipt store uses a unique source/event fingerprint and guarded claim updates. Receipts contain source, event ID, state, claim metadata, and timestamps, **not** the body, signature, or secret. Concurrent duplicates cannot both own the same active claim. States are `processing`, `processed`, and `failed`.

`receipt_store => 'array'` is useful for isolated tests or one process only. It cannot deduplicate across PHP requests or multiple workers. A failed callback permits a later retry; an abandoned processing claim becomes reclaimable after `receipt_lease_seconds` (default 300), with a new ownership token. An old callback cannot finish a reclaimed claim. A processed receipt continues to suppress that source/event pair until explicitly pruned; a fresh delivery ID does not bypass that claim. A duplicate already processing or processed returns `false`. Receipt handling is **not** a general API idempotency framework.

Receipt pruning is also explicit. The combined `webhooks()->prune()` uses `receipt_retention_days` (default 30). For a different cutoff:

```php
// Remove terminal receipts last updated more than seven days ago.
$removed = webhooks()->pruneReceipts(time() - 7 * 86400);
```

Pruning removes only terminal `processed` or `failed` receipts older than the cutoff; active `processing` receipts are retained. Choose retention for your retry and operational window. Once a receipt is removed, a **newly signed** delivery with the same event ID can be claimed again; the timestamp check alone only blocks stale signing timestamps. There is no automatic prune command or background cleanup in this phase.

## Diagnostics, errors, and boundaries

`diagnostics()->snapshot()['webhooks']` contains these aggregate counters: `outgoing_events`, `delivery_attempts`, `delivery_successes`, `delivery_failures`, `delivery_retries`, `incoming_verified`, `incoming_rejected`, and `incoming_duplicates`. It contains no endpoint/source names, IDs, URL, body, data, signature, or secret. Request diagnostics reset at the normal HTTP request boundary. Queue workers run in a different process and do not merge their diagnostics into the originating request.

Protect `Config/Webhooks.php`, environment values, Queue payloads, failed jobs, database receipts, application logs, and backups according to the data they contain. `WebhookEvent` and `VerifiedWebhook` omit payload data from debug dumps. An invalid incoming request yields a generic 400; a receipt or processing infrastructure failure follows the normal safe 500 boundary. Outgoing transport results expose only safe status and category metadata, not remote response content. Never log signing keys or full webhook bodies just to diagnose an integration.

Current scope: one SqueHub HMAC profile; named peers; synchronous or Queue-based outgoing delivery; bounded delivery metadata; signed incoming verification; optional database/array receipt claims; explicit secret rotation and pruning. There are no provider-specific signature adapters, delivery dashboard, broad API idempotency keys, distributed exactly-once processing, automatic Event-to-webhook mapping, vendor webhooks, or general HTTP Message Signatures. Verify each external peer's protocol before enabling production traffic. The [v2 status](Status.md) distinguishes working-tree implementation from published availability and environment qualification.
