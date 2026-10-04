# SqueHub v2 rate limiting

Rate limiting is an Application-owned service for explicit, reusable limits. It is independent of Authentication, Account Security, Session, Cache, Storage, and Database. No limit is attached to a route or service automatically.

## Direct use

```php
$result = rateLimiter()->consume(
    'report.export',
    (string) $accountId,
    maxAttempts: 10,
    windowSeconds: 60
);

if ($result->denied()) {
    // Choose an application-specific response outside route middleware.
}

rateLimiter()->clear('report.export', (string) $accountId);
```

`consume()` returns an immutable `RateLimitResult`: `allowed(): bool`, `denied(): bool`, `limit(): int`, `remaining(): int`, `retryAfter(): int`, and `resetsAt(): DateTimeImmutable` in UTC. `clear()` returns true only when one stored state was removed. Direct calls do not set HTTP headers. There is no global clear operation.

Each bucket is a bounded identifier using letters, digits, `.`, `_`, or `-`. Keys are nonempty strings of at most 1024 bytes without control characters; Unicode identifiers are allowed. SqueHub derives a SHA-256 fingerprint from a length-framed application prefix, bucket, and a digest of the key. Plaintext keys do not reach the stores, filenames, diagnostics, or framework error messages. Hashing is **not encryption**: low-entropy identities can still be guessed offline, so protect runtime filesystem access.

## Fixed windows and stores

The first consumption starts a window at the current framework time. The first `maxAttempts` consumes are allowed; later consumes are denied, count up to `limit + 1`, and do not extend the reset time. `remaining()` is measured after the current consume. Allowed results have `retryAfter() === 0`; denied results report at least one whole second until reset. At the exact reset instant, the next consume starts a new window. Changing the limit or window length starts a fresh window for that fingerprint.

`Config/RateLimit.php` selects `RATE_LIMIT_DRIVER` (`file` by default, `array`, `redis`, or `auto`), `prefix` (`squehub` by default), and optional absolute file `path`. Existing `RATE_LIMIT_STORE` remains accepted when `RATE_LIMIT_DRIVER` is unset. `RATE_LIMIT_REDIS_CONNECTION` selects a named [Redis](Redis.md) connection. `auto` probes once on first limiter resolution and selects Redis if usable, otherwise file; the choice is fixed for that Application. Explicit `redis` requires Redis and never falls back. No closures belong in config. The array store remains scoped to one Application/process.

The file store defaults to `Storage/RateLimits` below the Application base path, separate from application Storage drives, Cache, Logs, and Sessions. It creates no directory at boot. Files live under a SHA-256 namespace directory and have fingerprint-only `.json` and `.lock` names. A JSON record holds only `version`, `count`, `limit`, `window_seconds`, and `resets_at`. A per-key exclusive lock covers read, decision, and replacement. Corrupt records and backend failures raise `RateLimitException` instead of granting a fresh permit. File locking is local/cooperating-process only. The Redis store executes decision, bounded count update, policy-change reset, and native TTL in one server-side Lua command. Redis policies must stay below the exact integer range of Lua's double number representation. Only SHA-256 fingerprints enter Redis keys. This allows cooperating application servers using one Redis backend to share the same limit; keep their system clocks synchronized because the existing RateLimiter clock supplies the window time. A Redis outage is an error, not permission to bypass a limit.

## Named request limits

Register a policy explicitly in an application or package provider:

```php
use App\Plugins\RateLimit;
use App\Plugins\RateLimitRule;
use App\Plugins\Request;

final class LoginRateLimit
{
    public function resolve(Request $request): RateLimitRule
    {
        $email = strtolower(trim((string) $request->input('email', 'anonymous')));

        return RateLimitRule::fixed(
            key: $email === '' ? 'anonymous' : $email,
            maxAttempts: 5,
            windowSeconds: 60
        );
    }
}

// In a ServiceProvider::boot() method:
RateLimit::define('login', LoginRateLimit::class);
```

The class is resolved lazily through the Container, including constructor dependencies. A trusted application callable receiving `Request` may be registered instead. Resolvers return exactly `RateLimitRule`; they choose how to handle missing input. Duplicate definitions and missing names are configuration errors. No directory scanning or automatic IP selection occurs. If an application explicitly chooses `$request->ip()` as a key, that value follows the [trusted-proxy policy](Http.md#trusted-proxies-and-request-metadata): direct `REMOTE_ADDR` by default, or a validated effective client IP behind a configured trusted proxy. Do not read raw `X-Forwarded-For` directly as a limiter identity.

Attach the named middleware using the modern route syntax:

```php
use App\Plugins\RateLimitRequests;
use App\Plugins\Route;

Route::path('/login')
    ->post([LoginController::class, 'store'])
    ->through(['guest', RateLimitRequests::named('login')]);
```

Global CSRF runs before route middleware. Route middleware runs in the listed order: with `guest` first, its rejection consumes no permit; with the limiter first, consumption precedes the guest decision. Middleware runs before controller validation. A denied request does not execute later middleware or the controller.

Allowed and denied responses receive authoritative integer `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` (Unix UTC seconds) headers. Denied responses also receive `Retry-After` in seconds, HTTP 429, and the normal `X-Request-ID`. Within an enabled [API response scope](ApiResponses.md), denial uses the `rate_limited` error envelope, `Cache-Control: no-store`, and the same request ID in the body/header. All four rate-limit headers retain their existing values; denied attempts do not move the reset time.

Outside API scope, JSON denial is `{"message":"Too many requests."}`; HTML denial uses the normal safe error path, including application status handlers. A controller's conflicting rate headers are overwritten. Direct `consume()` never changes an HTTP response.

Routine denials do not generate error logs. Store failures propagate as production-safe 500 errors through the existing ExceptionHandler. `diagnostics()->snapshot()['rate_limit']` reports only `checks`, `allowed`, `denied`, `clears`, `errors`, and backend `time_ms`, reset per HTTP request. It contains no key, bucket, named limiter, fingerprint, route, or identity.

Rate limiting does not replace authentication or authorization, lock accounts, implement bot detection, or provide audit history. Auth, [personal access tokens](ApiTokens.md), and Account Security do not consume limits automatically; applications attach limits where appropriate. A token-authenticated route can use an authenticated identity or a safe opaque token identifier as a policy key. Never submit the raw Bearer credential itself as a rate-limit key, even though the rate-limit store fingerprints its keys. Middleware order determines whether unauthenticated requests consume a permit. Sliding windows, token buckets, and automatic login/reset throttling remain out of scope. Trusted-proxy resolution belongs to Request and is never an implicit Rate Limiter key policy.

## Application-facing Plugins import

Application code may import `App\Plugins\RateLimit`, `App\Plugins\RateLimitRule`, `App\Plugins\RateLimitResult`, and `App\Plugins\RateLimitRequests`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
