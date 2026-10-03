# Optional infrastructure adapters

SqueHub keeps Storage, Mail, Cache, and Queue as separate Application-owned services. Phase 20 adds an optional S3-compatible Storage drive, Resend and Postmark Mail transports, and a Memcached Cache driver. Selecting one does not require the others. The default local Storage, SMTP Mail, and file Cache configurations still work without those optional dependencies or credentials.

| Adapter | Selected by | Optional requirement | Local evidence | Live evidence |
| --- | --- | --- | --- | --- |
| S3-compatible Storage | `STORAGE_DRIVE=s3` or `Storage::drive('s3')` | `aws/aws-sdk-php:^3` | Fake object-client and Storage contract tests | Live S3-compatible MinIO: 1 test, 27 assertions passed; AWS S3 itself was not tested |
| Resend Mail | `MAIL_TRANSPORT=resend` or `via: 'resend'` | Existing HTTP Client and a provider API key | Fake/local HTTP request and response tests | Actual SqueHub adapter against Resend: 1 test, 1 assertion passed |
| Postmark Mail | `MAIL_TRANSPORT=postmark` or `via: 'postmark'` | Existing HTTP Client and a server token | Fake/local HTTP request and response tests | Actual SqueHub adapter against Postmark: 1 test, 1 assertion passed with a same-domain recipient; cross-domain sending was restricted by account approval |
| Memcached Cache | `CACHE_DRIVER=memcached` | PHP `ext-memcached` 3.x and a reachable server | Deterministic client/driver tests | Live Memcached 1.6.40 with ext-memcached 3.4.0: 7 tests, 14 assertions passed, 1 intentional skip |

Phase 20's user-run native Linux qualification also passed its final normal suite: 2,691 tests, 19,277 assertions, 28 skips, no failures or errors. These live results establish the named test environments and provider APIs, not a blanket guarantee for every S3-compatible service, AWS S3 account, Mail account, or future deployment. Doctor and Studio do not repeat these live checks. See [S3-compatible Storage](ProviderStorage.md), [HTTP Mail providers](ProviderMail.md), and [release readiness](ReleaseReadiness.md).

## Memcached Cache

Install and enable the optional PHP Memcached extension in the PHP runtime used by the application. A server must be configured separately. The base Composer installation does not require this extension. A selected Memcached Cache validates its settings at boot; resolving the Cache service without the extension fails with a Cache exception. Doctor can still boot and report `extension_missing`. Unselected providers do not connect at boot.

```env
CACHE_DRIVER=memcached
CACHE_PREFIX=myapp
CACHE_MEMCACHED_HOST=127.0.0.1
CACHE_MEMCACHED_PORT=11211
CACHE_MEMCACHED_TIMEOUT_MS=1000
```

`Config/Cache.php` accepts one configured host and port with a finite timeout. It does not provide a Memcached cluster, SASL, or TLS setup. `CACHE_PREFIX` is the same Cache namespace control used by the existing drivers; choose a distinct value per application sharing a server. Cache keys are fingerprinted before reaching Memcached and are not retained in Diagnostics. Values use SqueHub's existing bounded Cache value contract. The server and network must still be protected; fingerprinting is not encryption.

The public API remains `cache()->read()`, `store()`, `has()`, `remove()`, `take()`, `remember()`, and `clear()`. `clear()` atomically advances only the configured namespace generation. It never invokes a server-wide flush; old generations may remain physically until server expiry or eviction. `take()` uses compare-and-swap so concurrent takers cannot both claim the same live entry. `remember()` may compute the callback more than once during a race; one contender publishes a value and the others use it. It is a cache convenience, not a distributed lock.

SqueHub TTLs remain seconds. The driver converts a TTL longer than Memcached's 30-day relative-time boundary to an absolute Unix expiry; `null` requests no expiry. Memcached may evict an entry before its requested expiry. Cache corruption is treated as a miss under the existing Cache contract. Backend failures raise Cache exceptions rather than becoming fabricated hits or successful writes. No application data is written during ordinary boot, Studio inspection, `route:list`, or static infrastructure inspection. Doctor can report the selected extension/configuration but does not prove server reachability until an explicit live check is performed.

The guarded live test is `Tests/Integration/MemcachedCacheIntegrationTest.php`; it requires `SQUEHUB_TEST_MEMCACHED_ENABLED=1` and may use `SQUEHUB_TEST_MEMCACHED_HOST`, `_PORT`, and `_PREFIX`. A user-run Linux qualification passed against an isolated Memcached 1.6.40 server with PHP ext-memcached 3.4.0 (7 tests, 14 assertions, 1 intentional skip of the absent-extension case). No global `flush_all` was used; the test namespace was isolated and the server stopped afterward. Repeat the test only with an isolated server/namespace; it cleans only its own logical namespace.

## Queue adapter decision

SqueHub already ships Sync, Database, and Redis Queue connections with the existing worker, retry, failure, and after-commit contracts. SQS, RabbitMQ, and Beanstalkd were evaluated for Phase 20D and deferred: no demonstrated v2.0.0 application need justifies another broker or its operational dependency yet. A later driver must implement the current Queue connection contract and qualify reservation, retry, acknowledgement, failure, and process-boundary semantics on its real backend. The Memcached Cache adapter is not a Queue backend.

Provider operations retain their own subsystem ownership: Storage manages object keys and streams, Mail validates and sends messages, Cache stores values, and Queue handles background execution. Diagnostics and Observability receive aggregate, redacted operation data. Studio remains read-only and never sends a test email, uploads an object, or flushes a cache. See [Diagnostics](Diagnostics.md), [Health](Health.md), and [Studio](Studio.md).
