# SqueHub Studio

SqueHub Studio is an optional, read-only local development inspector. It brings safe Application, contribution, Health, Diagnostics, Profiler, and framework-cache evidence into one place. It does not install an application route, a database console, an editor, or a Package management UI.

## Enable and start

In your private `.env`:

```env
APP_ENV=development
STUDIO_ENABLED=true
PROFILER_ENABLED=true
```

`PROFILER_ENABLED` is optional. It captures bounded request/work timelines for the recent-requests view; Studio itself can show static metadata without it. `APP_DEBUG=true` alone does not enable either tool.

```bash
php squehub studio
# Or choose a specific local port:
php squehub studio --port=8100
```

The command prints its local URL. It binds only `127.0.0.1` and, without an explicit port, uses the first available port from 8100 to 8199. There is no public-host option. Keep this server local; Studio has no production remote-access authentication design. `APP_ENV=production` or `STUDIO_ENABLED=false` refuses startup, and each Studio HTTP request checks the current policy again. A changed `.env` therefore takes effect without trusting a startup-only switch. A normal `php squehub start` server does not expose Studio.

With `APP_BASE_PATH=/app`, the Studio URL is under `/app/studio`. Its stylesheet and official SqueHub icon use mount-aware public asset URLs. The private Studio server serves only these fixed assets and Studio pages, never arbitrary physical files from the repository.

## What Studio can inspect

Studio presents fixed, bounded projections. A label such as **known**, **configured**, **observed**, or **unavailable** describes the evidence behind a panel; an unavailable value is not guessed. Exact panels depend on enabled services and the metadata already present in the selected Application.

| Area | Safe evidence |
| --- | --- |
| Overview | Application name and environment, PHP/runtime identity, debug and profiler state, framework cache state, and selected frontend adapter status where configured. |
| Routes | Safe metadata projected from the Application's registered routes, using the same authoritative registry as `route:list`: method, application-relative path, name, handler label, middleware order, host, constraints, bindings, fallback flag, owner, and contract presence where known. No handler invocation. |
| Packages and Kits | Discovered activation state, dependencies, and source provenance from the existing Activation Registry. No entry hook execution. |
| Scheduler | Registered task definitions from the same declaration loader used by `schedule:list`: name, recurrence, mode, queue, and timezone. No due-time evaluation or task execution. |
| Recent work | Bounded Profiler records with correlation ID, operation, duration, and a safe timeline. The separate Observations page aggregates fixed database, Cache, Event, Queue, Scheduler, HTTP Client, Mail, Notification, and Storage count/time categories from at most 20 records. Disabled profiling means no records. |
| Infrastructure | Configured driver names and passive state for Cache, Queue, Scheduler, Events, Broadcasting, Storage, Redis, and related services where a safe inspection API exists. Translation shows configured locale labels and PHP `intl` availability without reading catalog values. S3 SDK and Memcached extension availability are static capability checks; Mail provider token presence is a boolean only. No provider reachability or delivery is checked. |
| Health and contract | Safe liveness or declared contract summaries without executing application handlers. Backend readiness probes are not run simply to draw the overview. |

Studio reads the registered route metadata used by `php squehub route:list`; an Application Contract can enrich a route but is not required for that route to appear. The shipped `GET /` route named `welcome.page` is a Closure route and appears with the safe handler label `Closure`. Studio neither invokes nor serializes that Closure. Controller labels are derived without constructing or invoking controllers, and model-binding metadata does not perform a database lookup. Unknown individual fields are labelled unavailable without hiding the route.

The optional local [Agent/MCP server](AgentAndAI.md) reuses selected Studio projections for activation and safe Health/infrastructure summaries. Its route/contract resources deliberately use only the already-registered RouteRegistry or an existing validated route cache; unlike Studio's normal route inspection path, they do not load Project route-source PHP and may report a partial inventory. Its capability checks and STDIO transport are separate from Studio's development-only browser server; starting either tool does not start the other. Neither tool exposes arbitrary route handler source or turns an inspected command into an executed command.

When the Vite frontend adapter is selected, the Overview shows whether development mode is configured and whether the local production manifest validates. It does not start Node, contact a development server, or disclose development URLs, source paths, manifest contents, or configuration values. An application using the default `none` adapter has no frontend card because a build is not required.

Obtaining the registry follows normal Application route registration: an active route cache may replay declarations, while an absent or stale cache falls back to ordinary route-source loading. This may evaluate application route registration PHP at bootstrap, as `route:list` does; it does not dispatch a request, run route middleware, or execute route handlers. Studio does not predict what arbitrary registration PHP would produce without running that normal registration path. Enabled Package routes follow the same registry path; disabled Packages are not booted merely for Studio. A Closure action prevents full route caching but does not prevent route inspection. See [Framework performance caches](PerformanceCaching.md).

The Scheduler panel loads trusted application and enabled Package schedule declaration files through the same loader as `php squehub schedule:list`. Their top-level PHP may execute during declaration loading, so keep that code limited to registration. Studio reads definitions after loading; it does not determine which tasks are due, run a task, dispatch a scheduled job, or run Package/Kit entry hooks solely to inspect schedules. Disabled Package definitions remain absent.

The Events and Broadcasting panels summarize registrations present in the Studio inspection Application. Studio boot intentionally does not run Package provider hooks, so registrations made only by those hooks may be absent. Treat these panels as current in-memory evidence, not a complete inventory of every registration the normal application could activate.

Profiler profiles are local development observations, not full request captures. They contain allowlisted operation and timing metadata, not SQL bindings, request bodies, cookies, Queue payloads, Mail bodies, or authentication secrets. Studio does not display raw configuration values, credentials, cache keys/values, private file contents, arbitrary log lines, physical paths, or database rows. The log viewer remains unavailable because the existing log format does not establish a safe structured and redacted tail contract.

## Security and limits

Studio accepts read-only GET/HEAD requests on fixed paths. Its server rejects non-loopback peers and unexpected Host values to limit local DNS-rebinding exposure. The UI uses escaped HTML and an external stylesheet; it does not require Node.js or a weakened global content-security policy. No POST control or browser mutation endpoint exists.

Studio cannot run SQL merely to list routes, retries, Scheduler tasks, events, broadcasts, Package/Kit lifecycle actions for inspection, shell commands, or route handlers. Normal Application bootstrap may evaluate route registration PHP, and the Scheduler panel may evaluate schedule declaration PHP as described above. `Doctor` and backend reachability checks are separate explicit operations; opening the dashboard does not connect to every configured backend. Its own render/inspection failure does not change a normal application's routes or worker behavior.

The local file Profiler remains bounded by `Config/Profiler.php` retention and byte limits. File Cache and route/config artifacts are private runtime state. Studio is not a distributed monitoring platform or an OpenTelemetry collector. See [Observability](Observability.md), [Profiler](Profiler.md), [Correlation](Correlation.md), [Diagnostics](Diagnostics.md), and [Deployment](Deployment.md).
