# Request correlation

SqueHub assigns a 32-character lowercase hexadecimal correlation ID to each handled HTTP request. The ID is generated from cryptographically secure random bytes. It identifies one logical operation across its logs and deferred work; it is **not** a credential or an authorization decision.

```text
HTTP request
  ├── response: X-Request-ID and X-Correlation-ID
  ├── structured log: request_id and correlation_id
  ├── queued job: Queue envelope metadata
  └── outbound HTTP: X-Correlation-ID
```

The existing `X-Request-ID` contract remains. For an HTTP request, both response headers carry the same framework-generated value. Client-supplied `X-Request-ID` and `X-Correlation-ID` values are **replaced**; they are not stored as upstream IDs. Even a well-formed incoming value has no authority over SqueHub's local ID. Malformed values, including CRLF, cannot enter response headers, Queue metadata, or framework log fields. Error responses retain the safe reference ID without exposing stack traces, SQL bindings, paths, or credentials.

## Correlation and traces

Correlation IDs differ from observability trace IDs and span IDs. A correlation ID remains stable across a logical operation and its queued work. Each request, worker attempt, or scheduler tick can produce its own trace and spans. Trace sampling does not determine whether correlation works, and correlation is available when structured observability is disabled.

Diagnostics exposes the current or most recent safe reference as `diagnostics()->snapshot()['correlation_id']`. During a background job or scheduler tick, the active background ID takes precedence; HTTP `request_id`, method, route, and status from a prior request are suppressed. Framework log context includes `request_id` and `correlation_id` while the HTTP request is active. After it finishes, a later unrelated log inherits neither. A log emitted within an active background operation contains `correlation_id` only. Application-supplied log context remains under `data` and cannot overwrite these framework fields.

## Queue propagation

The built-in Database and Redis Queue drivers persist the correlation ID in validated, versioned **outer envelope metadata** (`meta.correlation_id`). It is not inserted into `QueueJob::toQueuePayload()` data. Sync Queue executes immediately within the captured context. Existing version 1 Queue records without metadata remain readable; a worker gives such a job a fresh local ID. Invalid metadata is rejected as an invalid Queue payload and follows the Queue failure path.

Direct dispatch captures the current operation ID or creates a fresh ID when there is no active operation. `afterCommit()` captures at **registration time**, so a later commit cannot accidentally inherit another request or task's ID. Every job in a chain or batch inherits the composition's originating correlation ID, while each worker execution gets its own attempt/trace. A correlation ID is not a composition ID or a deduplication key.

Workers clear correlation before polling and after every attempt, including failures and idle polls. This prevents Job B from inheriting Job A's identity unless B explicitly originated from A while A's context was active. Queued Events, Broadcasts, Mail, and Notifications use the existing Queue dispatch path and therefore inherit this envelope behavior. Custom Queue drivers retain the original `QueueDriver` contract; durable correlation propagation requires the optional internal `CorrelatedQueueDriver` capability.

## Scheduler and outgoing HTTP

Each `Scheduler::run()` invocation creates a new ID. Tasks evaluated during that tick share it; jobs they dispatch carry it in their Queue envelopes. A nested manual scheduler invocation restores its caller's context afterward. Separate cron runs never reuse a fixed ID.

The SqueHub HTTP client adds `X-Correlation-ID` when a current Application context exists. It never forwards tracing baggage or copies arbitrary inbound headers. An explicitly configured outbound `X-Correlation-ID` header takes precedence:

```php
$response = httpClient()->pending()
    ->withHeaders(['X-Correlation-ID' => $partnerReference])
    ->get('https://partner.example.test/status');
```

The explicit value above is application-owned and still passes normal HTTP header validation. Same-origin redirects retain the header. A cross-origin redirect returns its 3xx response without sending another request, so neither a generated nor explicit correlation header reaches the other origin automatically. Without an active context, SqueHub adds no correlation header automatically.

## Privacy and limits

Only the generated ID is propagated. SqueHub does not derive it from a user ID, session, IP address, email, token, or timestamp. Queue envelopes are persisted data and should receive the same storage protection as Queue payloads. Correlation does not grant trust to a caller or guarantee exactly-once job delivery.
