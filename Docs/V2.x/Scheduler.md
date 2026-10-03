# Scheduler foundation

A Scheduler run starts a fresh [correlation context](Correlation.md) and can contribute bounded [Observability](Observability.md) timings and [development profiles](Profiler.md). The read-only [Studio](Studio.md) Scheduler panel uses the same declaration loader as `schedule:list` and can evaluate trusted schedule-definition PHP when opened; it never runs a task or evaluates task due-ness merely to show definitions.

[Doctor](Health.md) checks Scheduler persistence tables without loading or executing scheduled tasks. It does not verify OS cron. Missing tables warn unless Scheduler is explicitly required by readiness policy.

SqueHub Scheduler decides **when** a task is due. [Queue](Queue.md) decides how a scheduled `QueueJob` is delivered and executed. Scheduler definitions remain in application code; only hashed task identities and run/lock state are persisted. Registration never runs task work.

## Register schedules

Place schedule definition files under `Project/Scheduler/`. An enabled Package may contribute files under `Project/Packages/<PackageName>/Scheduler/`; a disabled Package contributes none. For example, use `Project/Scheduler/Cleanup.php` and `Project/Packages/Reports/Scheduler/GenerateDailyReport.php`. The historical `Project/<Name>/Scheduler/` layout remains application-owned schedule code outside the managed Package lifecycle. The loader reads `.php` files in these directories and their nested directories. Files load in a stable order: application definitions, legacy direct directories, then enabled Packages in dependency order. Give each task a unique name across all files.

Definitions load for `schedule:run`, `schedule:list`, and the opt-in local Studio Scheduler panel; they do not load for normal web requests, ordinary Application boot, or unrelated CLI commands. Top-level registration PHP in these files executes when loaded, so avoid task side effects there. The previous single `Project/Schedule.php` file and `Project/Schedule/` directory are not loaded; move their definitions into `Project/Scheduler/`.

```php
<?php

use App\Plugins\Schedule;
use Project\Jobs\CleanupExpiredSessions;

Schedule::job(new CleanupExpiredSessions())
    ->name('cleanup-expired-sessions')
    ->dailyAt('02:00');

Schedule::job(new CleanupExpiredSessions(), connection: 'database', queue: 'maintenance')
    ->name('cleanup-background')
    ->weeklyOn(7)
    ->at('03:30');
```

`App\Plugins\Schedule` and `schedule()` use the same Application-owned `Scheduler`. `App\Plugins\ScheduledTask`, `SchedulerRunResult`, and `SchedulerException` are exact aliases for application type hints and catches. Canonical `App\Scheduler` classes remain available. A job must implement `App\Plugins\QueueJob` or the canonical QueueJob interface. Queue owns its payload encoding, delayed dispatch, retries, and failed-job behavior; Scheduler does not duplicate them.

For an immediate synchronous task, use a class method or an in-process closure:

```php
Schedule::call([CleanupService::class, 'run'])
    ->name('local-cleanup')
    ->weekdays()
    ->at('08:30')
    ->withoutOverlapping(3600);
```

The class is resolved through the Application container when due. A closure requires an explicit `name()` and exists only in the live process; it is never serialized or persisted. `withoutOverlapping()` accepts a timeout in seconds and is supported only for synchronous calls. Scheduler cannot infer when an asynchronously queued job finishes, so it rejects that option for Queue jobs.

Names must be unique within the definitions, stable across runs, at most 128 ASCII bytes, and contain only letters, digits, `.`, `_`, or `-`, beginning with a letter or digit. Do not put secrets or personal data in names shown by `schedule:list`. A single job class or class-method call receives a deterministic hashed default name; give distinct explicit names when registering the same class more than once or when its payload distinguishes separate tasks. Duplicate or incomplete definitions fail clearly before execution.

## Recurrence and timezones

The fluent API provides `everyMinute()`, `everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()`, `hourly()`, `hourlyAt(15)`, `daily()`, `dailyAt('08:00')`, `weekly()`, `weeklyOn(1)` through `weeklyOn(7)`, `monthly()`, `weekdays()`, `weekends()`, and `at('08:30')`. `weekly()` defaults to ISO Monday, `monthly()` to the first day, and `weekdays()`/`weekends()` imply a daily schedule when no frequency was chosen. `at()` uses strict 24-hour `HH:MM`. Other constraints compose by intersection. Frequency methods set their default time; place `at()` after the frequency method to override it.

The default timezone is `APP_TIMEZONE` or UTC. Override one task with `->timezone('Africa/Lagos')`. Scheduler captures one UTC instant for a tick and evaluates every definition in its own timezone against that instant. Seconds within a matching minute do not create another occurrence. A nonexistent local time during the spring DST transition never matches and is skipped. A repeated local time during the autumn transition matches twice; the two UTC minutes have distinct occurrence identities. This behavior is covered with `America/New_York` tests. The host's implicit timezone is not used.

