# Configuration and environment

The [Application Contract](ApplicationContract.md) is registered through application code rather than an environment-driven inference scan. Its export uses declared public metadata, not `.env` values or private service configuration. Review descriptions, examples, and optional server URLs before publishing an exported artifact. `Config/Contract.php` also holds the optional [API verification](ApiVerification.md) coverage policies `verification.require_case_per_operation` and `verification.fail_on_warning`, both off by default. Verification cases themselves are registered explicitly in application code, not in configuration or `.env`.

[SDK generation](SdkGeneration.md) uses that same explicit contract. It has no credential or base-URL `.env` key: the consumer supplies its base URL and token/session values at runtime. Generated source and manifests must not receive application secrets.

SqueHub v2 reads `.env` once when an Application boots, then loads top-level `Config/*.php` files in filename order. Each configuration file returns an array under the lowercased first letter of its filename: `Config/App.php` provides `app.*`. `Config/Debug.php` is an executable compatibility script and is excluded from array loading. In normal application code, consume the configuration repository or an injected service instead of reading environment values throughout controllers.

For deployment, `php squehub config:cache --preview` reviews the private cache publication plan without publishing an artifact; `php squehub config:cache` performs the explicit build. The artifact lives in `Storage/Cache/Framework/` and may contain resolved credentials; protect it like `.env`. Planning records safe source and artifact hashes, then application checks source, effective environment, and artifact state again before publication. Its source and effective environment identity invalidates the cache after Config or `.env` changes. Package provider configuration still runs on every boot. Dynamic environment reads cannot be validated for this cache and cause the explicit build to fail; normal uncached boot continues. `php squehub config:clear` removes only this artifact and can recover a corrupt active one. See [framework performance caches](PerformanceCaching.md) for status, limitations, and deployment steps.

## Environment setup

Follow [Installation](Installation.md) to copy `.example.env` and generate `APP_KEY`. The key command prints a value for you to paste; it never rewrites `.env`. Missing or unchanged parsed settings trigger the setup notice. Keep `.env` outside version control and never print credentials in debug output or logs.

The optional [SqueHub Setup](Setup.md) command inspects configuration and proposes a reviewable first-run plan. It manages selected `APP_ENV`, `APP_DEBUG` for production, `APP_KEY` when missing, `DB_CONNECTION`, and a managed SQLite path; it preserves unrelated keys. Manual `.env` setup remains a complete path. Setup does not provision external services or make a configured database reachable by itself; it invokes Doctor after applying changes.

For SQLite, a relative `DB_SQLITE_DATABASE` path such as `Storage/Database.sqlite` is resolved against the application root, not the CLI working directory. `:memory:` and absolute paths retain their distinct meanings. Relative `..` traversal is rejected. SqueHub Setup's managed path uses the project-local `Storage/` directory.

The supplied template groups settings by service. These are the current entry points; open the corresponding `Config/*.php` file and service guide for complete validation and defaults.

