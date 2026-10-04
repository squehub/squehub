# HTTP idempotency for authenticated mutations

Idempotency is explicit route middleware for a client that may retry a mutation after losing its response. The client sends `Idempotency-Key`; SqueHub atomically claims the scoped key, runs the handler once while the claim is live, and stores a bounded safe result for later replay. It does not make arbitrary side effects exactly once.

```php
use App\Plugins\IdempotentRequests;
use App\Plugins\RequireToken;
use App\Plugins\RequireTokenAbility;
use App\Plugins\Route;

Route::path('/api/orders')->post([OrderController::class, 'store'])
    ->through([
        RequireToken::guard('api'),
        RequireTokenAbility::named('orders.write'),
        IdempotentRequests::authenticated(),
    ]);
```

Place `IdempotentRequests` **last** in the route's middleware list. It enforces this placement. Global CSRF runs before route middleware; authentication, token ability, application authorization, and any rate limit placed earlier run again on every retry, including a replay. The middleware requires an authenticated SqueHub identity and only accepts `POST`, `PUT`, `PATCH`, or `DELETE` modern routes. A Bearer-only mutation still needs the route's reviewed CSRF exclusion. No route is opted in automatically.

Send one ASCII key of 8–128 characters using letters, digits, `.`, `_`, `~`, and `-`, beginning with a letter or digit. Repeated headers, even identical repeats, are rejected. A missing or invalid key returns 400 `invalid_idempotency_key`. A client should generate a fresh unpredictable key per intended mutation and retain it for retries. SqueHub hashes the key before storage.

## Scope and fingerprint

The stored scope is a SHA-256 digest of the configured application namespace, route template, concrete application path, effective host, HTTP method, trusted authenticated identity, and key. A token-authenticated request includes the server-issued token identifier; another user or token cannot obtain its replay by copying the key. Scope components are not stored in plaintext.

The request fingerprint hashes method, path, content type, query, and exact captured body bytes, or a canonical form input map when there are no raw bytes. Sensitive body material is hashed and never stored in the idempotency record. Different bytes, even semantically equivalent JSON formatting, may conflict. File uploads, ambiguous `Content-Type`, and requests above `max_request_bytes` are rejected for this middleware. Validate application input in the handler as usual.

## Results and state

| Condition | Result |
| --- | --- |
| First claim | Handler runs; a cacheable 2xx response is stored. |
| Same scope and fingerprint after completion | Original 2xx status, body, and `Content-Type` replay, with `Idempotency-Replayed: true`. |
| Same scope, different fingerprint | 409 `idempotency_conflict`; handler does not run. |
| Same scope while another claim is processing | 409 `idempotency_in_progress`, `Retry-After: 1`; handler does not run. |
| Completed 3xx, streamed, cookie-bearing, or oversized success | 409 `idempotency_unreplayable` on retry; handler does not run again while retained. |
| Validation exception, explicit 4xx/5xx, or handler exception | Claim is released; a later retry may run the handler again. |

Only `Content-Type` is stored from a replayable response. `Set-Cookie`, `Authorization`, CSRF and session headers, trace/debug headers, and hop-by-hop headers are never stored or replayed. `Cache-Control: no-store` is added to successful first and replayed responses. Normal Kernel request IDs, CORS, browser security, and authentication response decoration are produced for each current request rather than copied from the old response.

An in-progress claim has a bounded lease. A crashed process leaves it until lease expiry; a matching request can then claim and run again. A former owner cannot finish or erase another owner's claim. A completed record is retained for a bounded period and then may be reclaimed. The defaults are a 120-second lease and 24-hour retention. The application must size the lease for its handler. If a handler mutates an external system and then its lease expires or store completion fails, SqueHub cannot undo that mutation; a retry may execute it again.

## Configure the backend

`Config/Idempotency.php` selects the backend lazily when an opted-in request first needs it:

| Setting | Default | Bounds or effect |
| --- | --- | --- |
| `driver` | `file` | `array`, `file`, `database`, `redis`, or `auto`. |
| `namespace` | application-path digest for local stores | Set a stable, unique name explicitly for database or Redis. |
| `path` | `Storage/Idempotency` | Absolute private file root. |
| `database_connection` | default database | SQLite or MySQL connection; run the migration first. |
| `redis_connection` | default Redis | Named Redis connection. |
| `require_shared` | `false` | Reject array, file, and SQLite; require MySQL or Redis. |
| `lease_seconds` | `120` | 1–300, less than retention. |
| `retention_seconds` | `86400` | 60–604800. |
| `max_request_bytes` | `1048576` | At most 1 MiB. |
| `max_response_bytes` | `32768` | At most 32 KiB of body bytes. |

`auto` probes optional Redis on first selection, otherwise uses file. Its decision is fixed for that Application lifetime; a later Redis outage does not switch a live namespace to file. Explicit Redis/database outages return a server failure before executing the handler. An explicitly selected shared backend requires a stable namespace on every node. File storage coordinates cooperating processes on one local server only; array storage is for tests or one process. Redis coordination is limited to the selected Redis service. MySQL database coordination is limited to its selected database. SQLite file coordination is suitable for a single server and a native filesystem, not a distributed cluster. Do not place the file store on a network share and infer multi-server safety.

The database migration `Database/Migrations/2026_10_02_create_idempotency_records.php` creates `idempotency_records` with a unique `scope_hash` and expiry index. It stores the fingerprint, owner token and lease, expiry, and a bounded response snapshot; it does not store the raw key, identity, path, or request body. File records are private and use `flock` plus atomic replacement. Their mutex files use 256 fixed hash stripes, so distinct request keys cannot create unbounded lock sidecars; an unrelated scope on the same stripe may wait briefly for its turn. Redis uses Lua for the whole claim, completion, and release transition and sets a server TTL. `Idempotency::manager()->prune(100)` performs physical cleanup for expired file and database records; an application may schedule it to reclaim storage. File pruning persists a hashed-filename cursor across processes and inspects at most the requested number of records per call, rotating past live records instead of revisiting them forever. It still enumerates the namespace directory on each call, so work grows with its file count; schedule pruning off the request path. Redis expires keys itself. No daemon is needed for correctness. Put a route rate limit before idempotency to bound distinct-key abuse.

## Guarantees and limits

- Within the chosen store's coordination boundary and a live lease, concurrent duplicate claims do not both enter the handler. A duplicate receives 409 while the first is processing.
- Authentication, CSRF, authorization, and prior route middleware still run on each attempt. A replay is never returned solely because a caller knows the key.
- Lease expiry, process crash, or an uncertain store completion can permit another handler execution. Ownership tokens stop a stale owner from changing the record, but they do not fence external work after a pause.
- Response replay is limited to bounded ordinary 2xx responses. A browser cookie, redirect, stream, large body, or server failure is not silently replayed.
- Idempotency state is separate from application database transactions, emails, and third-party APIs. For payments, pass a provider-supported idempotency key to the payment provider too; SqueHub's record alone cannot guarantee a remote charge happens once.
- A timeout after a remote service accepted a mutation is ambiguous. Use provider-side idempotency or another application-specific reconciliation mechanism before retrying.

Concurrency tests cover Windows and native Linux file/SQLite HTTP process races on a confirmed case-sensitive filesystem. Guarded live checks passed with Redis Server 8.0.5 through PhpRedis and disposable MySQL 8.4.11 through PDO MySQL. These results apply to the tested backends; they do not establish behavior for other deployments. Disposable backend cleanup was reported, but an independent post-stop Redis key-by-key cleanup check was not performed. See [verification status](Status.md).
