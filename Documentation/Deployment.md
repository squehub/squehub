# Deploy a SqueHub v2 application

This checklist applies to a verified SqueHub v2 source or package. Check the selected ref, environment, and enabled services before serving production traffic. See [v2 status](Status.md) for compatibility boundaries.

## Web and PHP

- Run a supported PHP 8.2+ build with Composer's required extensions and the drivers selected by the application.
- For an ordinary application, set the Apache/Nginx document root to `public/`; rewrite application requests to `public/index.php`. Never serve `.env`, `App/`, `Config/`, or the extensionless `squehub` CLI file as static files.
- Use HTTPS, set `APP_DEBUG=false`, and configure session cookies with `SESSION_SECURE=true`, `SESSION_HTTP_ONLY=true`, and an appropriate `SESSION_SAME_SITE` value.
- Give the PHP process a writable protected `session.save_path` for native sessions and writable selected runtime roots. A missing session path can break ordinary web requests.
- Keep `.env` private and specific to the deployment. Generate a distinct `APP_KEY` before storing encrypted data; rotate only with the [Cryptography](Cryptography.md) migration policy.

The canonical source for ordinary static files is the application-root `Assets/` directory; the browser URL is lowercase `/assets/...`. The repository has a root `index.php` for the official website's guarded root-hosted layout and a `public/index.php` for the preferred public-only document root. Both enter the same `Bootstrap/Web.php` lifecycle. Before application boot, its bounded static responder serves GET/HEAD `/assets/...` from an exact path in root `Assets/`, then from `public/assets/` as a compatibility source. Package asset URLs still pass through the application's Package activation policy. Keep generated frontend output under `public/assets/build/`, where its manifest expects it; avoid a same-name root asset that would take precedence.

