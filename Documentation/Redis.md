# Optional Redis foundation

The [Doctor and infrastructure commands](Health.md) report current Redis capability and the subsystem's own fixed auto selection without exposing endpoints or credentials. Doctor may PING Redis; ordinary Application boot and liveness do not.

SqueHub v2 can use Redis without requiring it. The shipped web and CLI Application, local Cache, native Sessions, file Rate Limiting, Database Queue, Scheduler, Mail, and routing work when no Redis client or server exists. The shared Redis manager backs optional Cache, Session, Rate Limiting, and [Redis Queue](RedisQueue.md). Scheduler uses the configured Queue backend when it dispatches a job.

## Install and configure

Provide a Redis server separately, then install either the PhpRedis PHP extension or the optional `predis/predis` Composer package. Neither client is a required SqueHub dependency. `REDIS_CLIENT=auto` chooses PhpRedis first, then Predis. Set `phpredis` or `predis` to require one client; an unavailable explicit client fails clearly when a command is attempted. Simply booting the Application never loads a client or opens a socket.

`Config/Redis.php` defines `main` as the default named connection. With both `REDIS_URL` and `REDIS_HOST` blank, Redis is unconfigured. Supported environment values are:

```env
REDIS_CLIENT=auto
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_USERNAME=
REDIS_PASSWORD=
REDIS_DATABASE=0
REDIS_CONNECT_TIMEOUT=5
REDIS_READ_TIMEOUT=5
REDIS_TLS=false
REDIS_PREFIX=squehub:
```

`REDIS_CONNECT_TIMEOUT` and `REDIS_READ_TIMEOUT` are positive seconds, up to 60. Ports must be 1–65535; database numbers are nonnegative integers. A blank optional value is unset. The default shipped file leaves the host blank, so Redis is optional even when its config file exists. No persistent connection is used.

Alternatively set `REDIS_URL=redis://username:password@host:6379/0` or `rediss://username:password@host:6380/0`. The URL supplies host, port, ACL credentials, database and TLS mode. It cannot be combined with individual host, port, username, password, database or TLS fields in a named connection. Prefix and timeouts remain separate settings. Percent-encode reserved URL credential characters. The URL is never included in Redis diagnostics or framework Redis exception messages.

For additional named connections, edit `Config/Redis.php`:

```php
return [
    'default' => 'main',
    'client' => 'auto',
    'connections' => [
        'main' => ['host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'app:'],
        'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'prefix' => 'cache:'],
    ],
];
```

Each named connection can override `client`, `url`, `host`, `port`, `username`, `password`, `database`, `connect_timeout`, `read_timeout`, `tls`, and `prefix`. Names are bounded ASCII identifiers. The manager reuses one wrapper and client per name within an Application process. Separate Applications own separate managers; they may still intentionally address the same Redis data if configured identically. A forked child resolves a new socket before its next operation.

## Application API

Use the stable application-facing import:

```php
use App\Plugins\Redis;

Redis::set('welcome', 'Hello', ttl: 300);
$value = Redis::get('welcome'); // string|null
$created = Redis::set('lock-token', 'opaque', ttl: 30, onlyIfMissing: true);
$exists = Redis::exists('welcome');
$seconds = Redis::ttl('welcome');
$count = Redis::increment('counter');
$count = Redis::decrement('counter');
$expired = Redis::expire('welcome', 60);
$removed = Redis::delete('welcome');

Redis::connection('cache')->set('dashboard', 'ready');
```

`redis()` returns the current Application-owned `RedisManager`; `redis()->connection('cache')` selects a named connection. Resolving either object is lazy. `Redis::available()` deliberately probes with `PING`; ordinary application boot does not. `Redis::capability()` performs no network I/O by default and returns `['status' => ..., 'client' => ...]`. Status is `not_configured`, `client_unavailable`, or `not_probed`; `Redis::capability(probe: true)` can additionally return `available` or `unreachable`. A probe failure does not make unrelated services fail. Cache, Session, and Rate Limit `auto` modes probe once when their own service first resolves, then keep that selection for the Application lifetime.

