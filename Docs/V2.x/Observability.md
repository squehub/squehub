# Observability

SqueHub's Application-owned observability recorder measures selected framework boundaries without requiring a collector or an external telemetry package. It is disabled by default. Existing [request diagnostics](Diagnostics.md) keep their aggregate counters; enabling observation adds bounded execution reports and operation metrics without changing application results.

## Configure collection

Edit `Config/Observability.php`:

```php
return [
    'enabled' => true,
    'sampling' => 'all', // off, all, or ratio
    'ratio' => 0.0,       // used only with ratio
    'max_spans' => 256,
    'max_metrics' => 64,
    'exporter' => 'array', // none or array
];
```

`sampling => off` records metrics but exports no traces. `all` captures every root trace. `ratio` samples by trace ID using a fraction from `0.0` to `1.0`; `ratio => 0.0` exports no traces. Sampling does not suppress operation metrics. `enabled => false` stops both collection paths and keeps the framework's per-operation disabled path small unless the [development profiler](Profiler.md) is explicitly enabled. That local consumer activates recording but does not activate the configured exporter. The array exporter is an Application-local ring buffer for tests and development, retaining at most 32 completed reports and metric batches by default. `none` opens no export transport. No background connection, collector, database schema, or OpenTelemetry library is required.

The recorder is resolved from `App\Observability\ObservabilityManager` in the current Application container. Its `exporter()` method exposes the configured in-process adapter for tests. Application code can subscribe an `App\Observability\ObservationConsumer` to completed local reports; this hook receives capped local observations even when external trace sampling excludes them. Consumer and exporter callbacks are isolated from application results. A callback that itself uses instrumented framework services does not mutate the completed report or recursively deliver another report.

## Reports and metrics

An `ObservationReport` has a generated trace ID, an optional correlation ID, root operation, sampled flag, ordered parent/child `ObservationSpan` values, per-scope metrics, and a dropped-span count. A span has an operation name, relative offset, duration, failure flag, and allowlisted attributes. The HTTP root uses SqueHub's generated request ID as its correlation ID; that ID and the trace ID are distinct. Queue worker jobs and scheduler ticks start independent roots. Cross-process correlation propagation is a separate capability; 19A does not infer a parent from Queue payloads or incoming request headers.

Metrics use fixed operation and `ok`/`error` keys, for example `db.query.ok`, `cache.read.ok`, and `queue.job.error`. Each entry contains `count` and `time_ms`. Queue names, job classes, route parameters, event classes, task names, connection names, and other dynamic values never become metric keys or labels. `max_metrics` bounds distinct metric keys; extra keys increment `status()['dropped_metrics']`. `max_spans` bounds each report and reserves a slot for its root. The manager's process-local `metrics()` totals persist for its Application lifetime; request-level `Diagnostics` aggregates still reset on every `Kernel::handle()`.

`diagnostics()->snapshot()['observability']` exposes only `configured`, effective recording `enabled`, configured `export_enabled`, exporter class or `none`, last failure **class**, export/consumer failure counts, and dropped metric count. `export_enabled` stays false when the profiler alone activates local capture. The snapshot contains no exporter credential or exception message. Export failure does not replace a controller response or Queue job result. A custom exporter implements `ObservationExporter::exportTrace()` and `exportMetrics()` and can be passed when constructing an `ObservabilityManager`; this contract does not claim OTLP compatibility. An official OpenTelemetry adapter and collector integration remain deferred.

## Instrumented boundaries

| Boundary | Operations and safe context |
| --- | --- |
| Incoming HTTP | `http.request` root, route match, route execution, each global and route middleware, controller, and route model binding; method, registered route pattern/name, response status/class, safe handler label. Global middleware spans carry a fixed `mode => global` attribute. |
| Database | `db.query` at Connection/QueryBuilder execution; connection, driver, SQL verb, duration, outcome. SQL text, bindings, and row values are excluded. Direct PDO calls are outside this hook. |
| Cache | Read, write, remove, take, remember, and clear timings; hit/miss where known and backend failure outcome. Keys and values are excluded. Resolver work is excluded from `remember` backend time. |
| Queue | Dispatch and reservation timing, per-reserved-job root, process/retry/failure outcome; configured connection, queue, attempt, validated job class on bounded spans. Queue payload and exception text are excluded. |
| Scheduler | Tick root and a child scope per evaluated task, including due/claim/execution time and bounded result; `scheduler.claimed` records a successful occurrence claim. Existing Scheduler claims and locks remain authoritative. |
| Events | Emission duration plus synchronous or queued listener execution scopes; PHP event/listener class and mode, never event data. |
| Broadcasting | Enqueue and publish duration/outcome, adapter name, public/private/mixed channel category, sync/queued mode; never channel name or payload. |
| Outgoing HTTP | `http_client.attempt` and `http_client.retry` count real attempts and retries; `http_client.request` measures the logical request including waits. The latter carries bounded method, response status class, and one fixed result category: `success`, `http_status`, `connection_error`, or `client_error`. URLs, hosts, redirect locations, query strings, headers, and bodies are excluded because these may encode user or credential data. |
| Mail, Notifications, Storage, Redis | Existing centralized Diagnostics timing and outcome hooks feed bounded operation spans/metrics. Recipient, message, path, storage key, Redis key, and payload data are excluded. |

Some subsystem counters are aggregate-only rather than separate child scopes: for example Queue worker start/stop and Diagnostics request memory. Mail transport and Notification dispatch timings represent different boundaries from their success/failure counters. Scheduler task scopes include claim and execution in one interval; `scheduler.claimed` is a zero-duration outcome event, not a separate lock-duration measurement. Outgoing HTTP attempt/retry events are zero-duration counts within the measured logical request; they do not claim separate network timing. The worker reservation occurs before its job root, so reservation timing contributes a process metric instead of a child of a job not yet known. Outgoing host names are intentionally omitted rather than assumed safe. This is an instrumentation boundary, not a complete record of application execution.

## Privacy and cardinality

Framework hooks submit only allowlisted scalar attributes. Each span keeps at most 12 attributes; string values are capped at 128 bytes and control characters are rejected. Routes use the registered pattern such as `/users/{user}` instead of `/users/842193`. Reports do not include raw URL paths, request or response bodies, cookies, authorization headers, SQL, cache keys, Queue payloads, notification content, recipient addresses, broadcast channel names, storage paths, or Redis keys. Application-defined route, job, event, and task names may appear in capped **spans** and should themselves contain no secrets. Trace sampling and caps reduce storage, but they do not make arbitrary application names private.

Reports are in-process unless an application deliberately installs an exporter. An exporter may persist or transmit operational metadata, so review its destination and retention policy. The built-in array exporter neither writes files nor sends network traffic. No OpenTelemetry protocol or collector integration ships in this phase.

## Local completion hook

The local completion hook supports tools such as a development profiler without duplicating SQL, Cache, or HTTP instrumentation:

```php
use App\Observability\ObservationConsumer;
use App\Observability\ObservationReport;
use App\Observability\ObservabilityManager;

final class LocalTimeline implements ObservationConsumer
{
    public function accept(ObservationReport $report): void
    {
        // Consume only the bounded, privacy-filtered report.
    }
}

$observability = $app->container()->make(ObservabilityManager::class);
$observability->subscribe(new LocalTimeline());
```

Consumers run when a root finishes. They receive local reports independently of exporter sampling. Do not perform slow I/O or retain unbounded copies inside a consumer. Consumers and exporters receive no automatic retry; failures are counted in safe status and do not fail application work. An observation created inside a completion callback may count toward process metrics, but nested callback delivery is suppressed to prevent recursive export.