The official root-hosted website keeps reviewed documentation files at site-root `Assets/docs/...` and its social image at `Assets/images/og/squehub.png`. It does not need duplicate copies under `public/assets/`. On a case-sensitive host, physical `Assets/` does not match a lowercase `/assets/` URL by itself. The root `.htaccess` sends `/assets/...` to root `index.php` and denies direct `/Assets/...` requests; the shared responder then checks root `Assets/` before `public/assets/`. It also denies direct access to private source and state, including direct requests for the Markdown guides in `Documentation/`, and direct browser requests under `/public/`. With `public/` as the document root, `public/.htaccess` sends `/assets/...` through `public/index.php` and the same responder, which can read the sibling root `Assets/`. Preserve the equivalent routing and private-path protections if Apache rules are replaced by another server. Use the [public-only shared-host layout](#subdirectory-mounts-and-shared-hosting) when a generic host cannot change its document root.

A local Apache/XAMPP root host with a physical `Docs/` directory may need the host-specific `Options -Indexes`, `DirectorySlash Off`, and `RewriteOptions AllowNoSlash` directives so Apache does not redirect lowercase `/docs` before routing. Do not upload that Apache-only override to LiteSpeed or assume a shared host permits it. For production, keep source `Docs/` outside the served root, prefer a `public/` document root, or configure equivalent routing through the host's supported controls. Verify that lowercase `/docs` reaches the application and direct uppercase `/Docs/...` source requests remain denied.

The built-in `php squehub start` command is for local development. It picks an available port from 8000–8099 when no port is specified and serves `public/`. With `APP_BASE_PATH=/app`, it displays an `/app` URL and routes mounted requests through the application. Its router uses the same bounded responder for validated GET/HEAD `/app/assets/...` URLs, preferring root `Assets/` and then `public/assets/`. It does not expose arbitrary framework files or make every `public/` file available through the mount. [SqueHub Dev](Dev.md) can coordinate that local server with Doctor preflight and an explicitly selected persistent Queue worker. Neither command replaces a production web server or worker supervisor.

[SqueHub Studio](Studio.md) uses a separate fixed-path local inspector server. It requires `APP_ENV=development`, explicit `STUDIO_ENABLED=true`, and a loopback request; it has no production remote-access design. Keep it out of production supervisors and public reverse-proxy mappings. `APP_DEBUG` does not authorize Studio access.

## Frontend assets and browser shells

The PHP-only default and native browser modules need no Node.js runtime in development or production. If a Vite profile is selected, build it deliberately with `php squehub frontend:build` and deploy its matching `public/assets/build` files and manifest alongside the PHP release. This generated directory remains a supported `public/assets/` source; do not move it into root `Assets/` without changing the build configuration and manifest contract. Production asset resolution uses that manifest and rejects missing or unsafe selected output; it does not fall back to HMR or a localhost development URL. `php squehub frontend:status` reports the selected adapter and build state without serving assets. See [frontend assets](FrontendAssets.md) and [profiles](FrontendProfiles.md).

Frontend tooling passed Windows and native Linux checks for real Vite, React, and Vue builds, manifest checks, and a supervised local HMR/proxy smoke. This qualifies the tested tooling paths; it does not establish live Apache frontend delivery, cPanel mapping, or every reverse-proxy deployment. Those remain separate release-profile checks documented in [release status](Status.md).

The backend remains the web document origin. A Session-authenticated frontend should receive a dynamically rendered `View::response()` shell containing a session-bound CSRF token, with `Cache-Control: private, no-store`. Configure [SPA fallback](SpaRouting.md) only when client-side paths need it; ordinary routes, 405 responses, API paths, and missing assets retain their normal behavior. A production Vite build supplies public JavaScript/CSS, not a static replacement for that per-session HTML shell. For ordinary applications, keep `public/` as the only served filesystem tree and verify that build URLs use the selected public mount rather than a host-root assumption.

## Subdirectory mounts and shared hosting

An Application may be mounted at a public URL such as `https://example.com/app/` without changing its `Route::path('/users')` declarations. Set `APP_BASE_PATH=/app` for that deployment; `Config/Http.php` reads the optional value as `http.base_path`. The root default is the empty string and retains existing `/` behavior. `/app` is a URL prefix only: it does not change the Application filesystem root, `Project/` layout, or Route group prefixes, and it is not inferred from `SCRIPT_NAME` or a forwarding header.

Map **only the application's `public/` directory** to the web server's `/app/` URL. On an Apache host with virtual-host control, an Alias or equivalent directory mapping can point `/app/` at the physical `public/` directory while the rest of the framework stays outside the served tree. Ensure Apache allows the public directory's rewrite rules: the supplied `public/.htaccess` sends `/app/assets/...` through the entry point and rewrites other non-file/non-directory paths to a relative `index.php`. The responder reads an exact root `Assets/` file first, then a `public/assets/` file. Do **not** make the repository root, `App/`, `Project/`, `Config/`, `.env`, `Storage/`, or `squehub` web-readable to obtain a subdirectory URL.

On shared hosting that exposes only `public_html/app/`, keep the private application, including root `Assets/`, at a sibling location such as `/home/account/squehub/`. Place **only** the public entry point, public `.htaccess`, and any generated `public/assets/` files in `/home/account/public_html/app/`. A copied `public/index.php` is **not** directly portable to this split layout: its `dirname(__DIR__)` assumes the private framework is the parent of `public/`. Replace that one public entry point with an operator-owned path to the private bootstrap, for example:

```php
<?php
require '/home/account/squehub/Bootstrap/Web.php';
```

The example path must be changed to the actual private installation. Ensure the PHP account may read that bootstrap and its Composer dependencies while the web server cannot serve them as static files. Keep `APP_BASE_PATH=/app`, preserve the public `.htaccess` behavior, and verify file permissions. Do not copy the entire repository into `public_html/app/` or add a root `.htaccess` rule that makes private source reachable.

For example, with `APP_BASE_PATH=/app`:

| Public request or generated path | Application behavior |
| --- | --- |
| `/app/` or `/app` | Application root route `/`. |
| `/app/users/15?tab=profile` | Route `/users/{id}` with the query intact. |
| `route('users.show', ['id' => 15])` | Public path `/app/users/15`. |
| `asset('/assets/app.css')` | Public path `/app/assets/app.css`; the responder reads root `Assets/app.css` first, then `public/assets/app.css`. |
| `asset('/assets/default/img/squehub-icon.png')` in the supplied Welcome View | Public path `/app/assets/default/img/squehub-icon.png` from the same View source used at root. |
| Internal redirect `/login` | Response location `/app/login`, with no second prefix for an already mounted target. |
| `/application` or `/users` | Outside this Application's mount; safe 404 at the framework boundary if forwarded to it. |

Application paths are case-sensitive and checked at segment boundaries; `/application` is not within `/app`. Encoded separators and traversal do not bypass the normal routing checks. An exact file at root `Assets/app.css`, or its compatibility location `public/assets/app.css`, has the application asset reference `/assets/app.css` and, under this mount, the browser URL `/app/assets/app.css`. `asset()` returns that public URL, never a filesystem location. Views and forms should use `route()` for named application links/actions and `asset()` for local public files; template-owned `@style` and `@script` local root paths are mounted when rendered. Literal HTML paths are not rewritten. Package and Kit code can keep application-relative route and asset declarations. API scopes and CORS paths also remain application-relative, while an Origin such as `https://example.com` never includes `/app`. Static contract/OpenAPI operation paths remain portable; configure a generated SDK's runtime base URL as `https://example.com/app` for this deployment.

Session cookies retain their configured Path. `SESSION_PATH=/` remains the default and works, but a mounted application should usually choose `SESSION_PATH=/app` so its session cookie is not sent to unrelated paths on the same host. Use a distinct `SESSION_NAME` for separate same-host applications and review Session storage isolation. Typed Response cookies keep their explicit Path; SqueHub does not silently narrow them to the mount. See [Sessions](Sessions.md).

`APP_BASE_PATH` does not configure an absolute public origin. For reset or verification links, combine an operator-reviewed canonical HTTPS origin with a generated mount-aware `route()` path. The legacy `url()` and `App\Core\Verification::tokenUrl()` helpers still read raw server variables and are not proxy- or mount-aware; avoid them for security-sensitive links. An explicit trusted-proxy profile may supply effective request Host/scheme, but it does not make arbitrary incoming Host values a safe canonical link policy. No `X-Forwarded-Prefix` trust or automatic HTTPS redirect is added.

Windows Apache/XAMPP checks covered a public-only `/app/` Alias, `APP_BASE_PATH=/app`, a private writable session path, successful HTML and asset responses, safe missing-route responses, and rejection outside the mount. Root-domain cPanel hosting through LiteSpeed has also been reported. Test route generation, redirects, documentation routes, and asset URLs in the actual deployment; Linux Apache and cPanel subdirectory-hosting layouts require their own checks.

To repeat the Apache check on a disposable installation, keep the private application outside the served tree and expose only its `public/` directory at `/app/` through an operator-owned test virtual host or Alias. Enable `mod_rewrite` and permit the supplied `public/.htaccess` rewrite in that public directory; use an isolated loopback listener and a writable private `session.save_path`. Give the disposable application's `.env` a fresh `APP_KEY` and `APP_BASE_PATH=/app`. Then request these paths against that listener, substituting its local port:

```powershell
$origin = 'http://127.0.0.1:PORT' # Replace PORT with the isolated Apache listener port.
curl.exe -i "$origin/app/"
curl.exe -i "$origin/app/assets/default/favicon/site.webmanifest"
curl.exe -i "$origin/app/does-not-exist"
curl.exe -i -H 'Accept: application/json' "$origin/app/api/missing"
curl.exe -i "$origin/"
```

Expect, in order: 200 HTML from the root View; 200 with the exact public asset bytes; 404 HTML; 404 JSON with `Content-Type: application/json`; and 404 outside the mount. The supplied manifest is a convenient current-source fixture; use a known static asset from the disposable copy if it changes. Compare the returned bytes with that physical public file, not merely the status code.

For an extended qualification, add a named route and an internal redirect **only in the disposable application**, then confirm the public `/app/...` link and `Location` header before removing those test routes. Confirm `App/`, `.env`, `Project/`, and `squehub` are not web-readable. Stop the isolated Apache process and remove only the disposable listener configuration, public mapping, and test copy after checking their exact paths. This procedure does not test production TLS, proxy rules, or cPanel's actual mapping; qualify those separately on the target host.

## Reverse proxies and allowed hosts

The default `Config/TrustedProxies.php` configuration trusts **no** proxy and reads **no** forwarding-header profile. Direct Apache/XAMPP, shared hosting without a known proxy, and `php squehub start` need no proxy setup. Their request IP, scheme, and Host come from the direct server connection. SqueHub does not infer proxy trust from localhost, private networks, Docker, Apache/Nginx, or the mere presence of a forwarded header.

When a known reverse proxy or TLS terminator sits directly before PHP, configure its **immediate peer address** and the one forwarding format it emits. For example, after replacing the documentation address with the actual peer address:

```php
<?php

// Config/TrustedProxies.php
return [
    'proxies' => ['192.0.2.10'],
    'profile' => 'x-forwarded',
    'allowed_hosts' => ['app.example.com'],
];
```

The example selects `X-Forwarded-For`, a single `X-Forwarded-Proto`, and a single `X-Forwarded-Host`. Choose `profile => 'forwarded'` instead if your proxy emits RFC `Forwarded`. Do not combine the families for one request. Exact IPv4/IPv6 addresses and IPv4/IPv6 CIDRs are supported. A multi-hop deployment may list each known proxy, for example `['192.0.2.10', '198.51.100.20', '2001:db8:1234::/48']`. SqueHub walks the client-address chain from the nearest trusted proxy outward and stops at the first untrusted hop; it never treats a claimed leftmost address as proof of origin. A missing or malformed selected profile falls back to direct metadata. A proxy address from another Application does not become trusted here.

Configure the fronting proxy to **replace or sanitize inbound client-supplied forwarding headers** before adding its own values, and prevent direct clients from bypassing that proxy if your deployment depends on its policy. Otherwise a trusted proxy could pass on spoofed information. Restrict trust to the smallest known ranges. SqueHub rejects universal `0.0.0.0/0` and `::/0` ranges; do not attempt to trust everybody through equivalent broad rules. Do not assume Cloudflare, AWS, or any other provider's network ranges are built into core, or use request-time DNS names as proxy trust entries.

`allowed_hosts` is a separate inbound host allowlist. Its empty default adds no restriction, preserving localhost and ordinary shared-host development. Configure exact names such as `app.example.com`, or a deliberate subdomain pattern such as `*.apps.example.com`, for public deployments. The wildcard does not match the apex `apps.example.com` or `evil-apps.example.com`; list the apex separately if needed. A disallowed effective Host receives a generic 400 response. The allowlist applies to direct Host and valid trusted forwarded Host alike. The routing layer still ignores the port when checking a route's `host()` condition. `Request::port()` uses an explicit effective Host port, a direct captured `SERVER_PORT`, or a scheme default for a proxied request without a public port. No `X-Forwarded-Port` behavior is enabled.

Use HTTPS on the public edge and ensure the selected forwarding profile carries the original `http` or `https` scheme only when the proxy is trusted. A direct `X-Forwarded-Proto: https` cannot change a direct HTTP request's effective scheme. Proxy configuration does not install automatic HTTPS redirects, HSTS, or a URL base path; set `APP_BASE_PATH` separately for a mount. An application may enable HSTS and other validated response headers through the separate, opt-in [browser security policy](BrowserSecurityPolicy.md). HSTS can affect the entire public host and must be reviewed before enabling it. See [HTTP request metadata](Http.md#trusted-proxies-and-request-metadata), [host routes](Routing.md#host-conditions), and [Security](Security.md).

Named routes produce public paths, including an explicitly configured `/app` mount, without producing an absolute origin. For security-sensitive absolute links, combine that generated path with a canonical public origin configured by the operator. The v1 `url()` and `App\Core\Verification::tokenUrl()` compatibility helpers read raw server variables and do not reflect trusted proxy metadata or the URL mount; do not use them to construct reset or verification links behind a proxy or under a subdirectory.

## Database and services

Choose a reachable configured PDO database and install only the migrations required by the features you enable. SQLite and MySQL drivers exist; verify the chosen server/version with the application's own integration tests. Validation's `unique` checks do not replace database unique indexes. Soft deletes do not remove uniqueness constraints or trigger foreign-key `ON DELETE` actions.

File Cache and file Rate Limit stores are local-host choices. Native sessions require a shared session strategy when traffic spans hosts; Redis-backed services need a protected reachable Redis server and a supported client. Do not treat network filesystem locks as a proven distributed rate-limit or Queue guarantee. Bounded live PhpRedis service and Queue tests passed in an earlier infrastructure qualification; Predis live execution and Redis authentication/TLS remain unverified. See [Release status](Status.md).

### Reliability guarantees and limits

[Locks](Locks.md) default to one-server file leases under `Storage/Locks`. For coordination across servers, explicitly select Redis or MySQL, set one stable `LOCK_PREFIX`, and require a distributed backend; the database driver needs the `reliability_locks` migration. Lock leases expire and do not fence work outside the backend. [HTTP idempotency](Idempotency.md) is opt-in per authenticated mutation route and defaults to one-server file state under `Storage/Idempotency`. Multi-server use requires a shared Redis or MySQL selection and a stable `IDEMPOTENCY_NAMESPACE`; the database driver needs the `idempotency_records` migration. Schedule bounded pruning of expired file/database records and protect both state roots. Neither feature makes an external payment or Queue side effect exactly once.

The [Retry Policy](Retries.md) is chosen explicitly for supported outbound calls; unsafe HTTP mutations require deliberate replay authorization. Queue retains its persisted attempt and worker backoff rules. The opt-in [Circuit Breaker](CircuitBreaker.md) stores named dependency state under `Storage/Circuits` and coordinates only cooperating processes on one local server. Keep its dependency names finite and protect that directory; it does not supply distributed admission or provider-side idempotency. User-run native Linux reliability checks for file/SQLite races and guarded live Redis 8.0.5/MySQL 8.4.11 tests passed in disposable environments. These results qualify those tested backends, not every production network, filesystem, or operational topology. A selected driver or a passing Doctor probe alone does not prove this deployment's coordination behavior; see [release status](Status.md).

### Other selected services

Optional infrastructure adapters are selected independently. An S3-compatible Storage drive requires `aws/aws-sdk-php`, a bucket limited to the intended application prefix, and separately protected credentials; object moves are copy then delete. A user-run disposable Linux test passed against live MinIO, but AWS S3 itself was not tested. Resend and Postmark adapters passed guarded live provider API tests; Postmark's successful send used a same-domain recipient while that account awaited cross-domain approval. Memcached Cache passed a guarded live Memcached 1.6.40 test with PHP ext-memcached 3.4.0. A deployment still needs its own credentials, approved sender, reachable server, and bounded HTTPS where applicable. `cache:clear` advances only the application namespace and is not a server flush. Unselected adapters should not affect boot. Doctor and Studio inspect configuration without repeating these live tests, so configured status is not current deployment proof. See [optional adapters](InfrastructureAdapters.md) and their focused provider guides.

If selecting Database Queue, install its Queue migrations and run `php squehub queue:work` under an external process supervisor. The additive Queue composition migration is required only if that connection uses chains or batches; it does not run at boot. If selecting Redis Queue, configure Redis and still supervise workers. Use `php squehub queue:status --connection=database --queue=default` (or `redis`) for payload-free ready, delayed, reserved-lease, and connection-wide failed counts. These counts are a snapshot, not proof that any worker is alive. `queue:work --stop-when-empty` drains currently eligible work and exits without waiting for delayed jobs; `--max-jobs`, `--max-time`, `--memory`, and `--timeout` bound a worker's life or individual attempt. A supervisor should restart workers after deliberate exits and deployments. `queue:restart` asks existing workers to exit between jobs; it does not launch replacements or kill processes. Windows lacks the Unix signal path and uses a soft job timeout checked after the handler returns. Queue is at least once: jobs, queued Mail, Notifications, composed work, queued Event listeners, and queued broadcasts may execute more than once, including around crashes before acknowledgement. Make externally visible side effects idempotent where appropriate. `sync` Queue runs jobs immediately in the requesting process. See [Queue](Queue.md), [Queue composition](QueueComposition.md), and [Events](Events.md#queued-class-listeners).

[Broadcasting](Broadcasting.md) is disabled by default and adds no server or network dependency to a normal deployment. An application-selected provider adapter must enforce the server-side private-channel authorization decision during its own subscription handshake. Configure its credentials outside Queue payloads and permit only the intended provider in browser CSP `connect-src`. SqueHub core supplies no WebSocket server or provider compatibility guarantee.

For Scheduler, install its database tables when using the shipped persistent store and invoke `php squehub schedule:run` once per minute through host cron or a task scheduler. SqueHub does not install cron or run a scheduler daemon. Queue workers and Scheduler are independent.

If enabling [Webhooks](Webhooks.md), configure explicit named peers and high-entropy per-peer secrets. The default database receipt and delivery metadata stores require their migrations before use. A receiving POST path needs an exact CSRF exclusion plus signature verification in its handler; an exclusion by itself is not authentication. Outgoing Queue delivery uses the normal worker and at-least-once semantics. Restrict HTTP egress, protect Queue payloads and webhook metadata, synchronize clocks for timestamp checks, and arrange explicit terminal-record pruning according to retention policy.

## Backup and recovery

Keep application source and live state in the recovery plan. `Project/` contains most application PHP, while needed `Assets/`, `Config/`, project Migrations and Seeders, and Composer dependency metadata are also relevant to rebuilding the application. Recovering data additionally requires a consistent database snapshot and persistent uploads or other selected files. Protect the historical `APP_KEY` needed to decrypt existing data, separately from an ordinary source archive.

The current `php squehub backup:dev` ZIP omits `Assets/` and live database records; it may include `.env`. Do not use it as the sole production recovery mechanism. SqueHub also provides a separate [portable source bundle](ProjectBundles.md), static [upgrade preflight](UpgradePreflight.md), and read-only [recovery plan](Recovery.md). A bundle does not carry database records, uploads, or deployment secrets. Importing source and restoring data are separate decisions; no command silently executes migrations or a database restore. See [Backup and portability](BackupAndPortability.md).

## Release checks

For a deployment-specific evidence snapshot, use `php squehub doctor --profile=shared-hosting`, `single-server`, `worker`, or `multi-server`. The optional `--probe` checks selected local backends; an explicit `--verify-url` permits a bounded public HTTP smoke for a web profile. Results distinguish configured, reachable, and end-to-end verified state; a selected driver is not proof of live work. No profile deploys code or starts workers. See [Deployment proof profiles](DeploymentProof.md) and [Health](Health.md).

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
composer test
php squehub doctor
php squehub infrastructure
php squehub route:list
```

For View-heavy applications, `php squehub view:cache` may run after source and Composer dependencies are in place. It precompiles current `.squehub.php` sources without executing their render code and fails if a source cannot compile. The command is optional: a web request safely compiles on demand if no artifact exists. Source changes select new artifacts without `view:clear` or a global OPcache reset. Use `php squehub view:clear` deliberately to remove old owned compiled artifacts when maintaining runtime storage. See [Compiled Views and Production Lifecycle](CompiledViews.md).

`php squehub config:cache` and `php squehub route:cache` are separate optional private framework-cache builds. Configuration may contain resolved secrets, so keep `Storage/Cache/Framework/` outside the public document root and source archive. Route caching rejects unsupported dynamic/Closure route actions rather than dropping them; the supplied welcome route currently uses one, so that application continues through ordinary uncached loading. A valid cache is checked against source, environment, and activation identity; source edits and deployment rollback make stale artifacts ineligible. A corrupt active artifact fails visibly until `config:clear` or `route:clear` removes the relevant file. Build and verify each cache explicitly; there is no aggregate `optimize` command. See [Framework performance caches](PerformanceCaching.md).

Run database-changing commands only against a confirmed intended target. Doctor's optional warnings should be assessed according to selected services; a required dependency failure must be resolved. Health endpoints are disabled by default and should be enabled only with a deliberate access policy. Logs, Cache, compiled Views under `Storage/Views`, Queue markers, and Storage files need ownership and retention policies. The first file logger does not rotate its own logs.

Before production cutover, install dependencies from the exact selected commit, run the full test and deployment checks for each target platform, verify public assets and documentation against that commit, and record the release decision. A successful local smoke or documentation deployment alone does not qualify an untested production layout. See [release status](Status.md) for current limits.