## Run and inspect

```bash
php squehub schedule:list
php squehub schedule:run
```

`schedule:list` shows names, recurrence, timezone, call/job mode, job queue, and overlap timeout. It does not execute tasks or display payloads, arguments, tokens, or a guessed next due time. `schedule:run` evaluates all tasks at one captured instant, claims each due occurrence, runs synchronous calls or dispatches jobs to Queue, and continues after an individual failure. It exits nonzero if any due task or required lock operation fails. CLI failure output is aggregate and does not print application exception text.

Invoke the command once per minute using the host scheduler, for example:

```text
* * * * * php squehub schedule:run
```

SqueHub does not install OS cron, run a Scheduler daemon, or automatically execute schedules on web requests.

[SqueHub Dev](Dev.md) does not add a Scheduler process. `schedule:run` is one-shot; keep a host cron or task-scheduler invocation when scheduled work is needed during development or deployment.

## Persistence, duplicates, and overlap

The shipped default uses the existing Database service. Install `Database/Migrations/2026_09_24_create_schedule_tables.php` explicitly with `php squehub migrate` before running due schedules. Boot and `schedule:list` do not open the database or create the tables. Set `SCHEDULE_DATABASE_CONNECTION` to select a named connection and `SCHEDULE_PREFIX` to separate applications that share a database. An `array` store can be configured for isolated tests or deliberate single-process use; it provides no cross-process protection.

`schedule_runs` has a unique pair of SHA-256 task key and UTC occurrence minute. A guarded insert atomically claims an occurrence under the tested SQLite strategy. A claimed occurrence stays claimed whether work completes, fails, or the runner crashes. This gives at-most-one claimed Scheduler execution per occurrence; a crash after side effects does not trigger automatic replay of that same minute. Run markers currently remain until an application-specific maintenance policy removes them. Live MySQL and distributed filesystem behavior have not been verified.

Synchronous `withoutOverlapping($seconds)` uses a separate `schedule_locks` row. The lock is acquired before the occurrence claim, released after normal completion or failure, and may be reclaimed when its expiry passes after a crash. A task that runs longer than the configured timeout can overlap a later occurrence. Random lock tokens prevent a stale runner from releasing a lock that another runner reclaimed. The run marker prevents duplicate **occurrences**; the overlap lock prevents a different occurrence starting while the earlier call is active. Queued jobs receive duplicate-dispatch protection only. Queue retains its separate at-least-once execution guarantee.

## Phase 24 guarantees and limits

The explicit [Lock API](Locks.md) is available inside application task code when it needs another named lease. Scheduler keeps its existing occurrence claim and overlap store; configuring a Lock backend does not replace either mechanism. In particular, a Queue job dispatched by Scheduler is still subject to Queue's at-least-once execution behavior. A lock lease is bounded and does not fence a worker that resumes after expiry.

Phase 24 [Retry Policy](Retries.md) does not add automatic Scheduler retries. Queue jobs keep Queue's persisted attempts and worker backoff, while an application-owned synchronous call may explicitly use a retry policy for a suitable outbound operation. The opt-in [Circuit Breaker](CircuitBreaker.md) can guard that logical outbound call on one server; it does not change Scheduler's occurrence claim or overlap lock. [HTTP idempotency](Idempotency.md) applies only to opted-in authenticated HTTP mutation routes, not to scheduled tasks or Queue jobs.

## Diagnostics and boundaries

`diagnostics()->snapshot()['scheduler']` contains `evaluated`, `due`, `executed`, `queued`, `skipped`, `failed`, and `time_ms`. `queued` counts successful Queue dispatches, including immediate sync Queue dispatch. `skipped` counts due entries rejected by a duplicate marker or overlap lock. `time_ms` covers the entire tick, including synchronous call work. These request-scoped aggregates retain no name, class, payload, argument, occurrence, or exception text. Separate CLI processes do not form a global metrics view. Queue dispatch also updates Queue's own diagnostics when a request is active.

Scheduler does not require Mail, Notifications, Auth, Account Security, Session, Cache, Storage, Events, or HTTP. Synchronous calls need no Queue service; Queue is resolved lazily for due job definitions. A due job can use the Database or Redis Queue connection through the existing Queue API, including internal queued Mail or Notification delivery jobs dispatched by application code. Scheduler's own cross-process duplicate protection still uses Database persistence; Redis Queue does not change its run claims or overlap locks. There is no Scheduler Redis lock, cluster leader election, raw cron parser, web dashboard, dynamic admin schedule, long-running scheduler worker, next-due prediction, or automatic run-state pruning.
