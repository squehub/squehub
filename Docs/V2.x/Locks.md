# Unified lock leases

`lock()` returns the `App\Locks\LockManager` for the current Application. A lock protects one named lease in the selected backend. Use it only around work whose concurrency boundary matches that backend.

```php
$lease = lock()->acquire('invoice:123', ttl: 30);
if ($lease->acquired()) {
    try {
        updateInvoice(123);
    } finally {
        $lease->release();
    }
}
```

`acquire(string $name, int $ttl = 30, float $wait = 0.0)` returns a `LockHandle`. `acquired()` records whether the call obtained the lease; it is not a continuing proof that the lease is still valid. `ownerToken()` returns the 64-character unpredictable token for an acquired lease, `expiresAt()` returns its estimated UTC expiry, and `release()` returns true only if that owner removed a live lease. A second release or release after expiry returns false. If another live owner has since taken the lease, stale-owner release throws `LockOwnershipException` and leaves the new lease intact. The Redis server owns its actual TTL, so `expiresAt()` is an estimate based on the Application clock.

`App\Plugins\Lock::acquire()` and `App\Plugins\Lock::run()` use the same manager. `App\Plugins\LockHandle` is an exact alias for the canonical result type when application code needs a type hint. `run($name, $callback, $ttl = 30, $wait = 0.0)` attempts release after the callback and throws `LockBusyException` if contention persists. If the callback and release both fail, the callback failure remains primary and the lease expires by TTL. A release failure after a successful callback propagates. No second public lock API is needed.

## Names, time, and waiting

Names must be 1–200 ASCII bytes and match `[A-Za-z0-9][A-Za-z0-9:._-]*`. Separators, wildcard characters, controls, and NUL are rejected. The backend receives a SHA-256 digest of the Application namespace and name, never the raw name. A lease TTL must be 1–86,400 seconds. Longer work must be split or explicitly designed around lease expiry; there is no automatic extension in this API. A wait is 0–60 seconds. Contention retries sleep for at most 25 ms at a time against a monotonic deadline. Backend connection or storage timeouts are separate from that contention wait. An interrupted sleep may return early; it does not extend that deadline. Process termination releases any short file mutex, while the lease remains until expiry.

Both file and database expiry use the Application clock. Distributed database users must keep node clocks synchronized; skew can shorten or lengthen a perceived lease. A crash can leave a lease behind until its TTL ends. Another owner may then acquire it. `release()` is optional on an acquired handle but strongly recommended in `finally` to avoid holding the lease to expiry.

## Configuration

`Config/Locks.php` defaults to `file` under `Storage/Locks`. Settings are `driver`, `prefix`, `path`, `redis_connection`, `database_connection`, and `require_distributed`. Corresponding environment names are `LOCK_DRIVER`, `LOCK_PREFIX`, `LOCK_PATH`, `LOCK_REDIS_CONNECTION`, `LOCK_DATABASE_CONNECTION`, and `LOCK_REQUIRE_DISTRIBUTED`. Valid drivers are `array`, `file`, `database`, `redis`, and `auto`.

| Driver | Coordination boundary | Storage and expiry |
| --- | --- | --- |
| `array` | One Application object in one PHP process | Application memory; cleared on process exit |
| `file` (default) | Cooperating processes on one server and filesystem | Private `Storage/Locks` namespace; lazy expiry and removal |
| `database` with SQLite | Processes sharing the same SQLite file | `reliability_locks`; expiry checked on access |
| `database` with MySQL | Processes sharing the selected MySQL database | `reliability_locks`; expiry checked on access |
| `redis` | Processes using the same Redis service and logical namespace | Redis `SET NX EX` TTL and Lua token-checked release |

`auto` probes Redis once on first manager resolution and otherwise selects file. Selection is fixed for that Application lifetime; an outage after Redis selection raises `LockBackendException` and never switches to file. Explicit Redis never falls back. The Redis manager supplies the named connection and its own key prefix. A distributed Redis or MySQL backend also requires an explicit, stable `LOCK_PREFIX` shared by the intended deployments; paths differ between hosts. Set `LOCK_REQUIRE_DISTRIBUTED=true` to reject array, file, auto, and SQLite. An explicit MySQL or Redis selection is still subject to actual backend availability. `LOCK_PATH`, if set, must be absolute. Backends create no file, PDO connection, or Redis socket merely because the Application boots.

