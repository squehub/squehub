# Queue chains and batches

SqueHub composes existing `QueueJob` implementations with the normal Queue worker. A **chain** runs jobs in array order and makes the next job available only after the previous reservation is acknowledged. A **batch** makes all jobs available together; an individual failure does not stop the others. Both use the Queue connection and queue name chosen when created. No second worker, callback registry, or native PHP object serialization is involved.

```php
use App\Plugins\Queue;
use Project\Jobs\{ImportCatalog, RebuildSearch, NotifyOwner};

$chain = Queue::chain([
    new ImportCatalog(42),
    new RebuildSearch(42),
    new NotifyOwner(42),
], queue: 'imports', connection: 'database');

$batch = Queue::batch([
    new RebuildSearch(10),
    new RebuildSearch(11),
], queue: 'imports', connection: 'database');
```

`chain()` and `batch()` dispatch immediately and return an `App\Plugins\CompositionHandle`; there is no second `dispatch()` call. Each job must implement `App\Plugins\QueueJob` and its JSON-safe `toQueuePayload()` / `fromQueuePayload()` contract. Existing `Queue::dispatch()` remains unchanged.

## Status and cancellation

```php
$status = $chain->status();
$status->state;      // active, cancelling, completed, failed, or cancelled
$status->total;
$status->succeeded;
$status->failed;
$status->cancelled;
$status->pending();
$status->progress(); // integer percentage of terminal items
$status->finished();

$chain->cancel();

// Save both the opaque ID and its fixed connection to inspect it later.
$later = Queue::composition($chain->id, $chain->connection);
Queue::cancelComposition($chain->id, $chain->connection);
```

A batch can also finish as `completed_with_failures`. `pending()` counts work not yet terminal, including reserved work; it is not a reliable count of running handlers. `progress()` counts successful, permanently failed, and cancelled items. A status snapshot contains no job payload or error text. An unknown or pruned ID returns `null` through `Queue::composition()`; the handle's `status()` instead throws a safe Queue exception.

Cancellation is cooperative. Unreserved work is removed before it can run, and a reserved job is skipped when the worker checks it before entering `handle()`. If `handle()` has already begun, it may complete; cancellation does not interrupt application code or undo its side effects. A composition may remain `cancelling` while such a reservation settles. Cancellation is idempotent for active work and returns `false` for terminal or unknown compositions. A failed chain stops its remaining jobs and clears their dormant payloads. A failed batch continues its other jobs. There are no per-job result payloads, completion callbacks, or automatic batch compensation.

## Connections and lifecycle

Compositions require a **fixed configured connection**: `sync`, `database`, or `redis`. The `auto` connection is rejected because a later worker process could select another backend. The same queue name and connection apply to every member. To process persistent work, run the existing `php squehub queue:work` using that connection and queue. Database composition state survives a worker restart in `queue_compositions` and `queue_composition_items`; install the additive `2026_09_30_create_queue_compositions` migration with `php squehub migrate`. Reversing that migration leaves the pre-existing Queue tables in place, but refuses to remove composition metadata while active or queued composed work exists. A later deliberate rollback removes terminal composition history. Database composition currently requires the default `queue_jobs` table. Redis stores composition metadata under its configured Queue namespace. Configure producer and workers with the same Redis namespace.

The `sync` connection executes the complete composition immediately in the caller. It returns a terminal handle; job exceptions become a failed composition status rather than escaping as synchronous `dispatch()` exceptions. Sync status exists only for that Application lifetime, cannot be canceled afterward, and has no durable worker or delayed execution. Database creation and advancement use the same Queue database connection's transactions; if an application opens a managed transaction on that connection, creation participates in it. A Redis composition is a separate backend operation and is not covered by a database transaction. There is no composition-specific `afterCommit()` API.

Queue validates the whole list before persistence. The shipped limits in `Config/Queue.php` are 100 jobs, 1 MiB of combined encoded payload, and the existing 60 KB limit per QueueJob envelope. The list must be nonempty and sequential. The Queue payload remains sensitive persisted application data; protect Queue storage and backups. Redis composition metadata retains payloads only for dormant chain steps; queued payloads live in normal Queue records, and terminal composition metadata contains counts without payload copies. A failed-job record can still retain its payload for an explicit operational retry. The handle and status expose only an opaque ID, connection, state, and counts. Queue diagnostics count one `dispatched` operation for one accepted composition, regardless of member count; worker attempt counters retain their existing meanings.

## Retries, races, and retention

Ordinary Queue retry and backoff apply to each running member. A job released for retry does not advance a chain. On exhausted attempts, the worker records a failed job and the composition records one permanent failure. `queue:retry` deliberately creates a **standalone** job from a failed payload: it does not resume a stopped chain, increment a completed batch, or rewrite historical composition status. This keeps a manual retry from silently advancing work a second time.

Reservation tokens fence settlement. A stale worker may repeat application side effects after lease expiry, but it cannot acknowledge a reclaimed reservation or advance the next chain step twice. Queue remains **at least once**, so handlers still need application-level idempotency where duplicate side effects matter. Cancellation versus reservation is settled at the backend boundary; a job already inside `handle()` may finish.

Terminal metadata is retained for the configured period (default 168 hours). Remove old terminal status explicitly with `Queue::pruneCompositions(hours: 168, connection: 'database')`. Active work is never pruned. Redis terminal metadata also has a backend expiry; database metadata remains until pruned. Pruning removes status and composition item metadata, so save any application-level reporting separately. Failed Queue job records follow the existing `queue:failed` / `queue:prune` lifecycle. There is no composition CLI command or dashboard.