Values in the direct Redis API are binary-safe strings. `get()` returns `null` for a missing key. `set()` returns `false` only when `onlyIfMissing` finds an existing key. `delete()`, `exists()`, and `expire()` return booleans. `ttl()` follows Redis values: `-2` missing, `-1` no expiry, otherwise seconds. `increment()` and `decrement()` run as atomic server commands; `set(..., onlyIfMissing: true, ttl: ...)` sends one atomic `SET` command. The direct API does not serialize PHP objects or silently JSON encode values. Each higher-level subsystem owns its own format.

Prefixes are applied to logical keys before commands. They help prevent accidental collisions but are not an access-control boundary. A Redis logical database index is also not a security boundary. Use network isolation, Redis authentication, and `rediss://` TLS across untrusted networks; certificate and peer-name verification stay enabled. Do not expose Redis directly to the public internet. Redis data and backups can contain application secrets. Restrict access accordingly.

## Diagnostics and deployment

`diagnostics()->snapshot()['redis']` contains only `operations`, `reads`, `writes`, `failures`, and `time_ms`. A command attempt, including a failed connection, counts once; obtaining a manager or connection counts zero. The section resets for each HTTP request and stores no key, value, connection name, host, URL, database, prefix, or credential. Vendor failures become safe `RedisException` messages. Credentials held by the manager and connection are omitted from their debug output.

Ordinary shared and cPanel hosting can leave Redis unconfigured and continue with file Cache, native Sessions, file Rate Limiting, and Database Queue. Set `CACHE_DRIVER=auto`, `SESSION_DRIVER=auto`, and `RATE_LIMIT_DRIVER=auto` to prefer Redis when a configured client/server responds to a single capability probe; otherwise the respective local driver is selected. `QUEUE_CONNECTION=auto` chooses Redis when available and Database Queue otherwise; it never chooses sync. Explicit `redis` requires Redis. Selection is fixed and never changes per operation or during an outage. Each subsystem can select a named connection with `CACHE_REDIS_CONNECTION`, `SESSION_REDIS_CONNECTION`, `RATE_LIMIT_REDIS_CONNECTION`, or `QUEUE_REDIS_CONNECTION`; credentials still belong only in Redis configuration. A future Doctor command can inspect each service's configured, selected, and reason state through `infrastructure()`.

All logical keys compose the Redis connection prefix and a subsystem namespace (`cache:`, `session:`, `session_lock:`, `rate_limit:`, or `queue:`). Subsystems also use a hashed application namespace. Session IDs and limiter subjects are never written as plaintext Redis keys. Hashing is not encryption; protect Redis, backups, and persisted payloads. To share state across servers with different checkout paths, set the same `CACHE_PREFIX`, `SESSION_REDIS_NAMESPACE`, and `QUEUE_REDIS_NAMESPACE` on those servers; keep `RATE_LIMIT_PREFIX` and the selected Redis connection prefix consistent too. Give unrelated applications distinct prefixes. Cache clear uses incremental `SCAN` within its own namespace and never flushes the database. Redis Cache uses native TTL and an atomic take script; Redis Rate Limit uses one atomic fixed-window Lua script. Redis Session uses PHP's existing session payload, native TTL, and a bounded per-session request lease; a request longer than the lease may overlap another. Redis Queue uses persistent records, time-ordered sorted sets, and fenced reservation scripts. Scheduler locks retain their existing backend.

For an opt-in live integration run, set `SQUEHUB_TEST_REDIS_URL` and install a supported client, then run `php vendor/bin/phpunit Tests/Integration/RedisLiveTest.php Tests/Integration/RedisSubsystemLiveTest.php Tests/Integration/RedisQueueLiveTest.php`. The tests use random prefixes and remove only their own keys; they never run `FLUSHDB`. The Queue test runs two independent PHP workers and tests stale-token fencing. Without these conditions, tests skip. TLS and authentication need a corresponding test server to be considered verified. Redis Cluster, Sentinel, Pub/Sub, Streams, and general distributed locks remain future work.
