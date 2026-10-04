# Queue foundation

[Request correlation](Correlation.md) follows explicit Queue dispatch into worker attempts and resets between reservations. Optional [Observability](Observability.md) and the [development profiler](Profiler.md) measure dispatch and processing without retaining job payloads; Queue still owns retries and acknowledgement. Studio's [read-only Queue panel](Studio.md) uses existing status metadata and does not retry or delete jobs.

The [Doctor check](Health.md) inspects selected Queue backend readiness and database tables, including the failed-job payload column. It does not enqueue work or prove a worker is running.

SqueHub Queue runs application jobs now (`sync`) or stores them for a worker (`database` or `redis`). Queue's persistence and worker remain independent of Mail, Notifications, Auth, HTTP, Session, Cache, Storage, and Events; Mail, Notifications, and explicitly queued Event listeners dispatch their internal delivery jobs through it. The normal application-facing imports are `App\Plugins\Queue`, `App\Plugins\QueueJob`, and `App\Plugins\QueueException`; canonical `App\Queue` types and the `queue()` helper remain available. Plugins and the helper resolve the same Application-owned `QueueManager`.

[Scheduler](Scheduler.md) can dispatch a due QueueJob through this same manager. Scheduler owns recurrence and duplicate-dispatch claims; Queue still owns serialization, delivery, retries, and at-least-once job execution. Scheduler does not observe when an asynchronous Queue job completes.

## Job and dispatch

```php
namespace Project\Jobs;

use App\Plugins\QueueJob;

final class ProcessOrder implements QueueJob
{
    public function __construct(private int $orderId) {}

    public function handle(): void
    {
        // Perform application work using the order ID.
    }

    public function toQueuePayload(): array
    {
        return ['order_id' => $this->orderId];
    }

    public static function fromQueuePayload(array $payload): static
    {
        return new static((int) $payload['order_id']);
    }
}
```

```php
use App\Plugins\Queue;
use Project\Jobs\ProcessOrder;

Queue::dispatch(new ProcessOrder(123));
Queue::dispatch(new ProcessOrder(124), queue: 'reports', delay: 60);
queue()->dispatch(new ProcessOrder(125), connection: 'database');
```

`dispatch($job, $queue = 'default', $delay = 0, $connection = null)` validates the queue name and delay. A queue name is at most 128 ASCII bytes and contains letters, digits, `.`, `_`, or `-`, beginning with a letter or digit. It is bound as SQL data, never used as a table name. Delays are nonnegative seconds, at most one year. There are no closure jobs or duplicate dispatch verbs.

## Connections and installation

`Config/Queue.php` defaults to `sync`. `QUEUE_CONNECTION=database` selects the database driver, `redis` requires Redis, and `auto` probes Redis on first Queue use before selecting either Redis or database for that Application lifetime. Auto never selects `sync`. Named connections `sync`, `database`, `redis`, and `auto` are configured there. An empty `QUEUE_DATABASE_CONNECTION` uses the default Database connection; set a name only when Queue should use another configured connection. The Redis connection name comes from `QUEUE_REDIS_CONNECTION`. Redis credentials stay in `Config/Redis.php` and the Redis environment values. `QUEUE_REDIS_NAMESPACE` identifies one application's Queue state; by default it derives from the project path, so distributed servers with different checkout paths must set the same explicit namespace. Queue service registration opens neither PDO nor a Redis socket.

The database driver reads `queue_jobs` and `queue_failed_jobs` using the selected Database connection. Redis Queue does not require these tables for active or failed jobs. With `auto` and unavailable Redis, the database tables and worker migration must be present; absence is an operational error, not a reason to execute jobs synchronously. `QueueManager::infrastructure()` exposes safe `configured()`, `selected()`, and `reason()` values after resolution. Selection is pinned; an explicit Redis connection or already selected Redis backend never switches to database during an outage. See [Redis Queue](RedisQueue.md) for its storage and distributed worker contract.

Install the tables explicitly with `php squehub migrate`. `Database/Migrations/2026_09_24_create_queue_tables.php` creates the Queue tables, and `Database/Migrations/2026_09_25_add_failed_queue_payload.php` adds the nullable payload column required for retry. Existing failures without retained payloads cannot be retried. Application migrations are not run at boot. The `sync` driver needs no tables and runs `handle()` immediately, even with a positive delay.

The database payload is versioned JSON: `version`, the job class name, and JSON-compatible `data`. Only null, booleans, integers, finite floats, strings, and nested arrays are accepted. Objects, resources, closures, excessive nesting, and payloads over 60,000 encoded bytes are rejected. The worker validates the envelope and class, requires `QueueJob`, calls `fromQueuePayload()`, then calls `handle()`. It never uses native PHP `unserialize()` or executes a payload-selected method. Persisted Queue tables contain application data and require normal database confidentiality and backups. Mail and Notification jobs carry a second internal payload version and reject unknown versions.

