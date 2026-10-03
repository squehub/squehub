# Shared retry policy

`App\Plugins\RetryPolicy` is the application-facing import for the immutable `App\Reliability\RetryPolicy`. It describes **when** another attempt may start. The caller still decides **which** failures are transient and what one attempt means. A retry cannot make an unsafe external mutation atomic or guarantee exactly-once delivery.

```php
use App\Plugins\{Http, RetryPolicy};

$policy = new RetryPolicy(
    maxAttempts: 3,
    initialDelayMs: 200,
    multiplier: 2.0,
    maxDelayMs: 2_000,
    jitter: 0.1,
    maxElapsedMs: 5_000,
);

$response = Http::withRetryPolicy($policy)
    ->timeout(2)
    ->get('https://api.example.com/catalog');
```

All delays and elapsed limits are in **milliseconds**. `maxAttempts` includes the first attempt. The policy permits 1–100 attempts; its initial and maximum delay cannot exceed 30,000 ms, and its optional elapsed limit cannot exceed one hour. The multiplier is 1–10; jitter is a fraction from 0 to 1. The no-retry default is one attempt. `nextDelayMs()` returns `null` when the attempt or elapsed limit stops further work. An in-flight attempt still uses its own transport timeout; the elapsed limit controls whether another attempt may begin. The policy caps exponential growth before conversion to a sleep interval. Jitter can be tested with a supplied unit sample; production uses nonsecret randomness.

## HTTP Client

With `withRetryPolicy()`, GET and HEAD may retry connection errors, timeouts, and HTTP 429, 502, 503, or 504. Other completed responses return normally. POST, PUT, PATCH, DELETE, and OPTIONS do not retry unless the caller explicitly passes `allowUnsafe: true`:

```php
$response = Http::withRetryPolicy($policy, allowUnsafe: true)
    ->withHeaders(['Idempotency-Key' => $key])
    ->post('https://api.example.com/orders', $order);
```

Only enable unsafe retries after confirming the remote endpoint's idempotency contract. An idempotency key sent to a third party works only if that party honors it. The established `Http::retry(attempts: 3, delay: 500)` remains an **explicit** fixed-delay retry request, including for unsafe methods, for compatibility. It now uses the same policy timing internally. Neither form replays a non-rewindable stream. The client retains the same request body, authentication, timeout, TLS, redirect, and response-size settings for each attempt.

The HTTP Client reads `Retry-After` on a retryable response as either whole delta seconds or a standard GMT HTTP date. An invalid header is ignored. A valid hint can delay the next attempt, but never past 30 seconds, the configured maximum delay, or the remaining elapsed allowance. The remote server cannot force an unbounded sleep. `Retry-After` does not itself make an otherwise terminal response retryable.

## Webhooks and Queue

Webhook sending uses the shared policy's bounded timing while retaining its own transient statuses: connection/timeout failures and HTTP 408, 429, 500, 502, 503, or 504. Endpoint settings still cap inline delivery at 1–3 attempts and 0–1,000 ms between attempts; a `Retry-After` hint cannot exceed that 1,000 ms cap. Each attempt reuses the exact event body and delivery ID and signs with a fresh timestamp. The Queue worker may make another **job attempt** after an inline sequence; that is a separate at-least-once delivery boundary.

Queue's persisted job attempts, reservation expiry, acknowledgement, failed-job handling, and fixed `queue:work --backoff` seconds remain authoritative. Phase 24 does not reinterpret Queue's stored attempts through this HTTP timing policy because that would change existing worker and serialized-job behavior. See [Queue](Queue.md), [Webhooks](Webhooks.md), and [Outgoing HTTP Client](HttpClient.md).

## Guarantees and limits

HTTP Client diagnostics retain aggregate `requests`, `attempts`, `retried`, `successful`, `failed`, and `time_ms`; Webhooks retain aggregate delivery attempts and retries; Queue retains its own worker retry counters. Retry waits count in HTTP Client `time_ms`. No policy instance, URL, credentials, request body, webhook signing material, Queue payload, or remote response body is retained in those snapshots. A timeout may occur after the remote service has already performed work. Treat unsafe external operations as potentially repeated, including when Queue retries a job after a crash.

## Verification

The Windows HTTP/Webhook retry and transport subset passed **25 tests and 262 assertions**, including a real local HTTP transport retry. The user-run native Linux Phase 24 focused/regression set included RetryPolicy, HTTP Client transport, Webhook, and Queue checks and passed **96 tests, 1,817 assertions, zero failures, and zero errors**. The final Windows and Linux suites both passed **2,924 tests** with zero failures and errors; exact totals and backend qualifications are in [release readiness](ReleaseReadiness.md#phase-24-reliability-qualification). Codex did not run the Linux session. No test claims that an external provider performs an unsafe mutation exactly once.