Run the standard migration before using `database`. It creates `reliability_locks` with a unique primary `key_hash`, `owner_token`, and an indexed `expires_at`. Claims use a transaction, unique insertion, and a conditional expiry update; release uses a conditional owner delete. SQLite process coordination requires a shared file, never `:memory:`. The lock database connection must not already be inside a caller transaction: an uncommitted lease would be invisible to other workers. On first use, SQLite lock operations set a bounded busy timeout for that connection. MySQL and SQLite have separate live qualification requirements; a passing SQLite test does not prove a particular MySQL deployment.

File lock records use one of 256 persistent hash-stripe `flock` mutexes for short transitions and a bounded JSON lease record per active key. Distinct keys sharing a stripe briefly serialize, but one name always maps to the same stripe. The fixed stripe set prevents permanent mutex-file growth as applications use many different names. Names and namespaces are hashed into private paths. Record publication uses a temporary file and rename while holding the stripe mutex. A damaged record raises `LockStorageException` rather than becoming an unsafe free lock. File locks rely on cooperating processes and the same local filesystem; network filesystems and multiple server copies are outside this guarantee. Protect the Storage directory from untrusted local writers.

Normal release removes its lease record. A crashed owner leaves a record that is safely replaced when that name is acquired after expiry. Expired records for names never revisited can remain on disk; lease expiry still permits correct acquisition. For physical cleanup, stop all processes using that file namespace and remove only that quiesced namespace directory after confirming its configured root. Do not unlink stripe mutex files or lease records while workers are active, because another process may still hold an open mutex inode. A rolling deployment must also quiesce old workers that used earlier per-name mutex files before switching to the stripe layout.

## Guarantees and limits

An acquired lock means one owner holds a bounded lease in the selected coordination backend at acquisition time. Ownership tokens prevent accidental release of another live owner's lease. **They do not fence external resources:** a paused worker can resume after its lease expires and perform work even though a new owner has acquired the lock. The protected resource must check a true monotonic fencing token itself if that stronger guarantee is required; this API does not issue one. A lock is not a database transaction, and it cannot make a payment, email, Queue job, or arbitrary external side effect exactly once.

Backend outage or missing migration fails the operation with a controlled `LockBackendException`; invalid names, TTLs, waits, and configuration raise `LockConfigurationException`. A malformed file record or database row raises `LockStorageException`. These errors do not contain lock names, owner tokens, backend credentials, or raw Redis keys. The owner token is returned only by the deliberate `ownerToken()` accessor. No automatic logs or diagnostics store it.

Scheduler's existing occurrence claim and overlap store remain in place. Queue reservation and acknowledgment remain separate and at least once. Application code can use `lock()` inside a scheduled task or Queue job where an explicit concurrency lease helps, but SqueHub does not add unique-job semantics or wrap every job automatically.

## Verification

`Tests/Unit/LockTest.php` verifies the public result contract, input bounds, owner mismatch, expiry, wait behavior, and Redis atomic command form. `Tests/Integration/LockIntegrationTest.php` uses separate PHP processes for file and SQLite-file contention, verifies crash/expiry recovery and Application isolation, and checks that hundreds of dynamic names create no more than 256 mutex files. Run both on the native filesystem being qualified. A Windows installation without directory-symlink privileges skips only the ancestor-link test; Linux should exercise it.

`Tests/Integration/LockRedisLiveTest.php` runs only with `SQUEHUB_TEST_REDIS_URL` and an installed Redis client. It uses one random `squehub-phase24-lock-*` prefix, tests across PHP processes, removes only its own prefixed keys, and verifies zero remain while connected. `Tests/Integration/LockMySqlOptInTest.php` requires `SQUEHUB_TEST_MYSQL_ENABLED=1`, a confirmed empty database named `squehub_test_*`, and the explicit `SQUEHUB_TEST_MYSQL_*` connection values. It creates and drops only `reliability_locks` and verifies the database is empty afterward. Default skips are not live-backend proof.

Phase 24 qualification passed on Windows and user-run native Linux, including file and SQLite child-process contention on a confirmed case-sensitive filesystem. The final Windows suite passed **2,924 tests, 21,890 assertions, 89 skips**; the final native Linux suite passed **2,924 tests, 22,122 assertions, 31 skips**; both had zero failures and errors. The user also ran the guarded Lock tests: Redis Server **8.0.5** with PhpRedis passed **1 test/9 assertions**, and MySQL **8.4.11** with PDO MySQL passed **1 test/25 assertions**. Redis used a disposable loopback server with persistence disabled, then the server was stopped and its temporary directory removed. A later post-stop scan was not independent key-by-key cleanup proof. The disposable MySQL database/user were dropped and their absence verified. Codex did not run these native Linux or live-backend sessions. See [release readiness](ReleaseReadiness.md#phase-24-reliability-qualification).
