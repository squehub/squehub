# Redis Queue and distributed workers

Redis Queue implements the existing `QueueJob` and `PersistentQueueDriver` contracts. Application dispatch, `Mail::queue()`, queueable Notifications, Scheduler job dispatch, and `Queue::afterCommit()` keep their public APIs. A normal `Worker` processes either Database or Redis Queue jobs; there is no Redis-specific worker command or Plugins symbol.

## Configure

```env
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_PREFIX=squehub:
QUEUE_CONNECTION=redis
QUEUE_REDIS_CONNECTION=main
QUEUE_REDIS_NAMESPACE=my-application
```

Supply the actual host, authentication, and TLS settings for your server. `REDIS_URL` can replace individual Redis coordinates. Redis credentials exist only in the Redis configuration; Queue stores a logical Redis connection name. `QUEUE_REDIS_NAMESPACE` must match on every server participating in one application Queue and differ across unrelated applications. If left blank, SqueHub hashes the project path; this default isolates local projects but different deployment paths will not share work. The Redis connection prefix and Queue namespace both contribute to key isolation. Keys begin with `queue:` after the connection prefix and never overlap Cache, Session, or Rate Limit keys. Neither Queue nor its tests use `FLUSHDB` or `FLUSHALL`.

Set `QUEUE_CONNECTION=auto` to select reachable Redis at first Queue use, otherwise Database Queue. This is an Application-lifetime decision: a later Redis outage is an error, not a switch to Database. Auto never selects synchronous execution. Database fallback requires installed Queue migrations and a working Database connection. Explicit `redis` likewise fails on Redis unavailability. Normal Application boot does not connect to Redis, open PDO, or create Queue data.

`QueueManager::infrastructure()` provides safe configured, selected, and reason values for the default Queue connection after its first resolution. Pass a connection name, such as `infrastructure('auto')`, to inspect a named connection. Selections are independent and fixed per Application lifetime; a named `auto` connection cannot inherit the default connection's choice. The result exposes no endpoint or credentials. The Redis Queue uses `Config/Redis.php` for a named connection and the shared `App\Redis` command and diagnostics boundary.

## Dispatch and workers

```php
use App\Plugins\Queue;

Queue::dispatch(new ProcessOrder(123), queue: 'orders', delay: 30);
Queue::afterCommit(new ProcessOrder(124), queue: 'orders');
```

```bash
php squehub queue:work --connection=redis --queue=orders --tries=3 --backoff=5
php squehub queue:work --connection=redis --once
php squehub queue:work --connection=redis --stop-when-empty
php squehub queue:status --connection=redis --queue=orders
php squehub queue:restart --connection=redis
```

Workers on different hosts must use the same Redis endpoint/database, connection prefix, and Queue namespace. `queue:restart --connection=redis` writes one shared opaque marker. Redis workers read it between attempts, finish the current attempt, then exit for an external supervisor to replace them. Database workers continue using the project-local `Storage/Queue` marker. `--sleep` retains normal idle polling; Redis Queue does not busy-spin. Worker timeout, memory, max-time, max-jobs, signals, and Windows soft-timeout limitations remain those of the normal Worker.

`--stop-when-empty` exits when no job is currently eligible; it does not wait for delayed work. `queue:status` reports ready, delayed, reserved-lease, and connection-wide failed counts without reading payloads. These counts do not establish whether a worker is alive. Use an external supervisor to replace deliberately exited workers.

## Storage and atomicity

Per application namespace, Redis Queue uses a global increasing job ID, a hash of JSON job records, a due sorted set and reserved sorted set for each logical queue, a failed-record hash and failed-time sorted set, and one restart marker. The Queue codec still caps encoded payloads at 60,000 bytes and validates version, class, and JSON-safe data. No PHP object serialization is used. Active records have no TTL. Reservation expiry permits a new claim; it never deletes the job.

One server-side Lua operation claims an eligible job, increments attempts, stores a random reservation token, and moves it to the reserved set. The due sorted set is ordered by server time, then by zero-padded monotonic ID for jobs with the same availability second. A bounded batch of expired reservations is recovered atomically during a later claim. Acknowledgement, release, and failure require the current token, so an old worker cannot settle a reclaimed attempt. Backoff and delayed availability use Redis server time, avoiding disagreement between worker host clocks. Failed-job moves and failed-job retries update their Redis structures in one server operation. Lua uses only Queue-owned keys supplied as `KEYS` and values supplied as `ARGV`; application values are never concatenated into script source.

Redis Queue is **at least once**. A worker can perform a side effect and lose Redis before acknowledgement; the reservation later expires and the job may run again. Application work, Mail delivery, and Notification channels must tolerate duplicates where they matter. A Redis outage before a completed reservation does not authorize execution. An outage after execution does not prove acknowledgement. Queue does not turn the Redis operation into a distributed transaction with the business database. `afterCommit()` waits for the outermost managed Database commit before Redis dispatch; rollback discards it, while a Redis failure after commit cannot roll the business transaction back.

Redis also supports the explicit `Queue::chain()` and `Queue::batch()` composition APIs. Server-side settlement atomically updates composition progress and makes the next chain item eligible only after the current item is acknowledged. Batch items may complete in any order; a failed item does not stop the other batch items. Cancellation prevents unreserved work from starting, but cannot undo a reserved or already executed side effect. Terminal metadata expires under the Redis retention policy or can be pruned explicitly. See [Queue composition](QueueComposition.md) for the exact API and failure semantics. This composition path has deterministic and process-race coverage; the user-run selected live Redis client path passed 30 assertions. The alternate optional client dataset skipped and remains unqualified by that run.

## Failed jobs

```bash
php squehub queue:failed --connection=redis
php squehub queue:retry 42 --connection=redis
php squehub queue:forget 42 --connection=redis
php squehub queue:prune --connection=redis --hours=168
```

Failed-job listings contain ID, queue name, job class, error type, attempts, and UTC failure time; they do not print payload or arbitrary exception text. Retry validates the retained Queue codec payload, inserts a new active job with attempts reset, and removes the failed entry atomically. Forget and prune affect only failures in the selected Queue namespace. Failed records retain potentially sensitive Mail and Notification data; protect Redis access, memory snapshots, persistence files, and backups. Diagnostics remain aggregate. Failed records do not expire automatically; schedule pruning if retention is required.

## Operations and limits

The implementation targets Redis **5.0 or newer** commands and effect-replicated Lua. The earlier opt-in Queue test passed against Redis Server 8.0.5 through PhpRedis 6.2.0, including atomic claim, stale recovery, and its process boundary. Later user-run live Queue composition passed the selected client dataset with 30 assertions; the alternate optional client dataset skipped. Predis live execution, Redis authentication/TLS, and broader deployment topologies remain unverified unless independently qualified. The Queue uses Redis server `TIME` in scripts; it requests effect replication before writes on versions where that setting is relevant. Keep enough Redis memory for persistent jobs and choose a server eviction policy that will not discard Queue keys. SqueHub does not alter `redis.conf`. Redis Cluster, Sentinel, Streams, blocking pop, Queue priorities, exactly-once execution, and an outbox are not provided. Existing `sync` and Database Queue drivers remain available for environments without Redis.

For live verification, set `SQUEHUB_TEST_REDIS_URL` and install PhpRedis or Predis, then run `php vendor/bin/phpunit Tests/Integration/RedisQueueLiveTest.php Tests/Integration/RedisCompositionLiveTest.php`. The opt-in tests use random Redis prefixes and cleanup restricted to their own `queue:` keys. The ordinary deterministic tests use a command double and do **not** prove live Redis atomicity.