### Selected typed data inside a job

SqueHub provides an **opt-in value envelope**, not a second Queue codec. A value implementing `App\Plugins\QueuePayloadData` exposes only `toQueueData()` and `static fromQueueData()`. Trusted application boot resolves the Application-owned `App\Plugins\TypedPayloadRegistry` and registers the value class under a stable lowercase alias and positive version. This is an exact application-facing alias of the canonical `App\Data\TypedPayloadRegistry`, not a second service. The registry's `encode($value)` returns `['type' => $alias, 'version' => $version, 'data' => $explicitFields]`; `decode($envelope)` accepts only registered aliases and the registered version. An application QueueJob may deliberately include this array in its existing `toQueuePayload()` data and resolve the registry after worker boot to reconstruct it. Neither a class name from persisted input nor arbitrary object properties choose reconstruction code.

The existing outer QueueJob envelope, `QueueCodec`, **60,000-byte total JSON limit**, worker, retries, failed jobs, and at-least-once semantics remain authoritative. An inner typed envelope does not turn a plain object into an auto-queued job or guarantee exactly-once handling. Unknown aliases/versions, malformed data, nested objects, and oversized jobs fail with specific safe registry errors. Exceptions from application `toQueueData()`/`fromQueueData()` callbacks, including callback-thrown `TypedPayloadException`, are replaced with generic safe messages and no previous cause. Register the same alias/version/class in sender and worker Applications; their registries are isolated. See [Typed application data](ApplicationData.md#explicit-typed-queue-payloads) for the concrete contract and provider example.

**Explicit Queue/Event payload envelopes are implemented and qualified** on the supported Windows and user-run native Linux qualification paths. Queue delivery remains at least once; see [verification status](Status.md).

## Chains and batches

Use `App\Plugins\Queue::chain([...], queue: 'imports', connection: 'database')` for ordered work, or `Queue::batch([...], queue: 'imports', connection: 'database')` for independently processed members. Both immediately dispatch through the selected fixed Queue connection and return a payload-free composition handle. The ordinary worker processes persistent members. See [Queue chains and batches](QueueComposition.md) for status, cancellation, failures, retries, retention, Sync behavior, and backend requirements.

## Queued Mail and Notifications

`App\Plugins\Mail::queue($message, connection: 'database', queue: 'mail', delay: 60, via: 'transactional')` dispatches the internal `SendMailMessage` job. A Notification implementing `App\Plugins\ShouldQueue` dispatches one internal `DeliverNotification` job instead of invoking channels in the request. Both jobs use the same `queue:work` command, retry settings, failed-job table, and Queue diagnostics as application jobs. No separate Mail or Notification worker exists. A Queue `sync` connection executes these APIs immediately; its delay does not postpone execution.

Queued Mail retains only a logical `via` transport name. At execution, the worker resolves that name using its current Mail configuration; SMTP credentials and Resend/Postmark API tokens are never copied into the Queue payload. The named HTTP providers still use the normal Mailer and one bounded HTTPS attempt per job attempt. A MailMessage translated before dispatch keeps those translated strings in the payload. A queued Notification builds its MailMessage during worker execution, so its payload or reconstructed recipient must supply a deliberate locale if its content depends on one. Queue does not inherit the dispatching HTTP request's locale. See [HTTP Mail providers](ProviderMail.md), [Mail](Mail.md), [Notifications](Notifications.md), and [Translation](Internationalization.md).

These payloads contain sensitive Mail content, Notification data, and recipient identity or anonymous route. Protect Queue tables accordingly. Raw PHP object serialization is never used. Queue is at least once: a worker can deliver and then crash before acknowledgement, and a retry can repeat channels that succeeded before a later failure. There is no exactly-once or deduplication guarantee. See [Mail](Mail.md) and [Notifications](Notifications.md) for payload contracts and delivery timing.

## Queued event listeners

`App\Plugins\Event::listenQueued(EventClass::class, ListenerClass::class)` registers one class listener for Queue delivery. The event implements `App\Plugins\QueueableEvent` with explicit JSON-safe payload methods; ordinary `Event::listen()` remains synchronous. The framework's internal delivery job carries the concrete event class, selected listener class, payload snapshot, and version. It uses the same Queue codec, worker, retry/backoff, and failed-job handling as other jobs. The worker invokes only that listener and does not re-emit the event. `afterCommit: true` is the queued-listener default; it uses this Queue manager's existing post-commit dispatch. Queue `sync` executes the listener immediately when no transaction is active. See [Events](Events.md) for registration, priority, propagation, transaction, and privacy semantics.

The [Webhook subsystem](Webhooks.md) also uses this Queue foundation for explicit deferred deliveries. Its internal job stores an endpoint name, event/delivery IDs, and bounded event JSON; the worker resolves the current configured destination and signing secret. The Queue payload may contain sensitive application data even though it omits credentials and signatures. The ordinary `queue:work` worker, failed-job storage, and `afterCommit()` timing apply; there is no separate webhook worker. At execution, Queue supplies internal attempt context to that job so an intermediate transient outcome is recorded as `retrying` while the last exhausted attempt is recorded as `failed`. This context is not an application-facing `QueueJob` requirement and is not supplied by untrusted payload data.

## Worker, retries, and failure

During local development, `php squehub dev --queue` can run the existing worker alongside the PHP server after [SqueHub Dev](Dev.md) preflight. It uses the selected persistent default Queue connection; a `sync` connection needs no worker and is rejected for that option. Stopping the Dev session can interrupt an in-flight attempt, especially on Windows where the direct PHP child is terminated. The job may be retried after normal reservation expiry. The standalone `queue:work` command remains the direct choice for workers and production process managers.

```bash
php squehub queue:work --connection=database --queue=default --tries=3 --backoff=5
php squehub queue:work --connection=database --once
php squehub queue:work --connection=database --max-jobs=100 --sleep=2
php squehub queue:work --connection=database --stop-when-empty
php squehub queue:work --connection=redis --queue=reports --once
```

`--once` checks for at most one eligible job and exits, including when the queue is empty. `--stop-when-empty` processes all currently eligible work and exits on the first empty poll; delayed jobs are not awaited. `--max-jobs` counts claimed attempts, including retries and failures. `--max-time` stops between jobs after a runtime limit, and `--memory` stops between jobs when allocated memory exceeds the MB limit. `--timeout` limits one job attempt. Unix `pcntl` can interrupt PHP execution; Windows has only a soft limit checked after `handle()` returns and cannot stop a blocked job. A timed-out job follows normal retry rules, and side effects may already exist. Keep timeout below the database `retry_after` period to reduce overlapping execution. Idle workers honor `--sleep` while checking controls each second. `Worker::exitReason()` reports `completed`, `empty`, `max_jobs`, `max_time`, `memory`, `restart`, `shutdown`, or `error`. Controlled exits return CLI success; infrastructure failures return failure. `Worker::stop()` and supported Unix signals request shutdown between jobs. A `sync` connection cannot be worked. Before each attempt, a worker reselects its own Application for static Plugins and clears any stale Auth request identity; Queue-only Applications do not resolve Auth merely for this reset.

The database worker selects an eligible row, then claims it with a guarded SQL update. The update increments `attempts` only for the worker that wins. A random reservation token fences acknowledgements, releases, and failures; an old worker cannot settle a row reclaimed after its reservation expires. `retry_after` in Queue configuration defaults to 60 seconds. A dead worker's reserved row becomes eligible again at that boundary. Times are stored as whole-second UTC values using SqueHub's model clock seam. The SQLite claim strategy is tested; live MySQL claim behavior needs separate verification.

The Redis driver implements the same reservation contract with atomic server-side scripts, server time, delayed sorted sets, and a shared restart marker. See [Redis Queue](RedisQueue.md) for ordering, stale recovery, worker coordination, and operational limits. A Queue `redis` connection is still worked by the ordinary `Worker` and the same CLI command.

On successful `handle()`, the worker removes the active row. On a thrown exception before the configured maximum attempts, it clears the reservation and moves `available_at` forward by the fixed `--backoff` seconds. On exhaustion, it transactionally moves the job to the failed table. With the upgrade migration installed, that row retains the encoded payload for retry. Malformed or unreconstructable payloads fail immediately. Failed payloads can contain sensitive application data; protect failed-job tables and backups. CLI listing never prints payloads or arbitrary exception messages.

The [shared retry policy](Retries.md) bounds outbound HTTP and Webhook inline timing, but does not override Queue's existing persisted attempts or worker backoff. Queue keeps its own at-least-once reservation and settlement contract; applying an HTTP retry policy to a Queue job would change when a job is acknowledged, released, or failed.

Queue delivery is **at least once**. If a process dies after `handle()` has caused side effects but before acknowledgement, the job may run again after reservation expiry. Make side effects idempotent where duplicates matter. Immediate dispatch made inside a transaction on the same database connection participates in that transaction; dispatch through another connection may become visible sooner.

## After-commit dispatch

```php
use App\Plugins\Queue;

Queue::afterCommit(new ProcessOrder(123));
queue()->afterCommit(new ProcessOrder(124), queue: 'reports',
    transactionConnection: 'orders');
```

`afterCommit()` validates the JSON payload when registered. With no active managed transaction it dispatches immediately. Inside a transaction it waits for the **outermost** commit. Nested savepoints merge callbacks into their parent in registration order; rollback discards callbacks registered in that savepoint. Outermost rollback discards all pending callbacks. `transactionConnection` selects the named Database connection whose lifecycle is observed; it is separate from the Queue `connection`. Raw PDO transactions opened outside `Connection` cannot register callbacks.

The business commit finishes before deferred Queue persistence. If Queue persistence fails, business changes cannot be rolled back. Later registered callbacks are still attempted and the failure is reported afterward. This is not a distributed transaction or an outbox guarantee. `sync` Queue jobs deferred in this way also run after commit.

## Failed jobs and restart

For a read-only operational snapshot, use `php squehub queue:status --connection=database --queue=default` or select `redis`. The command reports eligible ready records, delayed records, unexpired reservation leases, and the failed-record count across **all** queues on the selected connection. An expired lease is counted as ready because the next claim may recover it. These are point-in-time counts: a reservation is not proof that a worker process is alive, and the values can change immediately under concurrent workers. The `sync` connection has no persistent counts. Status never decodes or prints payloads, exception messages, or recipients. Doctor checks backend readiness separately and does not prove a worker is running.

```bash
php squehub queue:failed --connection=database
php squehub queue:retry 42 --connection=database
php squehub queue:forget 42 --connection=database
php squehub queue:prune --hours=168 --connection=database
php squehub queue:restart
php squehub queue:failed --connection=redis
php squehub queue:retry 42 --connection=redis
php squehub queue:restart --connection=redis
```

`queue:failed` lists at most 100 rows of safe metadata. `queue:retry` validates the retained payload, atomically inserts a new active job with attempts reset to zero, and removes the failed row. A failed requeue leaves the failed row intact. Database Queue retry requires the explicit payload migration; Redis Queue retains its failed payload in its own namespace. `queue:forget` removes one failed row. `queue:prune` removes failures strictly older than the selected positive number of hours; the default is seven days. Retry-all and automatic pruning are not provided. Applications may schedule pruning explicitly.

`queue:restart` writes a project-local generation marker under `Storage/Queue` for Database Queue workers. With `--connection=redis`, or a Redis default, it writes a shared Redis marker visible to workers on other hosts using the same Redis prefix and Queue namespace. Workers compare the selected marker between jobs and exit after finishing their current attempt; an external supervisor starts replacement processes. Idle workers observe it during their sleep interval. Long-running workers retain loaded code until restarted. SqueHub Dev can own a selected worker in one foreground local development session; production workers still need systemd, Supervisor, container restart policies, or a Windows service manager as appropriate.

## Diagnostics and boundaries

`diagnostics()->snapshot()['queue']` contains only `dispatched`, `processed`, `retried`, `failed`, `errors`, `worker_starts`, `worker_stops`, `timeouts`, `memory_exits`, `restart_exits`, and `time_ms`. These request-scoped measurements reset by the existing HTTP Diagnostics lifecycle; separate CLI workers do not produce one combined snapshot. They retain no job payloads, IDs, queue names, class names, or exception text. `time_ms` measures dispatch duration. Mail and Notification counters remain separate.

The manager and driver contract keep backend choice separate from the public `dispatch()` API. Each Application owns its manager and cached driver instances; in a process booting multiple Applications, resolve `QueueManager` from the intended container. The static gateway follows the currently bootstrapped Application. Redis Queue provides shared reservations and restart signaling, but production still needs an external worker supervisor. Current limits include no SQS, RabbitMQ, closure jobs, priorities, or dashboard. [Scheduler](Scheduler.md) can dispatch a due Queue job but does not change Queue delivery semantics. Separate queue names can organize work, but they are not priority scheduling.

The worker attaches its own Application container to internal Mail, Notification, queued Event-listener, and [Broadcast](Broadcasting.md) delivery jobs after JSON reconstruction; that context is never stored in Queue payloads. This keeps delivery bound to the worker's Application even if another Application booted later in the same PHP process. Immediate sync delivery retains the originating Mailer, NotificationManager, EventDispatcher, or BroadcastManager. General application jobs still follow the normal `QueueJob` contract.

The worker also begins and ends a fresh [translation locale](Internationalization.md) scope around each attempt. A locale selected by one job cannot leak into the next. Queue does not automatically copy the dispatching HTTP request's locale into its envelope; jobs that need a recipient locale must carry a deliberate, validated locale preference and select it while handling that job.
