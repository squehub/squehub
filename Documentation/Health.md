# Health, Doctor, and infrastructure inspection

SqueHub keeps three operational views separate. `diagnostics()->snapshot()` contains aggregate request metrics. Health answers whether an application is alive or ready to serve its configured workload. Doctor inspects the current deployment and reports safe configuration and dependency outcomes. None of these operations repairs the deployment.

The optional local [SqueHub Studio](Studio.md) shows liveness without automatically running readiness or Doctor probes. The [development profiler](Profiler.md) and [Observability](Observability.md) measure completed work; their timing evidence is not a Health pass.

## Commands

```bash
php squehub doctor
php squehub doctor --json
php squehub infrastructure
php squehub infrastructure --json
```

SqueHub offers opt-in [deployment proof profiles](DeploymentProof.md) to
`doctor`: `--profile=shared-hosting|single-server|worker|multi-server`.
Profile output is a separate, time-stamped evidence record with configured,
reachable, and end-to-end-verified tiers; unknown evidence stays unknown.
`--probe` asks existing bounded local checks to test selected backends; it does not probe a Vite server.
An explicit `--verify-url` can sample a web deployment without printing
response bodies. No profile starts a worker, deploys code, restores data,
or proves another host shares the same key and build. The original
`doctor` and `infrastructure` reports keep their existing contracts.

Doctor exits `0` when no check fails, including reports with warnings, and `1` when any check fails. Each result has a stable name, category, `pass`/`warning`/`fail`/`skipped` status, safe summary, reason code, and duration in milliseconds. The JSON report also includes counts. Order is stable: runtime, application, database, frontend, Redis, Cache, Session, Rate Limit, Queue, Scheduler, Storage, Mail, outgoing HTTP, Crypt, aggregate Package and Kit status, then registered package checks. Reports are fresh on each call. A failing check does not stop subsequent checks.

`infrastructure` shows only configured and selected driver names and a safe selection reason for Cache, Session, Rate Limit, Queue, Storage, and Scheduler. It uses the services' own `InfrastructureSelection` rather than predicting a different backend. Inspecting an unresolved `auto` service may perform its normal first Redis capability probe. A selection remains fixed for that Application instance; later outages never silently switch explicit Redis or persistent Queue work to another backend. Driver names do not prove a worker or cron process is running.

[Locks](Locks.md), [HTTP idempotency](Idempotency.md), [Retry Policy](Retries.md), and the [Circuit Breaker](CircuitBreaker.md) do not add Doctor or `infrastructure` checks. Doctor does not acquire a lock, claim an idempotency key, execute a retry, read circuit state, or verify their migrations. Its database check and Redis PING can show basic reachability, but cannot prove atomic ownership, HTTP replay, half-open admission, or a multi-server coordination boundary. Qualify those contracts with the focused process and guarded live-backend tests in their guides.

## Programmatic checks

```php
use App\Plugins\Health;

$live = Health::live();
$ready = Health::ready();
$doctor = Health::doctor();

if ($ready->hasFailures()) {
    // Keep this report in an operator-only surface.
}
```

`health()` returns the same Application-owned `HealthManager`. `HealthReport::healthy()`, `hasFailures()`, `hasWarnings()`, `counts()`, `results()`, and `toArray()` describe one run. `HealthResult` has the corresponding outcome fields and static `pass`, `warning`, `fail`, and `skipped` constructors. A separate Application has its own check registry and fresh reports.

Liveness reports only that the booted framework can respond. It does not contact the database, Redis, SMTP, or external APIs. Readiness checks environment/debug policy, the default database and Storage by default, plus selected Cache, Session, and Rate Limit backends. A non-sync Queue selection is checked; the sync Queue has no external backend. `Config/Health.php` can set `require_database`, `require_storage`, `require_queue`, `require_scheduler`, `require_crypt`, or `require_http_client`. The last four default to false. Optional Redis failure does not fail readiness for file/native/array backends. An explicitly selected Redis backend is probed and fails readiness when unavailable.

## Doctor's read-only probes

