# Performance characteristics and limits

SqueHub keeps normal application code concise while deferring optional work. Constructing the Application and registering providers does not open a database connection, SMTP socket, Redis socket, or Storage file merely to boot. Database opens PDO on first query. Mail connects only for a send. Queue and Scheduler work execute only when called or run by their CLI workers. This is an architectural behavior, not a published benchmark.

## Available tools

- Application data [Cache](Cache.md) supports file, array, and Redis backends. `remember()` computes on a miss; file coordination is local, and Redis `remember()` is not a distributed lock.
- `.squehub.php` [Views](Views.md) compile to source-derived PHP artifacts under Application-owned `Storage/Views`. A changed source selects a new content-addressed artifact without relying on mtime. `view:cache` can warm current Views without rendering, while `view:clear` removes owned artifacts; application `cache:clear` remains separate. See [Compiled Views and Production Lifecycle](CompiledViews.md).
- Private [framework caches](PerformanceCaching.md) can skip base Config PHP and cacheable route registration files after validating source and activation identity. `config:cache`, `route:cache`, and their separate clear commands are explicit; a Closure route action remains usable through the ordinary uncached loader.
- [Database](Database.md) queries use prepared statements and open connections lazily. Nested ORM eager loading batches each relation level; use `with([...])` to avoid an N+1 loop for supported relations.
- [Pagination](Pagination.md) offers offset pages with totals and forward cursor pages without an automatic count. Large offsets can be expensive; cursor ordering needs a unique key and has different behavior when rows change between requests.
- [Queue](Queue.md) moves suitable work to workers when using a persistent driver; `sync` executes immediately. [Scheduler](Scheduler.md) dispatches due work but needs host cron to invoke it.
- [Diagnostics](Diagnostics.md) exposes aggregate request counters and timing without retaining payloads. [Observability](Observability.md) adds bounded, safe spans and metrics, [correlation](Correlation.md) carries one opaque ID across supported boundaries, and the opt-in development [Profiler](Profiler.md) stores a limited request/work timeline. Use these to locate slow subsystems; measure real workloads before optimizing.

## Current limits

There is no service-discovery cache, global OPcache manager, or `php squehub optimize` aggregate command in this tree. The [optimization evaluation](PerformanceCaching.md#build-deployment-and-recovery) explains why individual validated cache commands are used. The five working `make:*` commands create source files; they are not performance tools. The compiled View lifecycle uses optional targeted OPcache invalidation when recovering or clearing an artifact; it never resets the full OPcache. Use PHP OPcache and web-server tuning according to the deployment environment, then validate actual throughput and memory under representative traffic. A fast local CLI smoke run is not a production latency claim.

Be careful with cross-process assumptions: file Cache and Rate Limit coordination is for cooperating local processes, while Redis and database backends require live server verification. Long-running Queue workers retain loaded code until restarted. See [Deployment](Deployment.md) and [verification status](Status.md).
