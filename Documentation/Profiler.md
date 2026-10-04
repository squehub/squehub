# Development profiler

SqueHub's development profiler keeps a bounded timeline of completed HTTP requests, Queue jobs, and Scheduler ticks. It consumes the existing [Observability](Observability.md) report; SQL, Cache, routing, middleware, Events, and other supported boundaries are measured once. The profiler adds local retention and inspection, not a second instrumentation system. It requires no database, Redis server, external collector, or Studio server.

## Enable capture

The shipped `Config/Profiler.php` is disabled. In `.env`, set:

```env
APP_ENV=development
PROFILER_ENABLED=true
```

`APP_DEBUG` does not enable the profiler. `APP_ENV=production` and every other non-development environment keep it disabled, even if `PROFILER_ENABLED=true` and `APP_DEBUG=true`. A disabled boot does not construct a profile manager or store, create directories, or allocate event graphs. Changing the environment or profiler setting in a running process requires that process to bootstrap again.

The default store is `file`, with private records under `Storage/Logs/Profiler`. The normal `Storage/Logs/` Git ignore rule covers these runtime files. Use `store => 'array'` in `Config/Profiler.php` for tests or one-process inspection. The array store does not share records with another Application or PHP process. The file store persists between local requests and cooperating processes on the same filesystem; it is not a distributed profiling backend.

```php
return [
    'enabled' => $environment->boolean('PROFILER_ENABLED', false),
    'store' => 'file', // file or array
    'max_profiles' => 100,
    'max_age_seconds' => 86400,
    'max_events' => 256,
    'max_profile_bytes' => 65536,
    'max_bytes' => 5242880,
];
```

Limits are validated at service resolution. `max_profiles` bounds retained records; `max_age_seconds` removes old records at the next save or read; `max_events` and `max_profile_bytes` bound each projected report; `max_bytes` caps total retained JSON bytes. The array and file stores prune the oldest recorded timestamp first, then profile ID for ties. One newly written file may temporarily exceed a count or byte budget before it is pruned. A directory far beyond that one-write allowance fails safely instead of loading an unbounded set of files. More events or metrics are truncated before persistence, and the record reports `dropped_events`/`dropped_metrics`. An existing corrupt file fails inspection and storage safely; the profiler does not treat it as a new empty record.

## What a profile contains

Each record has a random profile ID independent of its trace and correlation IDs, a UTC epoch capture time, root operation, total root duration in milliseconds, ordered events, operation metrics, and truncation counts. HTTP roots include the method, registered route pattern/name where matched, controller label where available, and response status. Middleware spans are nested and their durations are **inclusive** of downstream work. A child scope may therefore be needed to distinguish a middleware's own time; summing nested durations will double count. A Queue worker produces one `queue.job` profile per reserved job, and a Scheduler tick produces one `scheduler.tick` profile with task child scopes. A later Studio view can inspect these using the same record shape.

Database events include connection, driver, SQL operation, outcome, and duration. SQL text is omitted entirely because application code can put secrets directly in SQL literals; bindings and row data are also omitted. The existing metric map gives counts and total time for operations such as `db.query.ok` and `cache.read.ok`. A repeated query operation is evidence of repetition, not proof of an N+1 defect. Cache keys and values are absent. Queue payloads and job IDs, Event payloads, request/response bodies, authorization headers, cookies, session IDs, Mail/Notification content, Redis keys, and HTTP client URLs or headers are absent. No query parameter values or matched route parameter values are retained.

Profiles do not currently include reliable per-root memory start/end. `Diagnostics::snapshot()` has request memory counters, but PHP peak allocation is process-wide and the observation report does not carry those values. This is an explicit limitation rather than an invented measurement. The middleware timeline covers global and route middleware in execution order; global entries carry `mode => global`. A global rejection stops downstream route middleware and controller spans, as it stops their execution.

## Privacy and failure behavior

Observability admits only a fixed list of scalar attributes with length limits before a profile receives them. The profile file decoder validates its schema and attribute names again. Stored files use versioned JSON, random hexadecimal names, a private directory, a local namespace lock, and temporary-file-plus-rename publication. File operations reject linked directories and profile entries and verify containment under the Application root. The store never turns a route, key, user identity, or payload into a filename.

Profiles remain operational data, not an authorization boundary. Application-defined route names, task names, and class names may appear, so do not put secrets in those names. Keep the private `Storage/Logs/Profiler` directory inaccessible to the web server. The file store's lock coordinates SqueHub processes on a local/cooperating filesystem; no distributed locking guarantee is claimed.

A file-store failure is counted as an Observability local-consumer failure by `ObservabilityManager::status()` and does not replace the HTTP response, Queue result, or Scheduler result. It is not silently converted into a successful profile. `status()['last_failure_category']` contains only an exception class, not a path, payload, or secret. When only the development profiler activates Observability, `status()['enabled']` is true for local capture while `status()['export_enabled']` is false; a configured exporter remains inactive. Enabling Observability independently follows its own exporter policy.

The profiler stores completed roots only. It does not turn on an HTTP endpoint or a debug toolbar. The optional local [SqueHub Studio](Studio.md) can read its bounded records when both Studio and the profiler are enabled; Studio is a separate development server with its own environment gate. In long-running workers, each job root completes independently and the observation context is cleared before the next job. Profile IDs and correlation IDs do not serve as credentials.