- Runtime: PHP version from `composer.json`, PDO/JSON/filter extensions, and selected PDO driver.
- Application: environment identifier, private `.env` setup, and production debug policy. Missing `.env` or an unchanged copy of `.example.env` fails Doctor/readiness; `APP_DEBUG=true` in production also fails.
- Database: `SELECT 1` on the default connection. MySQL connection attempts use a five-second timeout. Other configured databases are not contacted automatically.
- Frontend: a PHP-only application (`frontend.adapter=none`) is `skipped` with code `not_selected`. For a selected Vite adapter, Doctor checks whether the configured manifest and its advertised build files are valid. A verified build passes even when Node or local Vite packages are absent from the serving host. In production, a missing or invalid build fails with `build_missing`; in development it warns with `build_missing` or `tooling_unavailable` when local Node/Vite is absent. Invalid frontend configuration fails with `frontend_invalid`. Doctor does not install packages, run a build, start Vite, or probe its port. Use [frontend profiles](FrontendProfiles.md) for the explicit build and `frontend:status --probe` commands. Frontend is a Doctor check, not a readiness or liveness dependency.
- Redis: existing `RedisManager::capability(probe: true)`; optional absence is skipped or warned. Redis PING is the only Redis command used.
- Cache, Session, Rate Limit: their actual fixed driver selection. No Cache entry is written, Session started, or limiter permit consumed. A selected Memcached Cache reports extension/configuration availability but does not prove server reachability. If `ext-memcached` is absent, Doctor reports `extension_missing`; resolving that selected Cache service fails.
- Queue: selected backend, Redis reachability if selected, or the required database Queue tables and failed-job payload column. It does not dispatch jobs or claim reservations.
- Scheduler: configured store and, for database storage, required tables. Missing tables warn unless Scheduler is marked required. No schedules or OS cron are executed or verified.
- Storage: default drive configuration and local root/nearest existing parent's writability. A selected S3 drive checks optional AWS SDK availability and configuration without listing or writing objects. No probe file is created. Writability is a filesystem permission estimate, not a guarantee against later disk exhaustion; S3 reachability remains untested here.
- Mail: default transport, configured SMTP host and sender, production TLS peer policy, or selected Resend/Postmark token/sender configuration. No SMTP connection or provider HTTP request is made. A configured provider is reported as not probed, not as live-delivery verified.
- HTTP Client: cURL availability and production TLS peer policy. No external request is sent.
- Crypt: an in-memory fixed-data MAC and verification through the existing Crypt manager validates backend and key without exposing or persisting them. Missing optional Crypt configuration warns; `require_crypt=true` makes it fail.
- Packages: the [Activation Registry](ActivationRegistry.md) supplies static installed, enabled, disabled, and broken Package counts. A broken Package fails Doctor. Where a bounded, validated dependency name fits, Doctor adds one actionable requirement hint; source labels, raw state records, and executable Package entries remain out of the result.
- Kits: the same registry supplies static installed, enabled, disabled, and broken [Kit](Kits.md) counts. A broken Kit composition fails Doctor and may include one validated Package requirement hint. The check reads metadata and saved ownership only; it never includes the Kit entry or runs lifecycle hooks. The registry can also report stale legacy metadata and write-readiness issues without changing files.

The `doctor` CLI uses Package inspection mode, so enabled Package entries and their custom checks are not booted by that command. It also never runs Kit lifecycle hooks. A programmatic `Health::doctor()` on a normally booted Application runs any registered custom checks. Configured Redis client/network timeouts bound its PING. Custom package checks must bound their own network work. Doctor does not run migrations, install extensions, change `.env`, generate keys, change permissions, restart workers, or perform any repair. It reports current runtime availability, not distributed correctness or end-to-end service delivery.

## Optional HTTP endpoints

`HEALTH_ENDPOINTS_ENABLED=false` is the shipped default. Set it to `true` to register `GET /health/live` and `GET /health/ready` with route names `health.live` and `health.ready`. In `Config/Health.php`, `middleware` is an optional ordered list of existing route middleware aliases/classes/objects. No Auth is attached automatically; load balancers may need anonymous access. Protect or restrict these routes at the deployment edge according to your topology.

Liveness returns `200 {"status":"ok"}`. Readiness returns `200 {"status":"ready"}` or `503 {"status":"unavailable"}`. Both use `application/json` and `Cache-Control: no-store, max-age=0`. Public responses never include the detailed report, connection names, paths, or exceptions. Keep Doctor JSON on an operator-only channel.

When these exact opted-in GET paths are requested, the web entry point routes them through the normal HTTP Kernel without loading legacy routes or starting the legacy browser Session. This keeps liveness independent of a failing Session backend and prevents legacy debug markup from entering health JSON.

## Application and package checks

Register a check through an application or package provider after Health is registered:

```php
use App\Plugins\{Health, HealthCheck, HealthResult};

final class PaymentConfigurationCheck implements HealthCheck
{
    public function check(): HealthResult
    {
        return HealthResult::pass('payments', 'package', 'Payment settings are present.');
    }
}

Health::manager()->register('payments', PaymentConfigurationCheck::class);
```

Class checks are instantiated lazily through the container, so constructor injection works. A callable or an already-created `HealthCheck` object also works. Pass `ready: true` to include the check in every readiness run; otherwise it runs only in Doctor. Duplicate names and reserved core names are rejected. Custom results must use the registered name and `package` category. Thrown exceptions and malformed results become safe `check_failed` outcomes while other checks continue. Return only bounded, operator-safe summaries and stable codes. Configured secrets are additionally passed through SqueHub's existing `SecretRedactor`; arbitrary application data must never be placed in a summary. A package should inspect local configuration by default and only contact an external API under an explicit application policy with its own timeout.

## Guarantees and limits: privacy and deployment

Health reports never include raw PDO/Redis/SMTP/cURL/Crypt exception text, SQL, credentials, APP_KEY, Redis URLs or hosts, absolute Storage paths, Queue payloads, HTTP bodies, or package exception messages. Public endpoints expose only one status word. Health results are not copied into request Diagnostics, avoiding recursion and retention of check names. A report can still reveal high-level deployment state, so restrict CLI/JSON access appropriately.

On shared hosting, file Cache, native Session, local Storage, and Database Queue can be ready without Redis. The file/array selections are not distributed infrastructure. Doctor does not prove Redis Queue atomicity, active workers, configured cron, successful SMTP or HTTP-provider delivery, Memcached reachability, or S3 object operations. These need separately guarded operational tests for the selected deployment. See [optional infrastructure adapters](InfrastructureAdapters.md).

The optional local [Agent/MCP server](AgentAndAI.md) can read bounded aggregate Health and infrastructure summaries through its default `read_health_metadata` capability. It omits application-configured connection, drive, transport, and Queue connection labels and Scheduler task definitions while retaining selected driver types, states, and counts. It does not create a public Health endpoint or turn a configured driver into an end-to-end operational proof. The Agent cannot read raw credentials, database rows, log lines, or Queue payloads through this resource.
