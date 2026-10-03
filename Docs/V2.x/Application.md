# SqueHub v2 Application foundation

The optional [Application Contract](ApplicationContract.md) is Application-owned. Application and package code may register schemas and outgoing Webhook descriptions, while route declarations can attach operation contracts. The OpenAPI compiler reads those declarations on demand without performing request dispatch, database queries, outbound HTTP calls, or OIDC discovery.

The standard bootstrap also registers the Application-owned [Health service](Health.md). `health()` and `App\Plugins\Health` run fresh liveness, readiness, or Doctor checks on demand. Bootstrap itself performs no health probe. The optional HTTP routes are disabled by default.

This describes the implemented Application foundation. The Request, Response, HTTP Kernel, v2 routing layer, and data foundation are now implemented. The legacy Router remains available for compatibility.

Normal Application boot loads application code and valid enabled [Packages](Packages.md) selected by the [SqueHub Activation Registry](ActivationRegistry.md). A [SqueHub Kit](Kits.md) can publish normal application files through an explicit lifecycle action, but its Kit entry class and lifecycle hooks do not run on ordinary HTTP boot. The registry is Application-scoped and reads activation metadata without creating a file or executing component PHP during inspection.

```php
use App\Foundation\Application;

$app = new Application($basePath);
$app->register(MyProvider::class); // Optional.
$app->bootstrap();

$app->container();
$app->config()->get('app.name');
$app->environment();
$app->isDebug();
```

`Application` owns one instance each of `Container`, `Config\Repository`, and `Environment`. It registers these and itself in the container. Paths such as `appPath()`, `projectPath()`, `configPath()`, and `publicPath()` derive from the base path. An invalid base path or missing config directory fails clearly.