| Area | Main `.env` keys | Detailed guide |
| --- | --- | --- |
| Application | `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_TIMEZONE`, `APP_KEY` | [Application](Application.md) |
| Translation | `Config/Translation.php`: default, fallback, and supported locales; PHP `intl` for advanced formatting | [Internationalization](Internationalization.md) |
| HTTP URL mount | Optional `APP_BASE_PATH` through `Config/Http.php` | [Mounted HTTP paths](Http.md#url-base-path-and-mounted-requests), [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting) |
| Frontend assets and SPA | `Config/Frontend.php`; optional `SQUEHUB_FRONTEND_DEV_URL` for a selected Vite development origin | [Frontend assets](FrontendAssets.md), [profiles](FrontendProfiles.md), [SPA routing](SpaRouting.md) |
| Inbound proxy and Host trust | No automatic `.env` keys; explicit `Config/TrustedProxies.php` policy | [HTTP request metadata](Http.md#trusted-proxies-and-request-metadata), [Deployment](Deployment.md#reverse-proxies-and-allowed-hosts) |
| Database | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USER`, `DB_PASSWORD`, `DB_SQLITE_DATABASE` | [Database](Database.md) |
| Sessions | `SESSION_DRIVER`, `SESSION_NAME`, `SESSION_LIFETIME`, `SESSION_SECURE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`, `SESSION_STRICT_MODE` | [Sessions](Sessions.md) |
| Cache | `CACHE_DRIVER` and `CACHE_*` settings; optional `memcached` uses `CACHE_MEMCACHED_HOST`, `_PORT`, and `_TIMEOUT_MS` with `ext-memcached` | [Cache](Cache.md), [optional adapters](InfrastructureAdapters.md#memcached-cache) |
| Locks | `LOCK_DRIVER=file` by default; optional prefix, path, named Redis/database connection, and `LOCK_REQUIRE_DISTRIBUTED` | [Locks](Locks.md) |
| HTTP idempotency | `IDEMPOTENCY_DRIVER=file` by default; optional namespace, path, named connection, lease/retention limits, and `IDEMPOTENCY_REQUIRE_SHARED` | [Idempotency](Idempotency.md) |
| Retry policy | No global retry switch; HTTP callers opt in, Webhooks retain endpoint attempt settings, and Queue retains worker backoff | [Retries](Retries.md) |
| Circuit breaker | No global backend switch; application code selects a policy for named dependencies, with single-server file state under `Storage/Circuits` | [Circuit breaker](CircuitBreaker.md) |
| Rate limits | `RATE_LIMIT_DRIVER` and `RATE_LIMIT_*` settings | [Rate limiting](RateLimiting.md) |
| Authentication and API tokens | Named guards and token policy in `Config/Auth.php`; no raw token environment key | [Authentication](Authentication.md), [API tokens](ApiTokens.md) |
| Remembered browser login | Opt-in `remember` policy in `Config/Auth.php`; no token belongs in `.env` | [Authentication](Authentication.md#optional-remembered-browser-login) |
| Roles and permissions | Opt-in driver/table policy in `Config/Rbac.php`; assignments are database or Application state | [RBAC](RBAC.md) |
| Multi-factor authentication | Opt-in `mfa` policy in `Config/Security.php`; uses Crypt key, explicit migration, and Rate Limit | [MFA](MFA.md) |
| Browser security policy | Opt-in `browser` policy in `Config/Security.php`; review CSP, HTTPS/HSTS, and cookies for the deployment | [Browser security policy](BrowserSecurityPolicy.md) |
| OIDC login | Application-defined provider client ID and secret environment keys mapped in `Config/OAuth.php` | [OIDC login](OAuth.md) |
| Webhooks | Application-defined peer secrets mapped in `Config/Webhooks.php`; no peers or secrets are enabled by default | [Webhooks](Webhooks.md) |
| Queue | `QUEUE_CONNECTION`, `QUEUE_DATABASE_CONNECTION`, `QUEUE_REDIS_CONNECTION`, `QUEUE_REDIS_NAMESPACE` | [Queue](Queue.md) |
| Scheduler | `SCHEDULE_PREFIX`, optional Scheduler database selection | [Scheduler](Scheduler.md) |
| Redis | `REDIS_CLIENT`, `REDIS_URL` or host/port/credentials, database, TLS, prefix | [Redis](Redis.md) |
| Mail | `MAIL_TRANSPORT`, sender, SMTP host/port/encryption/credentials; optional `RESEND_API_KEY` or `POSTMARK_SERVER_TOKEN` for the selected HTTP transport | [Mail](Mail.md), [HTTP providers](ProviderMail.md) |
| Crypt | `CRYPT_DRIVER`, `CRYPT_CURRENT_KEY_ID`, `APP_KEY`, optional previous key | [Cryptography](Cryptography.md) |
| Logging | `LOG_DRIVER`, `LOG_LEVEL` | [Logging](Logging.md) |
| Storage | `STORAGE_DRIVE` and configured local root; optional S3 uses `STORAGE_S3_BUCKET`, `_REGION`, `_ENDPOINT`, `_PREFIX`, credentials, and `aws/aws-sdk-php` | [Storage](Storage.md), [S3-compatible Storage](ProviderStorage.md) |
| Health | `HEALTH_ENDPOINTS_ENABLED` and explicit readiness policy | [Health](Health.md) |
| Development inspection | `PROFILER_ENABLED`, `STUDIO_ENABLED` (both false by default) | [Profiler](Profiler.md), [SqueHub Studio](Studio.md) |

`Config/` files may use the supplied `$environment` object to read environment-backed values. For example:

```php
<?php

return [
    'name' => $environment->get('APP_NAME', 'SqueHub'),
];
```

Framework/application setup can read `$app->config()->get('app.name')`. The repository supports `get()`, `set()`, `has()`, and `all()` with dot notation. In normal Application code, `config('app.name')` reads the current Application's selected configuration; see [Helpers](Helpers.md). A service provider can request needed services through constructor or container resolution.

## Reliability guarantees and limits

The Phase 24 settings choose separate coordination boundaries. Default file Locks and HTTP idempotency coordinate one local server; a shared Redis or MySQL selection needs a stable namespace and deployment-specific live qualification. The Circuit Breaker is opt-in and file-only on one server. Retry settings do not make an unsafe external mutation safe to repeat, and no configuration turns a lease or idempotency record into provider-side exactly-once execution. See [Locks](Locks.md), [Idempotency](Idempotency.md), [Retries](Retries.md), and [Circuit breaker](CircuitBreaker.md) for their exact contracts.

## Frontend selection

`Config/Frontend.php` defaults to `adapter => 'none'`, empty `entries`, and disabled development and SPA policies. This default supports PHP Views, `asset()`, CSS, and native browser modules without installing Node.js. An application can explicitly map a native entry or select Vite through a reviewed [frontend profile](FrontendProfiles.md). The production `build.directory` and `build.manifest` identify public output under `public/assets`; a selected Vite entry needs a valid build manifest outside development. `development.url` is an explicitly configured loopback origin, not a value inferred from an incoming request. `SQUEHUB_FRONTEND_DEV_URL` only provides its default setting and does not launch Node or enable the adapter.

The `spa` map starts with `enabled => false`, `prefix => '/'`, `view => null`, and `except => []`. Enabling it requires a valid logical backend View. Prefixes are literal application-relative paths and cannot contain a URL mount such as `APP_BASE_PATH`; `/app` in this map matches public `/site/app/...` when the deployment mount is `/site`. Normal routes and explicit route fallbacks remain authoritative. See [SPA routing](SpaRouting.md) and [Frontend Session Auth](FrontendAuth.md) for the browser, API, asset, and CSRF boundaries.

## Debug and runtime behavior

Debug output is off unless the effective `APP_DEBUG` setting is true. Development error details and the debug bar must not be enabled on a production deployment. During `php squehub start`, edits to `.env`, including `APP_DEBUG`, are loaded on the next browser request. A real shell environment variable overrides the file and remains fixed in that running server process. The CLI boots a fresh Application per command.

Some services choose a backend lazily. Explicit `redis` configuration fails when Redis is unavailable; `auto` behavior differs by subsystem and is documented in its guide. Do not assume `auto` means silent fallback to an in-memory or synchronous backend. `php squehub doctor` and `php squehub infrastructure` report safe configuration and selection summaries without printing credentials.

Personal access token policy belongs in `Config/Auth.php` under `tokens`; each token guard names an existing identity provider. The database driver uses an explicit `api_tokens` migration and the normal selected database connection. Never put a generated raw token into `.env` as a framework setting; issue it for the intended client and keep it out of logs and configuration dumps.

Phase 17 security features are separately opt-in. Remember-me reads `Config/Auth.php` and stores only a validator digest; its cookie carries the raw browser credential. RBAC uses `Config/Rbac.php`. TOTP MFA and browser response headers share `Config/Security.php` but have independent `enabled` switches. MFA enrollment secrets are encrypted with the Application's Crypt key, and recovery proofs are stored as one-way hashes. Their database migrations are explicit. The browser policy defaults off so existing inline Views remain compatible; a selected CSP or host-wide HSTS setting needs deployment review. See [Authentication](Authentication.md), [RBAC](RBAC.md), [MFA](MFA.md), and [Browser security policy](BrowserSecurityPolicy.md).

`Config/OAuth.php` starts with no providers. An application using external OIDC sign-in defines named providers, exact redirect URIs, trusted endpoint hosts, and its own environment-backed client credentials there. The sample keys in [OIDC login](OAuth.md) are illustrative; no provider credentials or endpoints ship with SqueHub.

`Config/TrustedProxies.php` is an independent inbound request policy. Its default is `['proxies' => [], 'profile' => 'none', 'allowed_hosts' => []]`: no proxy trust, no forwarding-header trust, and no added Host allowlist. `proxies` contains exact IPv4/IPv6 addresses or CIDRs; `profile` is exactly `none`, `forwarded`, or `x-forwarded`; `allowed_hosts` contains exact hosts or deliberate `*.example.com` subdomain patterns. Invalid proxy entries, universal trust ranges, unknown profiles, and unsafe host patterns fail configuration rather than silently lowering the trust boundary. These values belong to the selected Application, not to process-global Request state. The `trusted_hosts` key in `Config/OAuth.php` has a different purpose: it constrains outbound OIDC discovery and token endpoint hosts, not inbound request Hosts or proxies.

`Config/Http.php` owns the optional public URL mount: `['base_path' => $environment->get('APP_BASE_PATH', '')]`. The empty string is the canonical root deployment; `/app` and `/clients/acme` are examples of subdirectory mounts. Set `APP_BASE_PATH=/app` in the deployment's `.env` or edit the configuration file for that Application. The value is a URL **path prefix**, not a filesystem path, hostname, scheme, or route group. It is explicit: SqueHub does not infer it from `SCRIPT_NAME`, `PHP_SELF`, `DOCUMENT_ROOT`, proxy headers, or the CLI working directory. Root deployments need no setting. Invalid, ambiguous, encoded, or traversal-shaped prefixes fail configuration instead of becoming redirect or path-matching input. See [mounted requests](Http.md#url-base-path-and-mounted-requests) and [Apache/shared-host setup](Deployment.md#subdirectory-mounts-and-shared-hosting).

Optional local [Agent and AI integration](AgentAndAI.md) reads `Config/Agent.php`. Its shipped `['grants' => []]` keeps the MCP process read-only with six bounded metadata capabilities. A sensitive supported operation needs an exact local grant, such as `['read_schema' => ['connections' => ['reporting']]]` or `['create_plan' => ['operations' => ['migration_source']]]`. Unknown, unsupported, unscoped, and write-capability grants fail validation. No LLM API key or remote HTTP endpoint is configured by this feature. `agent:status --json` reports capability state without credential values; `agent:mcp` is an explicit local STDIO command and needs the optional `mcp/sdk` package. Ordinary application boot does not start the Agent process.

## Production checklist

1. Use a private, distinct `APP_KEY`, set `APP_ENV` and `APP_DEBUG` deliberately, and provide a writable protected session path.
2. Configure a reachable database and install only the migrations required by selected features.
3. Select and verify Cache, Session, Rate Limit, Queue, Storage, Mail, Redis, Lock, and idempotency drivers you actually use. The Circuit Breaker is file-only and single-server.
4. Point the web document root to `public/`, use HTTPS, and set secure session cookie options.
5. If mounting below a URL prefix, set `APP_BASE_PATH` to that path and expose only the application's `public/` directory. Keep route declarations application-relative.
6. If a known reverse proxy fronts the application, configure only its address/CIDR and one matching forwarding-header profile. Consider an explicit inbound `allowed_hosts` list for public hosts; direct development needs no proxy configuration.
7. Run [Doctor](Health.md) and the application test suite in the intended environment. See [Deployment](Deployment.md).
