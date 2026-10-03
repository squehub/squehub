# SqueHub v2 application data cache

`cache()` returns the `App\Cache\CacheStore` owned by the booted Application. The configured store is available in HTTP handlers, CLI commands, providers, and other application code. Cache use does not start a session, connect to a database, or require logging or diagnostics.

```php
cache()->store('site.settings', ['theme' => 'dark']); // No expiry.
cache()->store('report:2026:09', ['total' => 42], 600); // 600 seconds.

$settings = cache()->read('site.settings', []);
$exists = cache()->has('report:2026:09');
$removed = cache()->remove('report:2026:09');
$oneTime = cache()->take('temporary-token', null);
```

`read($key, $default)` returns the default on a miss. A cached `null` is a hit: `read()` returns `null`, and `has()` returns `true`. The default is a value, even if it is callable; `read()` does not execute it. `remove()` returns `true` only when a live entry existed. `take()` reads and removes a live entry in one driver operation, returning its value or the default. A file-backed `take()` uses an exclusive key lock so cooperating local processes cannot both take the same entry.

Use `remember()` to compute on a miss:

```php
$report = cache()->remember('report.summary', 300, static fn (): array => [
    'total' => db('orders')->count(),
]);
```

On a hit the callback is not called. A callback returning `null` creates a valid cached entry. The file driver rechecks under an exclusive per-key lock so cooperating processes compute a miss once. Other keys have independent locks. A callback exception propagates unchanged, leaves the entry absent, and releases the lock. This local coordination is not a distributed lock service.

## Configuration and storage

`Config/Cache.php` defaults to the `file` driver and `Storage/Cache` root. Set `CACHE_DRIVER=array` for process-local state, `redis` to require Redis, `memcached` to require the optional PHP Memcached extension, or `auto` to probe Redis once on first Cache resolution and otherwise select `file`. `CACHE_REDIS_CONNECTION` selects a named [Redis](Redis.md) connection (default `main`); Redis credentials remain in Redis configuration. An explicit network driver never silently falls back after a failure. Auto selection is fixed for the Application lifetime and is not runtime failover. `CACHE_PATH` may set an absolute file root. `CACHE_PREFIX` deliberately shares a namespace among applications that use the same root; otherwise the resolved Application base path separates them. Invalid settings fail during bootstrap or service resolution. File directories and entry files are created only on an operation. [Optional infrastructure adapters](InfrastructureAdapters.md#memcached-cache) documents the Memcached settings and guarantees.

The optional Memcached adapter passed a user-run live Linux qualification against Memcached 1.6.40 and PHP ext-memcached 3.4.0: 7 tests, 14 assertions, and 1 intentional skip of the absent-extension case. The test used an isolated namespace without a server-wide flush. This does not make a different deployment server reachable or verified; Doctor's ordinary inspection remains non-mutating.

Container stores and array-driver state are per Application. In a process that boots multiple Applications, the global `cache()` helper follows the most recently booted Application, like SqueHub's other global service helpers; use `$app->container()->make(\App\Cache\CacheStore::class)` when addressing a specific Application explicitly.

Each application namespace is a SHA-256 directory under the configured root. Each key is hashed into a `.cache` filename; `.lock` files coordinate access. Keys never appear as filenames. Keys must be nonempty strings of at most 512 bytes without control characters. Punctuation, Unicode, and path-like text are accepted because they are hashed. Prefixes cannot contain separators, `..`, or controls. Applications must still treat predictable keys as shared state, not as an access-control boundary.

`cache()->clear()` and `php squehub cache:clear` remove only entries in the configured application Cache namespace. The Redis driver uses incremental `SCAN` and scoped `DEL`, never `KEYS`, `FLUSHDB`, or `FLUSHALL`; Session, Rate Limit, and unrelated Redis keys remain. Concurrent writers may create new entries while a scan runs. Compiled Views have their own Application-owned `Storage/Views` directory and `php squehub view:clear` command; data Cache clearing does not touch them. A clear failure returns a nonzero CLI status. Expired file entries are removed when accessed; Redis entries expire natively.

## Values, expiry, and failures

Supported values are `null`, booleans, integers, floats, strings (including binary strings), and nested arrays containing those types. Arrays are copied on write and read. Objects, Models, collections, pages, Requests, closures, resources, and recursive arrays are rejected with `CacheException`. Cache serializes a versioned envelope and decodes with PHP class instantiation disabled. Redis Cache limits the encoded record to 8 MiB. Cache a Model's deliberate array representation if suitable, after reviewing hidden fields and sensitive data. Cache entries are neither encrypted nor authorization or audit records; protect file storage and Redis access.

TTL is a positive integer in seconds or `null` for no framework expiration. Zero and negative values are invalid. Redis uses atomic `SET EX` and server-side expiry; `null` has no Redis TTL. File/array expiry uses the framework's `ModelClock`. Corrupt, incompatible-format, or expired local entries become misses and are removed best effort. Redis payloads are versioned and decoded with class instantiation disabled after scalar/array validation; corrupt Redis payloads raise `CacheException`. Inability to create, lock, read, write, or clear the backend raises `CacheException`; backend failures are not silently converted to misses. Exceptions and automatic diagnostics contain no keys, values, or serialized payloads.

`diagnostics()->snapshot()['cache']` reports request-scoped reads, hits, misses, writes, removals, and backend time in milliseconds. `remember()` excludes callback computation from backend timing. Metrics reset for each Kernel request and are inactive outside a request. Cache does not log routine hits or misses. An application may log non-sensitive context deliberately through `logger()`.

[Compiled Views](CompiledViews.md) use source-derived PHP artifacts under `Storage/Views`; application data Cache uses its separate configured backend and namespace. Neither feature shares invalidation logic. Sessions, CSRF tokens, and authentication state remain separate. Redis Cache `take()` is atomic through one server-side script. Memcached Cache uses compare-and-swap for `take()` and namespace generation for `clear()`; it never flushes an entire server. `remember()` can compute the same miss concurrently on different servers or contenders; it is a cache convenience, not a distributed lock. Use the explicit [Lock API](Locks.md) for bounded leases. Database cache, tags, and background pruning remain out of scope. Private route/config caches are separate [framework performance caches](PerformanceCaching.md).

## Application-facing Plugins import

Application code may import `App\Plugins\Cache`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