That `Application::basePath()` is the **filesystem project root**, not the HTTP URL mount. The optional `APP_BASE_PATH`/`Config/Http.php` setting selects a separate public path prefix, represented internally by an Application-owned URL base-path service. Root URLs remain the default. Routing, generated links, and mounted browser requests use the URL setting; filesystem source and runtime locations continue to use the Application root. See [mounted requests](Http.md#url-base-path-and-mounted-requests) and [Configuration](Configuration.md).

Code directories and PHP files use a capital first letter, such as `App/Core/View.php` and `Project/Controllers/UserController.php`. Composer handles canonical PSR-4 names first. A bounded fallback then accepts first-letter variants in `App`, `Project`, and `Database` class path segments, including the `Packages` namespace alias. For example, `app\core\view` resolves the same class as `App\Core\View`. The remaining letters must still match. Application path helpers, route loading, and CLI migration lookup accept relevant legacy first-letter variants during migration. Tool-required filenames such as `composer.json`, `public/index.php`, `.env`, and `squehub` retain their standard spelling.

On `bootstrap()`, the Application reads `.env` once, loads top-level `Config/*.php` files in filename order, runs every provider's `register()`, then runs every provider's `boot()`. Repeated successful bootstrap calls do nothing. Duplicate provider registration is ignored; providers cannot be added after bootstrap starts. A failed bootstrap propagates its exception and cannot be retried on that Application instance.

After installation, follow the platform-specific [environment setup steps](Installation.md#configure-the-environment): copy `.example.env` to `.env`, run `php squehub key:generate`, and paste the complete printed `base64:` value into `APP_KEY`. The command does not edit `.env`. Review the application, debug, database, cache, session, queue, mail, and other settings you intend to use, then run `php squehub doctor`. The web entry point returns a no-store 503 setup page (JSON for JSON requests) when `.env` is missing or its parsed values still match `.example.env`. The CLI prints the same safe notice on stderr but leaves commands available, including `key:generate` and `help`. Comments, key order, and line endings alone do not count as configuration. The web check runs on every request, so finishing setup takes effect without restarting `php squehub start`. Invalid or unreadable `.env` files still follow the normal bootstrap error path.

`Config/Debug.php` is an existing executable debug script and is deliberately excluded from array configuration loading. Each other top-level PHP config file must return an array under its filename namespace with a lowercase first letter: both `Config/App.php` and `Config/app.php` expose the `app` key. Config files can use the `$environment` object supplied by the loader:

```php
return ['name' => $environment->get('APP_NAME', 'SqueHub')];
```

The repository supports `get()`, `set()`, `has()`, and `all()`, including dot notation and defaults. `Environment::boolean()` recognizes standard values such as `true`, `false`, `1`, `0`, `yes`, and `no`. Debug output is disabled by default in every environment; set `APP_DEBUG=true` to enable it. During `php squehub start`, edits to `.env`, including `APP_DEBUG`, are picked up on the next browser request without restarting the server. An actual shell environment variable still takes precedence over `.env` and remains fixed for that server process. Normal application code should read configuration rather than `.env` directly.

Templates can use `environment('production')` or `environment('staging', 'production')` for a strict match against the selected Application environment, and `debugging()` for that Application's current debug state. These [Template Utilities](TemplateUtilities.md) are evaluated during rendering, including when compiled template PHP is reused. They are presentation helpers, not a substitute for server-side feature policy or authorization.

`Bootstrap/App.php` returns a booted Application with events, cache, database, validation, session, CSRF, HTTP, routing, and authentication services registered. Both web entry points use `Bootstrap/Web.php`. It captures a Request, runs `config.php` and `Bootstrap.php` for legacy routes, then uses the HTTP Kernel and RouteDispatcher to produce and send a Response. The web bootstrap starts the Application-owned session before loading legacy routes; CLI Application bootstrap does not start a session. It does not include root `Database.php` during normal web startup. `DatabaseServiceProvider` registers the database manager without opening PDO; a connection opens only when database work needs it. The legacy `$router->add()` route files are mirrored into the Application's RouteRegistry during route loading. Root `config.php` adapts the Application configuration to the legacy array and constants, including the historical string value for `app.debug`. CLI bootstraps the same Application. The legacy `App\Core\Service` registry remains a separate compatibility mechanism; `App\Core\Database` now delegates to the Application-owned manager. See [HTTP foundation](Http.md), [sessions](Sessions.md), [CSRF](Csrf.md), [routing](Routing.md), [middleware](Middleware.md), and [database](Database.md).

There is no global `config()` helper yet. Framework code can use `$app->config()` or constructor injection of `Config\Repository`; a global helper can be added when Application access has an explicit lifecycle.

The canonical web Application also registers [CSRF protection](Csrf.md). Its provider adds a global Kernel guard after Session is registered; ordinary CLI Application bootstrap still does not open a native browser session. The token is issued only when requested and remains in reserved session metadata. Source documentation follows the project's internal code standard.

The web entry points and Composer require PHP 8.2 or newer. Run `composer test` for isolated foundation and compatibility tests.

The optional [Webhook manager](Webhooks.md) is Application-owned and resolves named peers only when used. Boot does not send an HTTP request, open a Queue worker, claim a receipt, or persist delivery metadata. Normal application code uses `App\Plugins\Webhook` or `webhooks()`; framework internals use canonical `App\Webhooks` types.

The standard bootstrap registers [account security](AccountSecurity.md) after Auth. Its configuration is validated without opening a database connection or starting a CLI Session; token operations use the configured identity provider when called.

Application bootstrap now configures [logging](Logging.md), [application data cache](Cache.md), and [request diagnostics](Diagnostics.md) before handling HTTP. Logging works from CLI without a session or database connection, and file output opens only when a record is written. Cache supports file, array, Redis, and auto selection; file storage opens only when used. [Compiled Views](CompiledViews.md) use the selected Application's `Storage/Views` derived-artifact directory, separate from data Cache and Session state. They compile on first render or through the optional `view:cache` command; boot itself does not render templates.

The bootstrap also registers [events](Events.md) before later application providers boot. `events()` resolves the current Application's dispatcher; configured and provider listeners belong to that Application's lifetime. In a process hosting more than one Application, resolve `EventDispatcher` from the intended Application container explicitly.

The standard bootstrap also registers [application Storage](Storage.md). `storage()` proxies the configured default drive and `storage()->drive($name)` selects a named drive. The service is Application-owned and local roots are created only on first use. Application files default to `Storage/Files`, separate from Logger and Cache runtime directories. In a process hosting multiple Applications, resolve `StorageManager` from the intended Application container explicitly.

The standard bootstrap registers the [outgoing HTTP Client](HttpClient.md) separately from the incoming HTTP Kernel. `App\Plugins\Http` and `httpClient()` resolve the Application-owned client. Boot does not open a network connection, and HTTP fakes/captured test requests do not cross Application instances. The shipped transport requires cURL when a real outbound request is made.

The bootstrap also registers the opt-in [Lock](Locks.md), [HTTP idempotency](Idempotency.md), and [circuit breaker](CircuitBreaker.md) services without acquiring a lease, creating a record, or contacting their backends during ordinary boot. [RetryPolicy](Retries.md) is an immutable timing value used only when an outbound caller explicitly requests retries. The file backends use private runtime roots under `Storage/`; applications that need multi-server coordination must explicitly select and qualify an appropriate shared backend. None of these services changes Queue's at-least-once delivery or makes external mutations exactly once.

Optional [Agent and AI integration](AgentAndAI.md) is a local CLI/MCP inspection workflow, not a service that runs during an ordinary HTTP request. `php squehub agent:status` reports its effective capabilities; `php squehub agent:mcp` deliberately starts the STDIO protocol process when the optional SDK is installed. Its manager uses the selected Application root, current version, already-registered metadata or validated route cache, and `Config/Agent.php` grants. The Agent CLI skips enabled Package provider boot and does not load Project route-source PHP to complete an inspection; absent declarations can produce a partial result. Default capabilities are read-only. No LLM client, remote listener, shell, source-writing operation, or Agent service provider is required for normal Application boot.

Phase 25A–25F is complete within the verified Windows and user-run native Linux profiles. The official `mcp/sdk` 0.8.1 client interoperated with SqueHub's local STDIO server on Linux. SqueHub advertises the 2025-11-25 handshake revision; the SDK itself also implements the 2026-07-28 modern/stateless era. The [release record](ReleaseReadiness.md#phase-25-agent-and-ai-integration--complete) gives exact totals and cleanup evidence.

The standard bootstrap also registers [Crypt](Cryptography.md) lazily. `App\Plugins\Crypt` and `crypto()` resolve the Application-owned manager. Boot does not decode `APP_KEY` or require sodium/OpenSSL until Crypt is used. `CRYPT_DRIVER=auto` prefers sodium, then uses OpenSSL AES-256-GCM when sodium is unavailable.

The [authentication foundation](Authentication.md) validates its configuration during bootstrap without starting a session, connecting to a database, or requiring a User model. Guard instances belong to the Application; resolved identity objects are reset at each Kernel request. The [authorization foundation](Authorization.md) registers after Authentication, validates explicit global abilities and policy class mappings, and evaluates them only when requested. Its manager belongs to the Application; authorization decisions are never cached.

Named [personal access token guards](ApiTokens.md) reuse this Auth and identity-provider ownership. A guard's token repository is resolved on first named guard or token-service use, and token authentication does not start Session or change the default browser guard. The token identity and safe metadata are request scoped; route middleware selects the named guard for downstream `auth()->user()` and Authorization checks. The database token table is installed explicitly through migrations, never at Application boot.

The standard bootstrap registers [rate limiting](RateLimiting.md) as a separate, lazy Application service. `rateLimiter()` exposes direct `consume()` and `clear()` operations; route middleware uses explicitly registered named policies. Boot creates no rate-limit files and does not resolve named policy classes.

The standard bootstrap also registers [Mail](Mail.md). `mailer()` resolves the Application-owned service. Configuration is validated at boot without connecting to SMTP; message preparation and transport selection occur only when a send is requested. The historical `App\Core\Mail` builder remains a separate compatibility path using the same environment-backed SMTP configuration.

The standard bootstrap registers [Notifications](Notifications.md) after Mail. `notifications()` resolves the Application-owned synchronous channel manager. Its Array channel works independently; the Mail channel resolves `Mailer` only when selected. No notification database, queue, session, or transport connection is created during bootstrap.

The standard bootstrap also registers [Queue](Queue.md). `queue()` resolves an Application-owned manager with a synchronous default and optional persistent Database or Redis connections. Registration opens no database connection, Queue table, or Redis socket. Applications opt into background work by selecting Database Queue and installing its migration, or selecting [Redis Queue](RedisQueue.md), then running `php squehub queue:work`. `QUEUE_CONNECTION=auto` selects Redis if available and Database otherwise on first Queue use; it never falls back to sync. `Mail::send()` and ordinary Notifications remain synchronous; `Mail::queue()` and Notifications implementing `ShouldQueue` use the existing Queue worker. On the sync connection, those queued APIs execute immediately.

`Queue::afterCommit()` and `queue()->afterCommit()` defer work until the outermost transaction on the selected Database connection commits; without a managed transaction they dispatch immediately. Queued Mail accepts `afterCommit: true`, and queueable Notifications may override `queueAfterCommit()` to request the same timing. Rollback discards pending work. A post-commit Queue persistence failure cannot roll back the completed business transaction. Workers can be bounded with `--max-jobs`, `--max-time`, `--memory`, and `--timeout`, then asked to reload code using `php squehub queue:restart` under an external supervisor.

The standard bootstrap registers [Scheduler](Scheduler.md) after Queue. `schedule()` resolves the Application-owned definitions and lock service; boot neither loads files under `Project/Scheduler/` or package `Scheduler/` directories nor executes work or opens schedule tables. Only `schedule:run` and `schedule:list` load those definitions. Due Queue jobs resolve Queue lazily, while synchronous calls can run without it.

The standard bootstrap also registers optional [Redis](Redis.md). `redis()` resolves the Application-owned manager, and `App\Plugins\Redis` provides the short application API. Boot, manager resolution, and named connection lookup perform no Redis network I/O. Cache, Session, Rate Limit, and Queue may select Redis explicitly or through `auto`; only first resolution of an auto-selected service probes availability. Redis remains optional for shared hosting.


## Application-facing Plugins import

Application code may import `App\Plugins\Route`, `App\Plugins\View`, `App\Plugins\Model`, `App\Plugins\DB`, `App\Plugins\Cache`, `App\Plugins\Storage`, `App\Plugins\Mail`, `App\Plugins\Notification`, `App\Plugins\Queue`, and `App\Plugins\Schedule`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.

`App\Plugins` is the stable application-facing gateway. Its facades resolve the currently bootstrapped Application's existing services; it creates no separate container or lifecycle.
