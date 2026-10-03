# SqueHub v2.0.0 master development roadmap

**Status: development plan, updated 2026-10-03.** Every phase below, completed or planned, belongs to **SqueHub v2.0.0**. Phase numbers identify work packages, not product versions. No v2 stable release has been tagged or published. An API present in the working tree is not thereby verified on every backend or available through the published Composer package. See [Feature status](FeatureStatus.md) for current APIs and [Release readiness](ReleaseReadiness.md) for the dated verification record.

## Design and status rules

**Simple to use. Powerful underneath.** Keep MVC, concise routes and controllers, packages, `php squehub`, and `.squehub.php` templates familiar. Most application PHP belongs in `Project/`; framework internals belong in `App/`. Application assets, configuration, migrations, Seeders, dependencies, live data, and uploads need separate consideration during upgrades and recovery. See [Directory structure](DirectoryStructure.md) and [Backup and portability](BackupAndPortability.md).

`App\Plugins` is the stable developer-facing gateway for supported framework APIs; internals use canonical subsystem namespaces, while project classes remain `Project\...`. For example, application code can import `App\Plugins\Route`, `View`, `DB`, `Queue`, and `Redis`. Future public features should consider a Plugins entry from the start, without adding aliases for internal-only classes. Preserve documented legacy compatibility where it does not compromise v2's architecture.

A small application must remain viable with PHP 8.2+, SQLite or MySQL, Apache or the PHP development server, native Session, file Cache, local Storage, sync Queue, and optional host cron. Redis, external workers, Node.js, Docker, WebSockets, cloud services, and telemetry collectors must not become prerequisites for basic web requests. Advanced capabilities should compose with the existing Application rather than create parallel runtimes.

**SqueHub is PHP-first, not PHP-only.** An application may use MVC templates, vanilla JavaScript, native ES modules, a React or Vue frontend, a separate web client, or a mobile client against the same backend architecture. React, Vue, Vite, Node.js, and npm belong to optional selected tooling, never to core boot. Ordinary shared/cPanel hosting remains a first-class target. Phase 22 qualified the zero-build path and selected frontend paths on Windows and native Linux; provider-specific deployment remains a separate release gate.

| Label | Meaning |
| --- | --- |
| **Implemented in working tree** | Source and tests exist locally; this does not imply publication or complete platform qualification. |
| **Verified** | A specified test or deployment profile passed; the environment and limits must be named. |
| **Release gate open** | Required acceptance or distribution evidence is still missing. |
| **Planned** | Intended design work, not an available public API or promised delivery date. |
| **Research / evaluate** | A decision is pending; the idea may be narrowed or dropped. |
| **Optional adapter/package** | Enhances selected deployments without burdening basic applications. |

Priorities describe work inside **v2.0.0**: **Required** is a planned release obligation; **High** is important but may need an explicit scope decision; **Advanced** is for applications that opt in; **Optional adapter** requires real backend proof before a support claim; **Research** is not a hidden release gate. A future API name in this document is conceptual until its design and tests establish a contract.

## Implemented foundation: Phases 1–11F

These milestones describe the current development tree, not a published framework. The linked guides give exact current behavior.

| Work packages | Implemented foundation | Current boundary |
| --- | --- | --- |
| 1–5 — Audit, Application, HTTP, routing, data | v1 audit and regression baseline; Application, Container, Providers, Request/Response, Kernel, errors, path-first Router, middleware, controller resolution, and bound QueryBuilder. | Legacy bridges coexist with modern APIs; raw table queries stay separate from Models. [Application](Application.md), [Routing](Routing.md), [Database](Database.md) |
| 6A–6G — Schema and ORM | Migrations, Model state/casts/timestamps, relations/pivots, collections, pagination, explicit scopes, soft deletes, Seeders, and Factories. | This foundation was extended by the early Phase 16 implementation below. [Models](Models.md), [Relationships](Relationships.md), [Seeders](Seeders.md) |
| 7A–7D — Browser foundation | Validation, Session/flash/old input, CSRF, escaped views, layouts, sections, includes, and browser forms. | No general components or view-cache CLI. [Views](Views.md), [Forms](Forms.md), [CSRF](Csrf.md) |
| 8A–8E — Core services | Logging, Diagnostics, Cache, Events, local Storage, and source-snapshot checks. | A copied snapshot is not a reproducible Git checkout. [Diagnostics](Diagnostics.md), [Stabilization](Stabilization.md) |
| 9A–9D — Identity and security | Session/model Auth, gates/policies, account credential and reset/verification tokens, and application-neutral Rate Limiting. | Phase 12D adds the separate API token guard. Phase 17 implements opt-in remember-me, RBAC, and MFA without an automatic login throttle. [Authentication](Authentication.md), [Account security](AccountSecurity.md), [Rate limiting](RateLimiting.md) |
| 10A–10F — Delivery and background work | Mail, Notifications, sync/database Queue, workers/retries, Scheduler, queued delivery, failed-job operations, and after-commit dispatch. | At-least-once delivery; host supervision/cron remain operational responsibilities. [Mail](Mail.md), [Notifications](Notifications.md), [Queue](Queue.md), [Scheduler](Scheduler.md) |
| 10B.5 — Public namespace | `App\Plugins` gateway for supported application-facing framework symbols. | Project-owned classes stay in `Project\...`. [Plugins](Plugins.md) |
| 11A–11C — Redis | Application Redis gateway; Redis Cache, Session, Rate Limit, and Queue drivers. | Live PhpRedis paths have now passed bounded tests; Predis live execution, authentication, TLS, and all production topologies are not thereby proved. [Redis](Redis.md), [Redis Queue](RedisQueue.md) |
| 11D–11F — HTTP, Crypt, operations | Outgoing HTTP Client, sodium/OpenSSL Crypt, Doctor, Health, and safe operational diagnostics. | External HTTPS and SMTP TLS success need their own qualification. [Outgoing HTTP](HttpClient.md), [Cryptography](Cryptography.md), [Health](Health.md) |

Phase 13A provides five working generators: `make:controller`, `make:middleware`, `make:migration`, `make:model`, and `make:seeder`. New data setup and explicit reversal use Seeders; the v1 Dumper API and commands are retired in v2. Phase 13B adds Package-only discovery, explicit activation, dependencies, and guarded lifecycle operations. Phase 13C records observed contributions with Package ownership and a verified, statically inspectable snapshot. Phase 13D gives generators and Package lifecycle one shared reviewable change plan with no-application-write preview, stale-state checks, and deliberate apply. Phase 13K extends that model to Kits with local definitions, reviewed file publication, and explicit activation. Phase 13L unifies Package and Kit state behind the [SqueHub Activation Registry](ActivationRegistry.md). Phase 21E extends the shared plan with bounded review provenance and verification expectations while execution remains subsystem-owned. See [Code generators](Generators.md), [Seeders](Seeders.md), [Packages](Packages.md), [Kits](Kits.md), [Contributions](Contributions.md), and [Reviewable changes](ReviewableChanges.md).

## Phase 11G — Production portability and release assessment

The following is the **reported Phase 11G verification record**, reconciled with the current source and guarded test files. It describes bounded runs, not a new run performed while editing this roadmap. [Release readiness](ReleaseReadiness.md) retains the detailed assessment and outstanding gates.

| Work package | Status and evidence |
| --- | --- |
| **11G.1 Distribution integrity / source reproducibility** | **Release gate open.** A copied source snapshot installed and tested, but today's `HEAD` has only 80 tracked files; required `App/`, `Bootstrap/`, `Config/`, `Project/`, and `composer.lock` content remains untracked. `Docs/` and `Tests/` are ignored. A clean committed checkout is not yet reproducible. |
| **11G.2 Windows + Linux portability** | **Verified on specified hosts.** Windows PHP 8.2.12/SQLite 3.39.2: **725 tests, 4,879 assertions, 9 skips, 0 failures/errors/risky**. Native case-sensitive WSL 2 Ubuntu 26.04.1 LTS filesystem under `/home/val/projects/squehub-v2-releasegate`, kernel 6.18.33.2-microsoft-standard-WSL2, PHP 8.5.4, Composer 2.9.5, SQLite 3.46.1, cURL 8.18.0, OpenSSL 3.5.5, sodium enabled: **725 tests, 4,886 assertions, 7 skips, 0 failures/errors/risky** after portability fixes. Fresh Linux Composer installation passed. macOS is unverified. |
| **11G.3 Live MySQL qualification** | **Verified for guarded coverage.** Disposable MySQL 8.4.11/InnoDB on WSL with PDO MySQL, a restricted test user, and `squehub_test_11g` (removed afterward): `MySqlSchemaOptInTest` and the MySQL group passed **1 test, 52 assertions**. The full Linux suite with MySQL enabled passed **725 tests, 4,938 assertions, 6 skips, 0 failures/errors/risky**. The guarded test covers detection, Model and QueryBuilder paths it exercises, Schema/FKs, FK rejection and actions, transactions, migrations, failure handling, and cleanup. This does not prove every MySQL deployment scenario or MariaDB compatibility. |
| **11G.4 Live Redis qualification** | **PhpRedis live verified for the existing test contracts.** Redis Server 8.0.5, PhpRedis 6.2.0, PHP 8.5.4, isolated DB 15: `RedisLiveTest` passed **1 test/8 assertions**; `RedisSubsystemLiveTest` PhpRedis data set passed **7 assertions** for Cache, Session, Rate Limit, and its cross-process path; `RedisQueueLiveTest` PhpRedis data set passed **9 assertions** for atomic claim, stale recovery, and process boundary. Combined Linux + MySQL + Redis suite: **725 tests, 4,962 assertions, 3 skips, 0 failures/errors/risky**. After cleanup and removing test environment variables, the normal Linux suite returned to **725/4,886/7 skips**. **Predis was not live verified** because `predis/predis` was absent; deterministic coverage is not a live Predis run. Redis auth/TLS and multi-region behavior remain unproved. |
| **11G.5 Apache/XAMPP deployment** | **Verified for a Windows document-root smoke.** Fresh Composer install, strict validation, optimized autoload of about 5,607 classes, and copied-tree suite **725 tests, 4,881 assertions, 9 skips, 0 failures/errors/risky**. Apache 2.4.58/PHP 8.2.12/OpenSSL 3.1.3 showed the setup-required 503 for incomplete `.env`; after proper root placement/configuration, the application loaded at `http://localhost/`. This does **not** establish arbitrary URL-subdirectory hosting; Phase 15E addresses that separately. |
| **11G.6 Final release-gate reconciliation** | **Open.** Backend and platform checks above passed within their stated limits, but complete Git/source reproducibility, remaining required phases, a real v2 release artifact, public docs, and final approval are outstanding. |

The live MySQL and PhpRedis tests use opt-in guarded paths. Their results qualify those paths only; they are not proof of external SMTP/provider delivery, successful STARTTLS/SMTPS, Redis authentication/TLS, every worker topology, macOS, or a published `composer create-project` installation. Predis is an **optional remaining qualification item**, not a Redis failure. The source-reproducibility blocker is independent of the passing working-tree suites.

## v2.0.0 workstreams

All rows target **v2.0.0**. Priorities do not imply a later version.

| Workstream | Phase | Target | Focus |
| --- | --- | --- | --- |
| Foundation and release integrity | 1–11G, 28 | v2.0.0 | Preserve implemented core; close reproducibility and release gates. |
| API platform | 12A–12I | v2.0.0 | Safe resources, responses, versioning, tokens, webhooks, contracts, SDK generation, verification. |
| Developer experience and packages | 13A–13L | v2.0.0 | Generators, Package and Kit lifecycle, inspection, reviewable changes, testing DX, guided setup, feature blueprints, and optional dev orchestration. |
| Views and server-rendered UI | 14A–14P | v2.0.0 | Shared context, parser, components, package views, returnable responses, compilation. |
| HTTP and routing | 15A–15E | v2.0.0 | Binding, route depth, response types, trusted proxies, base-path hosting. |
| Database and ORM | 16A–16E plus final relation pass | v2.0.0 | Eager loading, relation existence/counts, through and polymorphic relations, cursor pages, lifecycle, concurrency. |
| Identity and security | 17A–17E | v2.0.0 | Persistent login, RBAC, MFA, browser policy, signed URLs. |
| Background work and realtime | 18A–18D | v2.0.0 | Queue operations/composition, queued listeners, optional broadcasting. |
| Observability, performance, and Studio | 19A–19F | v2.0.0 | Tracing, correlation, profiler, route/config caching, measured optimization, and an optional development dashboard. |
| Internationalization and providers | 20A–20D | v2.0.0 | Translation plus optional Storage, Mail, and infrastructure adapters. |
| Portability, change planning, and deployment | 21A–21E | v2.0.0 | Project bundles, upgrade preflight, recovery boundaries, deployment proof, and a common change-plan engine. |
| Frontend platform and application profiles | 22A–22L | v2.0.0 | Node-free assets and ES modules plus optional first-class Vite, React, Vue, SPA, and starter profiles. |
| Typed application data | 23A–23E | v2.0.0 | Optional data objects, validated mapping, form/API integration, safe payload contracts. |
| Reliability and idempotency | 24A–24E | v2.0.0 | Shared lock/retry contracts, HTTP idempotency, optional circuit breaker, race verification. |
| Agent and AI integration | 25A–25F | v2.0.0 | Framework-aware MCP context, read-only defaults, capabilities, reviewed AI plans. |
| Stabilization | 26A–26E | v2.0.0 | Public API, security, compatibility, performance, and dependency audits. |
| Documentation and ecosystem | 27A–27D | v2.0.0 | Versioned public docs, verified examples, v1 upgrade guidance. |
| Final release candidate | 28A–28D | v2.0.0 | Reproducible artifact, installation proof, public docs, explicit approval. |

## Phase 12 — API platform (12A–12I implemented; 12I Linux/TypeScript checks reported passed, wider release gates open)

Existing Request, Response, JsonResponse, Validation, Auth, Authorization, Rate Limiting, Queue, Crypt, and HTTP Client support this layer. Phase 12A provides explicit [API resources](ApiResources.md), Phase 12B adds opt-in [API responses and errors](ApiResponses.md) through the existing HTTP Kernel and exception boundary, Phase 12C adds [API versioning](ApiVersioning.md) and [CORS](Cors.md) policies, Phase 12D adds named [personal access token](ApiTokens.md) guards through existing Auth, and Phase 12E adds a configured [OIDC login client](OAuth.md). Phase 12F's [SqueHub webhook profile](Webhooks.md) is present in the development working tree; its qualification remains bounded by current tests. Phase 12G adds a SqueHub-native [Application Contract](ApplicationContract.md) and OpenAPI 3.2.1 export. Phase 12H adds explicit [API verification](ApiVerification.md) through the existing Kernel. Phase 12I adds deterministic [client SDK generation](SdkGeneration.md) from the same native contract. These are working-tree implementations, not a published or fully platform-qualified v2.0.0 release. [API development](ApiDevelopment.md) describes what exists today.

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **12A API resources** | Required | **Implemented in working tree.** `App\Api\ApiResource` and its `App\Plugins\ApiResource` abstract gateway expose explicit single, collection, nested, conditional, and existing Page representations; `ResourceCollection` and `ResourceException` have exact Plugins aliases. Application resources conventionally live under `Project/Api/Resources/`. Immutable replacement metadata, strict JSON values, privacy, lazy loaded-relation handling, and existing JsonResponse integration are covered by Windows tests. Return `->response()` for HTTP. See [API resources](ApiResources.md). |
| **12B API response and error contract** | Required | **Implemented in working tree.** Explicit path scopes select safe machine-readable validation, authentication, authorization, routing, CSRF, rate-limit, and unexpected error envelopes through the existing ExceptionHandler. `App\Plugins\ApiError` declares intentional public application errors. Fresh local request IDs, no-store errors, strict bounded details, redaction, debug privacy, header preservation, and rendering fallback are covered by Windows tests. Successful Resource bodies, explicit custom responses, browser flows, and Health/setup behavior retain their contracts. See [API responses](ApiResponses.md). |
| **12C Versioning and CORS** | Required | **Implemented in working tree.** Explicit route/group version metadata uses ordinary URI prefixes or an opt-in version header, with request-scoped access and managed mismatch errors. Central `Config/Api.php` CORS is disabled by default and scopes strict origins, methods, headers, credentials, preflight, and error-response headers. No forced `/api/v1` path or universal permissive CORS. See [API versioning](ApiVersioning.md) and [CORS](Cors.md). |
| **12D API/personal access tokens** | Required | **Implemented in working tree.** Named Bearer guards reuse existing Auth/model identity resolution and request-scoped guard selection. Opaque indexed identifiers, SHA-256 of 256-bit random secrets, explicit database migration or isolated array repository, bounded abilities, expiry, revocation, atomic rotation, throttled last-used metadata, aggregate diagnostics, generic 401 challenge, token-ability middleware, and PAT-shaped log/debug redaction have Windows regression and user-reported manual WSL coverage. Phase 12D.1 adds a guarded live MySQL token test; its existence alone is not a MySQL PASS. Later user-run Phase 26 MySQL qualification included API tokens. Raw tokens are returned only at issuance; issuance endpoints and rate-limit policy remain application decisions. See [API tokens](ApiTokens.md). |
| **12E OAuth 2.1 / OIDC integration** | Advanced | **Implemented in working tree as an opt-in OIDC browser client.** Named `Config/OAuth.php` providers use authorization code, PKCE S256, session-bound one-time state and nonce, strict RFC 9207 callback `iss`, discovery and trusted endpoint hosts, and RS256 ID-token verification. `ExternalIdentity` requires explicit application mapping and `Auth::login()`; provider tokens are not persisted, and no provider endpoint is shipped. Live provider interoperability remains unqualified. OAuth 2.1 is still an Internet-Draft; see [OIDC login](OAuth.md). |
| **12F Webhooks** | High | **Implemented in working tree.** Named SqueHub-profile peers use canonical signed JSON, bounded outgoing attempts, optional Queue delivery and after-commit dispatch, active/previous key rotation, incoming timestamp checks and atomic receipt claims, safe outgoing metadata, and aggregate diagnostics. The final Windows suite passed **1,086 tests/7,398 assertions/14 skips**; the focused Webhook filter passed **45/496** with real local loopback transport. No WSL/Linux, live MySQL, or external provider qualification was run by Codex for 12F. It is not a vendor signature adapter or exactly-once transport. See [Webhooks](Webhooks.md). |
| **12G Application Contract / OpenAPI bridge** | High | **Implemented in working tree.** Explicit route operations, SqueHub-native schemas, Resource schemas, errors, pagination, auth requirements, API versions, tags, and outgoing Webhooks produce deterministic OpenAPI 3.2.1 or SqueHub JSON through `contract:export`. Export is offline and opt-in; it does not inspect arbitrary controller PHP, execute runtime behavior, expose `/openapi.json`, verify response conformance, or generate SDKs. See [Application Contracts](ApplicationContract.md). |
| **12H API verification gate** | Required | **Implemented in working tree.** `contract:verify` consumes the native 12G contract, checks route/known-security metadata, and runs deliberately registered cases through Request, Kernel, Router, middleware, and ExceptionHandler. Opis Draft 2020-12 validation checks declared JSON response shapes; reports are deterministic and omit request/response bodies and credentials. The Windows suite covers passing and drift fixtures plus API regressions. Executable cases are development/testing-only; mutating methods and marked cases require `--mutations`, and production CLI verification is static-only. This proves selected cases in the exercised environment, not every state, external provider, or deployment. See [API verification](ApiVerification.md). |
| **12I Contract-driven client/SDK generation** | High | **Implemented in working tree.** `sdk:generate` consumes the SqueHub-native Application Contract through a shared normalizer and emits standalone TypeScript, JavaScript ESM, and PHP clients with stable operation/schema mapping, version filtering, runtime credentials, JSON request/response handling, safe API errors, and ownership-aware regeneration. `--check` is write-free. Windows tests cover generated PHP over real local cURL → HTTP → Kernel and JavaScript with a Fetch/Node smoke. The user later reported successful Linux/WSL execution of generated clients and TypeScript compilation; exact later totals and tool versions were not supplied. Clean committed-checkout qualification remains open. No Dart, OpenAPI round trip, embedded credentials, or controller inference. See [client SDK generation](SdkGeneration.md). |

On 2026-09-26, Phase 12A's focused unit/integration checks passed **57 tests and 241 assertions** on Windows PHP 8.2.12 with SQLite 3.39.2. The full suite passed **783 tests, 5,132 assertions, 9 skips, and no failures, errors, or risky tests**. The skips cover two symlink cases, POSIX logging, one guarded MySQL test, and five Redis cases. No WSL/Linux, case-sensitive filesystem, live MySQL, or live Redis run was performed for this pass. Phase 12H's wider API verification gate remains open, and completion of 12A does not complete Phase 12 or establish release readiness.

The user subsequently reported a manual WSL Phase 12A run of **783 tests, 5,139 assertions, 7 skips, and no failures, errors, or risky tests**. That historical result predates Phase 12B and 12C.

On 2026-09-26, Phase 12B's Windows PHP 8.2.12/SQLite 3.39.2 full suite passed **928 tests, 6,063 assertions, 9 skips, and no failures, errors, or risky tests**. The broad focused regression run passed **407 tests, 2,603 assertions, and 2 skips**; API error unit tests passed **102 tests and 371 assertions**, integration tests passed **43 tests and 554 assertions**, and Plugins symbol checks passed **3 tests and 180 assertions**. Linux/WSL verification was not run by Codex. No live MySQL or live Redis qualification was added at that checkpoint.

The user later reported manual WSL verification after Phase 12B: focused 12B checks passed **145 tests and 925 assertions**, 12A regressions passed **57 tests and 241 assertions**, and the full Linux suite passed **928 tests, 6,070 assertions, 7 skips, 0 failures, 0 errors, and 0 risky tests**. These historical user-reported results do not cover Phase 12C.

On 2026-09-26, Phase 12C's Windows PHP 8.2.12/PDO SQLite 3.39.2 full suite passed **965 tests, 6,396 assertions, 9 skips, 0 failures, 0 errors, and 0 risky tests**. Focused version tests, including a combined version-header/CORS case, passed **19 tests and 95 assertions**; CORS unit tests passed **11 tests and 136 assertions**, and CORS integration tests passed **7 tests and 102 assertions**. A broad API/HTTP/security regression passed **452 tests, 3,027 assertions, and 2 skips**. Linux/WSL verification for 12C was **not run by Codex**; no live MySQL or live Redis qualification was added by that Windows pass.

The user subsequently reported a manual WSL/Linux full-suite pass after Phase 12C: **965 tests, 6,403 assertions, 7 skips, 0 failures, 0 errors, and 0 risky tests**. This historical result predates 12D.

On 2026-09-26, Phase 12D's Windows PHP 8.2.12/PDO SQLite 3.39.2 final full suite passed **988 tests, 6,616 assertions, 9 skips, 0 failures, 0 errors, and 0 risky tests**. The suite includes a LoggingTest regression for redacting a newly issued PAT before it appears in an HTTP request. Linux/WSL was not run by Codex for 12D. The user subsequently reported a manual WSL full-suite pass of **988 tests, 6,623 assertions, 7 skips, 0 failures, 0 errors, and 0 risky tests**, plus passing focused 12D repository, guard, and HTTP integration tests. This is historical, user-reported Linux evidence, not a live MySQL token test or a clean committed-checkout reproduction.

Phase 12D.1 supplies `Tests/Integration/MySqlApiTokenOptInTest.php` to qualify the real migration and database token repository only against an explicitly confirmed disposable `squehub_test_...` MySQL database. Its default Windows invocation skipped safely (**1 test, 0 assertions, 1 skip**); the final Windows suite passed **989 tests, 6,616 assertions, 10 skips, 0 failures, 0 errors, and 0 risky tests**. Codex did not run live MySQL. The older Phase 11G MySQL pass predates this token repository, but a later **user-run Phase 26** MySQL 8.4.11 / PDO MySQL / InnoDB qualification included API tokens among **8 representative tests and 448 assertions**, with zero failures and errors. The Linux full suite with live MySQL opt-in passed **2,955 tests, 23,572 assertions, 20 skips, zero failures, and zero errors**. Phase 12D's implementation status was unchanged at that checkpoint; Phase 12E and the 12H verification gate were then still ahead and have since been implemented. See [release readiness](ReleaseReadiness.md) for the later evidence.

On 2026-09-26, Phase 12E's Windows PHP 8.2.12/PDO SQLite 3.39.2 final `composer test` run passed **1,041 tests, 6,902 assertions, 14 skips, 0 failures, and 0 errors**. Focused OAuth flow/browser, protocol security, and configuration checks passed **16/182**, **13/66**, and **19/31** tests/assertions respectively. The four new default skips are an opt-in loopback HTTP test, separately passed **4 tests/85 assertions** against a local fake provider using real cURL. No live public or enterprise OIDC provider, WSL/Linux, or MySQL run was performed by Codex for 12E. This working-tree result does not close the wider Phase 12H gate; RFC 9207 `iss` is required before code exchange, so providers omitting it remain unsupported by the initial policy.

On 2026-09-26, Phase 12F's final Windows `composer test` run passed **1,086 tests, 7,398 assertions, 14 skips, 0 failures, 0 errors, and 0 risky tests**. The final focused Webhook filter passed **45 tests and 496 assertions with no skips**, covering the dotted-peer-name regression and real local loopback transport. A broad phase regression passed **536 tests, 3,641 assertions, and 10 skips** before the final Webhook-only adjustments; the final full suite covers those adjustments. Codex did not run Phase 12F on WSL/Linux or live MySQL and did not qualify an external webhook provider. At that checkpoint, Phase 12 remained incomplete: 12G–12I and the broader 12H verification gate were still planned.

On 2026-09-27, Phase 12G's working-tree implementation added explicit contracts and OpenAPI 3.2.1 export. Focused Windows checks cover schema declarations, route attachment and visibility, API shapes, versions, security, outgoing Webhooks, Plugins isolation, and CLI JSON output. The final Windows `composer test` suite passed **1,132 tests, 7,614 assertions, 14 skips, 0 failures, 0 errors, and 0 risky tests**. **Linux/WSL verification was NOT RUN by Codex** for this phase. Phase 12 still requires the 12H verification gate and 12I client generation work.

The user subsequently reported a manual WSL pass after Phase 12G: **1,132 tests, 7,621 assertions, 12 skips, 0 failures, 0 errors, and 0 risky tests**, and no Composer audit advisories. This predates Phase 12H.

On 2026-09-27, Phase 12H's Windows working-tree `composer test` suite passed **1,179 tests, 7,833 assertions, 14 skips, 0 failures, 0 errors, and 0 risky tests**. Explicit cases exercise selected API behavior through the real Kernel and compare responses to the native contract; static checks and private deterministic reports support a CI gate. Opis JSON Schema 2.6 validates the declared Draft 2020-12 response vocabulary with external reference resolution disabled. Mutation and production execution boundaries are documented in [API verification](ApiVerification.md). **Linux/WSL verification was NOT RUN by Codex** for Phase 12H. No live external OIDC provider, arbitrary webhook peer, live MySQL verifier, or clean committed-checkout reproduction was qualified by this Windows pass. Phase 12I client generation is still planned; Phase 12 is not complete.

On 2026-09-27, Phase 12I's Windows working-tree `composer test` suite passed **1,222 tests, 7,999 assertions, 14 skips, 0 failures, and 0 errors**; the focused SDK filter passed **43 tests and 163 assertions**. `sdk:generate` produces standalone TypeScript, ESM JavaScript, and PHP from the native contract with a deterministic manifest and write-free stale check. Generated PHP passed real local cURL → HTTP → Kernel qualification, and JavaScript passed a Node/Fetch subprocess smoke. A TypeScript compiler was unavailable locally, and **Linux/WSL verification was NOT RUN by Codex** at that checkpoint. The user later reported that generated clients passed Linux/WSL execution and generated TypeScript compiled; exact later totals and tool versions were not supplied. A clean committed-checkout build and wider deployment qualification remain open. Phases 12A–12I are implemented in this working tree, not release-qualified.

A conceptual `php squehub api:client --language=typescript` command and usage such as `api.users.get(id)` illustrate the desired developer experience; neither exists yet, and final signatures need design review. Generation must report unknown contract areas rather than infer arbitrary controller PHP. This should serve React, Vue, mobile, and external clients without tying the API Platform to one frontend.

## Phase 13 — Developer experience, Packages and Kits (13A–13F and 13H–13L implemented in working tree; 13G research complete: deferred)

Preserve and expand SqueHub's existing application Package model.

In v2:

```text
Project/Packages/<PackageName>/
```

remains the first-class location for reusable runtime capabilities.

SqueHub also formalizes Kits as a separate concept under:

```text
Project/Kits/<KitName>/
```

The distinction is deliberate:

```text
Package
→ reusable runtime capability

Kit
→ broader application composition/scaffold
```

A Package normally remains active at application runtime and may contribute routes, services, views, middleware and other framework behavior.

A Kit primarily installs, configures and composes an application. It may require multiple Packages and create normal Project files, Views, Assets and configuration, but it must not become a second Package runtime.

Do not introduce competing `Modules/`, `Domains/` or `Features/` architectures for the same purpose.

### Phase 13 work packages

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **13A Generators** | Implemented in working tree | `make:controller`, `make:middleware`, `make:migration`, `make:model`, and canonical `make:seeder`. Generate a single reviewed source file with canonical casing/PSR-4, no-write `--preview`, and no overwrite. Controller/Model/Middleware may target an existing Package; migration and Seeder targets remain root-only. V1 Dumper APIs/commands are retired; `seed:status` and explicit `ReversibleSeeder` rollback preserve the needed data workflow without pretending execution history exists. See [Code generators](Generators.md) and [Seeders](Seeders.md). |
| **13B SqueHub Package foundation and lifecycle** | Required; implemented in working tree | `Project/Packages/<PackageName>/` now has a package-named entry/public API, static validation, disabled-by-default manual import/install, explicit enable/disable, deterministic dependency order, Package-only state and ownership fingerprints, and guarded install/upgrade/remove. Only enabled Packages participate in runtime boot. Package-local Migration/Seeder runners and the shared Package/Kit registry remain later work. See [Packages](Packages.md). |
| **13C Package/Kit contributions and provenance** | Implemented in working tree | One Application-scoped registry records effective Package/application ownership for routes, middleware aliases, selected views, Package config defaults, service bindings, and Scheduler definitions. `package:inspect` reads a persisted snapshot without executing Package PHP; `package:verify` deliberately boots enabled code and refreshes one Package snapshot. Changed source is stale; disabled records are historical. Phase 13K attributes declarations in Kit-published `Project/Routes/` files to `kit:<Name>` as artifact provenance even when the Kit is disabled; those files still run as ordinary Project routes. Broad class indexing, commands, assets, Migration/Seeder contributions, and side-effect-free Explain commands remain later work. See [Contributions](Contributions.md). |
| **13D Reviewable changes** | Implemented in working tree | One internal `App\Changes` plan/action/result vocabulary and renderer cover all five generators and Package install/enable/disable/upgrade/remove. Plans list source and state actions, risk, warnings, conflicts and fingerprints; preview does not change the application project, and non-interactive apply requires `--yes`. Package staging/recovery remains Package-owned. Phase 13K reuses these plans for Kit lifecycle; Phase 21E adds bounded shared review metadata. See [Reviewable changes](ReviewableChanges.md). |
| **13E Testing developer experience** | Implemented in working tree | PHPUnit remains the runner. `App\Plugins\TestCase` owns a disposable test Application with SQLite and array state drivers, and provides real-Kernel HTTP requests, a stateful client, safe response/session assertions, explicit Auth/CSRF helpers, Migrations/Seeders, and Package route/provenance testing. Unit tests can still use PHPUnit alone. `make:test`, specialized Event/Mail/Queue fakes, specialized Kit testing helpers, and live-backend qualification remain later work. See [Testing](Testing.md). |
| **13F Large applications through Packages** | Implemented in working tree | Ordinary `Project/` code remains valid with zero Packages. Capability Packages use one application-scoped, deterministic dependency graph for shared/deep requirements, cycles, activation, and reverse-dependency protection. Optional static `extra.squehub.owner` labels identify maintainers without granting privileges. Package entry classes are deliberate public/bootstrap APIs; internals stay implementation details. Existing namespaced config, contribution provenance, reviewable lifecycle, and 13E Testing API are qualified with a multi-Package fixture. No second module system, artificial visibility flag, Kit runtime, or view namespace was added. See [Large applications](LargeApplications.md) and [Packages](Packages.md). |
| **13G Reproducible request cases** | Research complete: deferred | Matched routes expose templates and Package provenance without concrete parameter or query values, but registered route metadata still needs a strict privacy policy. Replay also needs a fail-closed profile for outbound HTTP, mail, and queue work, followed by planted-secret and isolation evidence. No Request Case capture or replay API is implemented. |
| **13H SqueHub Setup** | Implemented in working tree | Optional `php squehub setup` inspects the application, shows a secret-safe reviewed configuration plan, applies deliberately, and invokes Doctor for verification. The manual installation path remains complete. Setup does not rotate an existing key, run Migrations or Seeders, install Packages, or offer Profiles/Kits. See [SqueHub Setup](Setup.md). |
| **13I SqueHub Feature Blueprint** | Implemented in working tree | `make:feature Post` composes a Model, Migration, Controller, empty Request-validation rules class, API Resource, fixed first-Page GET route, and runnable test into one existing 13D `ChangePlan`. The GET scaffold returns at most 20 records with normal `data`/`meta`; request-selected pagination remains application work. An existing Package can be targeted explicitly; runnable Migrations and tests use the current root discovery locations. Review and apply are separate; Migrations and Seeders never run automatically. Phase 21E preserves this plan contract and adds optional shared metadata for new consumers. See [SqueHub Feature Blueprint](FeatureBlueprints.md). |
| **13J SqueHub Dev** | Implemented in working tree; Phase 22 frontend extension implemented | `php squehub dev` coordinates the existing PHP development server with structured Doctor preflight. `--queue` explicitly starts the ordinary worker only when the selected default Queue connection is persistent. Phase 22 adds explicit `dev --frontend` supervision for a selected Vite profile; ordinary `dev` and `start` remain Node-free. Package activation follows normal Application boot, and Dev is a foreground development tool rather than a production process manager. Scheduler orchestration remains deferred because `schedule:run` is one-shot. See [SqueHub Dev](Dev.md) and [frontend profiles](FrontendProfiles.md). |
| **13K SqueHub Kit foundation and lifecycle** | Implemented in working tree | `Project/Kits/<KitName>/` contains a strict `kit.json` manifest and a lifecycle-only Kit entry. Local-directory install leaves a Kit disabled; explicit enable/disable/upgrade/remove, static list/inspect, reviewed file publication to canonical Project, Config, Migration, Seeder, Test and public Kit-asset locations, required Package activation through PackageManager, file ownership, bounded hooks, and aggregate Doctor status are implemented. No remote Git source, automatic Migration/Seeder execution, or Kit per-request boot was added. Phase 13L subsequently unified Kit and Package activation state. See [Kits](Kits.md). |
| **13L SqueHub Activation Registry** | Implemented in working tree; user-reported Linux/WSL verification passed | One Application-scoped [registry](ActivationRegistry.md) combines Package and Kit state in `Project/Activation.json`, imports legacy state without boot writes, derives status and typed dependencies, and supplies enabled Package boot order. Package and Kit lifecycle plans use a shared registry fingerprint and lock; Kit activation commits reviewed Package and Kit state in one registry write. Doctor consumes structured status. Exact later Linux/WSL totals and versions were not supplied. Kits still never boot per request. No database, Redis, Node, Studio, MCP, or new `activation:*` CLI is required. |

---

## Package foundation

The established Package model remains:

```text
Project/
└── Packages/
    └── Weather/
        ├── Weather.php
        ├── Controllers/
        ├── Models/
        ├── Middleware/
        ├── Utils/
        ├── Views/
        ├── Routes/
        ├── Config/
        └── composer.json
```

`Weather.php` remains the Package's deliberate entry/bootstrap and public API boundary.

Additional v2 folders may include:

```text
Api/
Services/
Jobs/
Events/
Listeners/
Commands/
Database/
    ├── Migrations/
    └── Seeders/
Tests/
Assets/
```

only where the relevant SqueHub subsystem has an established integration contract.

A Package does not need every directory.

For example:

```text
Project/
└── Packages/
    └── Currency/
        ├── Currency.php
        ├── Utils/
        └── composer.json
```

is valid.

---

## Package runtime activation

Installation and activation are separate concepts.

A Package may be:

```text
not installed
installed + disabled
installed + enabled
invalid / broken
```

The 13B runtime flow is:

```text
Package exists
    ↓
discover
    ↓
validate
    ↓
check activation registry
    ↓
enabled?
    ├── no  → do not boot
    └── yes
          ↓
      resolve dependencies
          ↓
      register then boot Package entry
          ↓
      runtime contributions become available
```

Only enabled Packages contribute supported runtime behavior.

A disabled Package may remain physically installed without registering:

```text
routes
services
middleware
views
scheduled definitions
package Utils
```

or executing its entry hooks. Automatic command, event, and job directory scans are not part of 13B.

---

## Package runtime hooks

The Package entry extends `App\Plugins\ServiceProvider` and uses the existing two-phase lifecycle:

```text
register
boot
```

The entry loads only for enabled Packages, after static validation and dependency resolution. Registration and boot order is deterministic, with dependencies first.

The distinction must remain:

```text
runtime hooks
→ executed during Application Package activation

install/remove/upgrade operations
→ explicit CLI or manager calls, separate from runtime hooks
```

Normal Application boot must never:

```text
install migrations
delete files
upgrade Package source
rewrite application configuration files
run lifecycle installers
```

merely because a Package is enabled.

---

## Package lifecycle

The familiar commands remain:

```bash
php squehub package:install <source>
php squehub package:remove <package>
```

The implemented Package command family also includes:

```bash
php squehub package:list
php squehub package:enable <package>
php squehub package:disable <package>
php squehub package:upgrade <package> <source>
```

All mutating commands render the shared internal [reviewable change plan](ReviewableChanges.md). `--preview` applies nothing, while `--yes` confirms non-interactive application. Phase 21E adds optional review metadata to the same plan without changing Package execution ownership.

Installation behaves like:

```text
package:install
      ↓
inspect Package source
      ↓
validate Package name / entry / namespace
      ↓
inspect dependencies and known files
      ↓
build the shared internal ChangePlan
      ↓
show collisions and conflicts
      ↓
developer deliberately invokes apply
      ↓
install into Project/Packages/ disabled
      ↓
record a safe source label and owned-file fingerprints
      ↓
use existing Package PSR-4 mappings
      ↓
enable separately after review
```

Managed upgrade/remove protect modified and untracked Package files. Package migrations and Seeders are never run automatically. See [Packages](Packages.md) for the exact 13B contract.

---

## Manual Package import

Manual Package copying/import remains supported.

For the canonical v2 Package root:

```text
copy Package into Project/Packages/
        ↓
SqueHub discovers it
        ↓
validate Package
        ↓
report status
        ↓
developer explicitly enables it
```

Discovery must not automatically mean trusted execution.

Older lower-case physical Package roots remain an upgrade concern; they are not implicitly activated by the canonical 13B registry. Move them to exact canonical paths and review their entry and dependencies before enabling.

---

## Package public API

The established usage remains possible:

```php
use Packages\Weather\Weather;

$forecast = Weather::today();
```

The Package entry class is the intentional public Package API.

Internal Package implementation classes do not automatically become global SqueHub framework APIs.

---

# Kit foundation

Kits are separate from Packages.

Canonical location:

```text
Project/
└── Kits/
    └── Ecommerce/
        ├── Ecommerce.php
        ├── kit.json
        ├── Templates/
        ├── Config/
        └── Assets/
```

The manifest filename is `kit.json`. Format `1` records strict, statically readable metadata describing:

```text
format
name
version
required Packages
explicit source-to-target file mappings
declared lifecycle hooks
```

The mapping allowlist includes normal `Project/` classes, routes and views, root Migrations, Seeders and tests, `Config/` PHP files, and static files under `public/assets/Kits/<KitName>/`. The manifest contains no executable source or secret values. Its exact format and examples are in [Kits](Kits.md).

A Kit should not duplicate an entire running application inside `Project/Kits/`.

Its generated application code goes to normal SqueHub locations.

---

## Package vs Kit

The core distinction is:

```text
Package
→ adds a capability

Kit
→ assembles a solution
```

For example:

```text
Payments Package
```

may provide reusable payment capability.

An:

```text
Ecommerce Kit
```

might compose:

```text
Orders Package
Payments Package
Inventory Package
Customers Package

+
storefront views
admin views
starter routes
configuration
assets
application structure
```

A Kit may depend on Packages.

A runtime Package must never require a Kit merely to function.

That dependency direction should remain:

```text
Package → Package       allowed
Kit     → Package       allowed

Package → Kit           prohibited
```

Kit-to-Kit dependencies should be added only if a real need is demonstrated and cycles can be handled safely.

---

# Kit installation model

The current lifecycle deliberately separates acquisition from activation:

```text
local Kit directory
        ↓ kit:install, with reviewed Change Plan
validate and acquire Project/Kits/Ecommerce/
        ↓
record Kit installed + disabled
        ↓ kit:enable, with separate reviewed Change Plan
resolve installed required Packages through PackageManager
publish declared Project/Config/Migration/Seeder/Test/asset files
        ↓
record ownership and mark Kit enabled
        ↓
verify the resulting application explicitly
```

`kit:install` does not enable the Kit, publish its composition, or run a Migration or Seeder. The installer currently accepts a local directory, not a remote Git URL or ZIP archive. Missing required Package sources are conflicts; installed but disabled requirements appear as visible Package activation actions. Lifecycle hooks execute only during an approved apply, never during preview.

Generated files might end up as:

```text
Project/
├── Controllers/
│   └── ShopController.php
├── Routes/
│   └── Shop.php
├── Views/
│   └── Shop/
├── Packages/
│   ├── Orders/
│   ├── Payments/
│   └── Inventory/
└── Kits/
    └── Ecommerce/
        └── Ecommerce.php and kit.json
```

The private `Project/Activation.json` records Package and Kit activation, source kind, and lifecycle ownership metadata. Existing `Project/Kits/State.json` files are imported read-only until the first explicit lifecycle mutation writes the canonical registry. Do not hand-edit either file. An explicitly copied Kit can be discovered and enabled, but its source definition is not managed for automatic upgrade/removal.

The Kit definition remains under `Project/Kits/`.

Normal application files remain normal Project files.

Reusable capabilities remain Packages.

---

# Kit activation

A Kit may have states such as:

```text
not installed
installed + disabled
installed + enabled
invalid / broken
```

An enabled Kit means:

```text
its composition is considered active
its required Packages are valid
its ownership/configuration is recognized
its declared files have been published through a reviewed operation
Doctor can report aggregate status from static metadata
```

Disabling a Kit does not delete published files. A Kit-published `Project/Routes/` file may continue to register routes as ordinary Project code, and the contribution owner may remain `kit:<Name>` to describe its source. This is file provenance, not Kit entry execution. Phase 19F Studio reads bounded activation metadata and safe metadata from registered routes, including available provenance. Phase 25 Agent/MCP reads bounded activation metadata and only already-registered or validated cached route metadata; it does not load route-source PHP merely for inspection.

Enabled state does **not** mean arbitrary Kit PHP should execute on every request.

Runtime behavior should primarily come from:

```text
normal Project code
+
enabled Packages
```

If something needs permanent per-request runtime boot, it probably belongs in a Package rather than a Kit.

---

# Kit lifecycle hooks

Kits may declare bounded lifecycle hooks for deliberate operations. The `App\Plugins\Kit` base class defines:

```text
beforeInstall
afterInstall

beforeEnable
afterEnable

beforeDisable
afterDisable

beforeUpgrade
afterUpgrade

beforeRemove
afterRemove
```

Each hook accepts `App\Plugins\KitContext` and returns `void`. The Kit manifest explicitly lists hooks it uses. The context supplies only operation, name and version; it is not a ServiceProvider or access to secrets.

These hooks:

```text
run only during explicit lifecycle actions
```

and must never execute during:

```text
static inspection
package/kit listing
contract export
Doctor inspection
Studio read-only inspection
MCP read-only inspection
normal HTTP boot
```

The Kit entry is not a runtime contribution or per-request bootstrap class.

A Change Plan does not sandbox arbitrary hook code.

---

# Kit lifecycle commands

Current working-tree commands:

```bash
php squehub kit:list
php squehub kit:inspect Ecommerce
php squehub kit:install ./Ecommerce --preview
php squehub kit:install ./Ecommerce --yes
php squehub kit:enable Ecommerce
php squehub kit:disable Ecommerce
php squehub kit:upgrade Ecommerce ./Ecommerce-v2
php squehub kit:remove Ecommerce
```

Mutating commands support `--preview` and require `--yes` for non-interactive apply. `kit:list` and `kit:inspect` read metadata without executing Kit PHP. `kit:upgrade` requires a local source directory. A source or destination conflict blocks apply; plan review does not sandbox trusted hook code.

Disabling a Kit must not automatically delete its generated application files.

Removing a Kit first creates a Change Plan identifying:

```text
Kit-owned files
modified Kit-owned files
shared files
required Packages
Packages shared with other Kits/features
configuration
assets
published Migration files, whose replacement/deletion is conservatively blocked
```

The plan must not blindly delete application code. Kit-owned files modified after publication remain protected. Kit-owned Migration replacement or deletion is blocked without querying or changing database Migration history during preview. Kit lifecycle never runs or rolls back Migrations or Seeders.

---

# SqueHub Activation Registry

Phase 13L implements one authoritative Application-scoped view over the canonical `Project/Activation.json`, Package metadata, and Kit definitions. It answers:

```text
What is installed?
What is enabled?
What is disabled?
What is invalid?
What depends on what?
Who owns what?
What should boot?
```

Conceptually:

```text
Packages
────────────────────
Payments      enabled
Orders        enabled
Inventory     enabled
Reports       disabled

Kits
────────────────────
Ecommerce     enabled
Admin         disabled
```

`ActivationRegistry` offers typed `package` and `kit` lookup, status, dependencies, dependents, a read-only snapshot, and enabled Package boot order. The first explicit lifecycle mutation merges valid legacy `Project/Packages/State.json` and `Project/Kits/State.json` into one canonical file. Normal boot and inspection do not write. Once canonical state exists, it wins; Doctor warns about divergent legacy metadata. See [SqueHub Activation Registry](ActivationRegistry.md) for the implemented contracts.

Requirements:

```text
file/config based
deterministic
versionable
no database requirement
no Redis requirement
safe on shared hosting
portable between Windows/Linux
```

Do not store secrets in activation metadata.

---

# Dependency resolution

Before enabling a Package:

```text
Package Commerce
requires:
    Payments
    Inventory
```

SqueHub checks:

```text
Payments   enabled
Inventory  disabled
```

and produces a plan:

```text
Cannot enable Commerce directly.

Required change:
ENABLE Inventory
ENABLE Commerce
```

No silent dependency activation.

---

## Kit dependencies

Example:

```text
Ecommerce Kit
requires:
    Orders
    Payments
    Inventory
```

Activation may produce:

```text
Orders       enabled
Payments     disabled
Inventory    enabled

Plan:
ENABLE Payments
ENABLE Ecommerce Kit
```

Again, the developer approves the plan.

---

# Shared dependencies

A Package may be required by:

```text
another Package
multiple Kits
normal application code
```

Therefore disabling/removing a Package must check dependency provenance.

Example:

```text
Payments Package

Required by:
- Ecommerce Kit
- Subscription Package
```

SqueHub must block or plan those dependent changes rather than silently disabling Payments.

Likewise, disabling an Ecommerce Kit must not automatically disable Payments if another active Package/Kit still requires it.

---

# Ownership model

SqueHub should distinguish:

```text
owned
required
shared
generated
published
user-modified
```

For example:

```text
Ecommerce Kit

OWNS
Project/Views/Shop/*
Assets/shop/*

REQUIRES
Orders Package
Payments Package

GENERATED
Project/Routes/shop.php

SHARED
Config/app.php
```

Removal/upgrade decisions depend on that metadata.

A file that was generated by a Kit and later modified by the developer must not be silently deleted or replaced.

---

# Package/Kit provenance

The framework should be able to explain:

```text
Route:
orders.show

Owner:
Orders Package

Enabled because:
Orders Package enabled

Required by:
Ecommerce Kit
```

or:

```text
View:
shop.checkout

Created by:
Ecommerce Kit

Uses:
Payments Package
Orders Package
```

This provenance should later feed:

```text
13C provenance foundation; side-effect-free Explain commands remain deferred
19F SqueHub Studio
21E Change Plans
25A MCP
25E AI change plans
```

without creating a second introspection architecture.

---

# Packages are the large-application boundary

Do not introduce:

```text
Project/Modules/
Project/Domains/
Project/Features/
```

for the same responsibility.

Small application:

```text
Project/
├── Controllers/
├── Models/
├── Routes/
└── Views/
```

Large/self-contained capability:

```text
Project/
└── Packages/
    └── Billing/
```

Packages remain optional.

A tiny SqueHub application does not need them.

---

# Feature Blueprints and Packages

The Phase 13I command is:

```bash
php squehub make:feature Order --package=Commerce --preview
```

It plans Package-local classes and routes through one shared change plan. This example defaults to table `commerce_order`, path `/commerce/order`, and route name `commerce.order.index` without English pluralization. `--table` and `--route` provide explicit overrides. The current root migration and test runners do not discover files nested inside a Package. Package-targeted Blueprint Migrations therefore use root `Database/Migrations/`, and generated tests use root `Tests/Integration/`, with Package ownership retained in the plan.

Review the action list before applying. A representative outline is:

```text
Project/Packages/Commerce/
├── Models/Order.php
├── Controllers/OrderController.php
├── Api/Resources/OrderResource.php
├── Validation/OrderValidation.php
└── Routes/Features/Order.php
Database/Migrations/<timestamp>_create_commerce_order_table.php
Tests/Integration/CommerceOrderFeatureTest.php
```

Before writing:

```text
CREATE Project/Packages/Commerce/Models/Order.php
CREATE Project/Packages/Commerce/Controllers/OrderController.php
CREATE Project/Packages/Commerce/Validation/OrderValidation.php
CREATE Project/Packages/Commerce/Routes/Features/Order.php
CREATE Database/Migrations/<timestamp>_create_commerce_order_table.php
CREATE Tests/Integration/CommerceOrderFeatureTest.php

Conflicts: 0
Migration execution: NOT INCLUDED
Seeder execution: NOT INCLUDED
```

---

# Feature Blueprints and Kits

A Feature Blueprint builds one bounded feature through the generator plan. A Kit applies an explicitly declared application composition from a local definition. The commands are separate:

```bash
php squehub kit:install ./Ecommerce --preview
php squehub kit:install ./Ecommerce --yes
php squehub kit:enable Ecommerce --preview
php squehub kit:enable Ecommerce --yes
```

The install plan acquires a definition and leaves it disabled. A later enable plan may show:

```text
ENABLE installed Package Orders
ENABLE installed Package Payments
ENABLE installed Package Inventory

CREATE Project/Controllers/ShopController.php
CREATE Project/Views/Shop/Index.squehub.php
CREATE Project/Routes/Shop.php
CREATE public/assets/Kits/Ecommerce/storefront.css

ENABLE Ecommerce Kit

Migration execution: NOT INCLUDED
Seeder execution: NOT INCLUDED
```

Every file must be declared in `kit.json`; the plan does not expand wildcard destinations. Missing required Package sources are conflicts, not implicit downloads. The developer sees and approves the plan before application.

---

# Setup integration

`php squehub setup` guides application configuration without requiring a starter profile. Application Profiles are deferred in Phase 13H because the current starter has no distinct, complete profile scaffolds; Phase 13I covers Feature Blueprints and Phase 13K covers Kits. A future profile or Kit choice must show every proposed file, Package, and configuration change in a reviewable plan before application. Neither a Kit nor a Package may be installed implicitly.

Manual setup remains:

```text
copy .example.env → .env
php squehub key:generate
set APP_KEY
review database/application configuration
verify writable Storage
php squehub doctor
```

---

# Studio integration through Phase 19F

The implemented, local-only [SqueHub Studio](Studio.md) now shows bounded Package and Kit activation summaries from the registry. For example:

```text
Packages
────────────────────────
Payments       Enabled
Orders         Enabled
Inventory      Enabled
Reports        Disabled

Kits
────────────────────────
Ecommerce      Enabled
Admin          Disabled
```

Deeper per-Package inspection may later show:

```text
routes
controllers
services
views
dependencies
dependents
configuration
health/status
```

Deeper per-Kit inspection may later show:

```text
required Packages
owned/generated files
configuration
assets
activation state
composition health
```

Those deeper views are still future work. The current Studio does not execute Package or Kit entries to invent missing details and cannot change activation state.

---

# MCP/AI inspection boundary

Phase 25 MCP can expose bounded Package/Kit activation summaries, including which Packages are enabled and which Kits are active. More detailed provenance and impact explanations remain future work, including:

```text
Why does /shop/cart exist?
What Package owns orders.create?
What depends on Payments?
What will disabling Ecommerce affect?
```

AI changes must still flow through:

```text
Inspect
   ↓
Change Plan
   ↓
Developer review
   ↓
Apply deliberately
   ↓
Tests
   ↓
Prove
```

MCP must never bypass Package/Kit activation or Change Plan rules.

---

# Phase 13 acceptance boundary

By the end of Phase 13, SqueHub should be able to:

```text
PACKAGES

discover Packages
validate Package structure
identify the main entry/public API
install safely
enable
disable
upgrade safely
remove safely
preserve manual import
resolve dependencies
detect dependency cycles
track ownership
track provenance
load only enabled Packages
refresh autoload deterministically
generate code into a Package
test a Package in isolation

KITS

discover Kits
validate Kit metadata
install Kits
enable
disable
upgrade safely
remove safely
resolve required Packages
track generated/published files
track ownership
track composition state
protect user-modified files
identify Package dependencies
verify active composition

SHARED

maintain deterministic activation registry
show installed/enabled/disabled/broken state
block unsafe dependency removal
show Change Plans before modification
support Windows/Linux case rules
require no database/Redis for registry
feed Explain/Studio/MCP provenance
```

without:

```text
inventing a second module system

confusing Packages with Kits

booting disabled Packages

executing arbitrary Kit code on every HTTP request

silently enabling dependencies

silently overwriting user files

silently deleting modified files

silently running migrations

blindly removing shared Packages

executing lifecycle hooks during static inspection

requiring Packages/Kits for simple applications

requiring Redis, Node or a database for activation state
```

The architectural rule is:

> **Packages build capabilities. Kits build solutions.**

And the lifecycle rule is:

> **Installed does not automatically mean enabled. Discovery does not automatically mean trusted execution.**

The broader SqueHub workflow remains:

> **Inspect → Plan → Apply deliberately → Verify.**

Phase 13 Kit commands and hooks are described by the [Kit guide](Kits.md). The shared [SqueHub Activation Registry](ActivationRegistry.md) is implemented in the working tree; other proposals in later phases remain planned APIs. Phase 13 implementation work packages have resolved outcomes. The user reported that Phase 13L passed Linux/WSL verification, with no exact later totals or versions supplied. Clean-checkout and wider release gates remain open.

## Phase 14 — Views and server-rendered UI (14A–14P complete and cross-platform qualified in working tree)

Preserve `.squehub.php`, escaped `{{ }}`, raw `{!! !!}`, layouts, sections, includes, CSRF, old input, validation errors, and existing [View guide](Views.md) contracts.

The goal is a **complete, predictable, production-grade SqueHub template engine** that remains simple for ordinary PHP applications.

Templates are trusted application code; the compiler is not a sandbox for untrusted templates.

| Package | Priority | Intended result |
| --- | --- | --- |
| **14A Shared view context — implemented in working tree** | Required | Application-owned shared values, lazy request-scoped providers and per-view composers with explicit precedence and reserved-name protection. Auth, CSRF, flash, Session, and request data must not leak across requests or Application instances. See [Shared View Context](ViewContext.md) for the actual API and its limits. |
| **14B Reliable Template Compiler — implemented in working tree** | Required | A scanner and balanced argument parsing handle nested and multiline expressions, quoted delimiters, existing layouts, sections, includes, and CSRF without changing escaped or raw output. Development errors identify the logical View and source line; production responses follow the safe error boundary. See [Reliable Template Compiler](TemplateCompiler.md). This phase adds no components or new conditional and loop directives. |
| **14C Conditionals and Control Flow — implemented in working tree** | Required | Structurally validated `@if`/`@elseif`/`@else`/`@endif`, `@unless`/`@endunless` with optional `@else`, and `@switch`/`@case`/`@default`/bare `@break`/`@endswitch`. Balanced, multiline expressions remain trusted PHP evaluated at render time. Switch uses normal PHP fall-through, and compiler errors identify the logical View and source line. See [Conditionals and Control Flow](Conditionals.md). |
| **14D Loops and Iteration State — implemented in working tree** | Required | Structurally checked `@foreach`, `@forelse`/`@empty`, `@for`, and `@while` use trusted PHP expressions at render time. Finite `@foreach` and `@forelse` sequences expose read-only `$loop` metadata: `index`, `iteration`, `remaining`, `count`, `first`, `last`, `even`, `odd`, `depth`, and `parent`; `@for` and `@while` do not fabricate it. Bare `@break` and `@continue` follow validated loop/switch scope. See [Loops and Iteration State](Loops.md) for iterable preparation and scope limits. |
| **14E Layouts and Sections — implemented in working tree** | Required | One static `@extends` declaration per template, named section capture, lazy escaped `@yield` defaults, nested layouts with the most specific child section winning, same-template duplicate rejection, and safe circular-layout detection. See [Layouts and Sections](Layouts.md) for the current contract. |
| **14F Template-owned Scripts, Styles and Asset Stacks — implemented in working tree** | Required | Rendered pages, layouts, included partials, and enabled Package Views own their `@style`/`@script` resources and `@push`/`@prepend` blocks. Layouts place named `@stack` output. Direct resources deduplicate, optional `once` keys identify repeated declarations, and external Application registrations must name participating logical Views. Collected assets belong to one top-level render; no Node or bundler is required. See [Template-owned Scripts, Styles and Asset Stacks](Assets.md). Phase 14H extends the owner model to components. |
| **14G Includes and Reusable Partials — implemented in working tree** | High | Required `@include` inherits context and accepts a subtree-only explicit data overlay. `@includeOptional` skips genuine absence; `@includeWhen` lazily skips when its condition is false. All use quoted static logical names and the same contained View resolver. Active physical View identity detects direct and alias-based include cycles; repeated completed includes remain valid. A partial contributes assets only when rendered. See [Includes and Reusable Partials](Includes.md). |
| **14H Components, Props and Slots — implemented in working tree** | High | Paired `@component` invocations render contained `Components.*` Views. Component templates declare required/defaulted `@props`; caller-scope content becomes immutable default/named slots; a separate immutable attribute bag safely renders HTML attributes. Component scope excludes undeclared caller values. Nested components, mixed component/include cycle checks, active `$loop`, and component-owned deduplicated assets use the existing render tree. See [Components, Props and Slots](Components.md). No component-class lifecycle or typed prop schema is provided. |
| **14I Forms and Validation UX — implemented in working tree** | High | Existing Request validation, filtered Session old input, ErrorBag, and global CSRF remain authoritative. `@error` scopes the first field message; an ordered summary uses `$errors->all()`. `@method`/`method_field()` tunnel only POST form bodies to PUT/PATCH/DELETE, and `checked()`/`selected()` emit fixed attributes from explicit booleans. CSRF still runs before routing and controllers. See [Forms and Validation UX](Forms.md). No form builder, model binding, or new CLI command was added. |
| **14J Auth, Guards, Session and Authorization — implemented in working tree** | Required | `@auth`/`@guest` use the current default or an explicit named guard. `@can`/`@cannot` use existing global abilities or resource policies; `@session` presents a literal Session key and active flash data with a scoped `$value`. All support `@else` and evaluate at render time. These control rendering only; middleware and server-side Authorization remain the security boundary. See [Auth, Guards, Session and Authorization](ViewSecurity.md). |
| **14K Template utilities — implemented in working tree** | Medium | `json()` and `@json` encode runtime values with HTML-dangerous character protection; `classes()` composes ordered, conditional class values; `environment()` and `debugging()` read the selected Application at render time. Phase 14I `checked()`/`selected()` remain the boolean attribute helpers. See [Template Utilities](TemplateUtilities.md) for syntax, escaping contexts, and failure behavior. |
| **14L Fragments and Partial Responses — implemented in working tree** | Medium | Static `@fragment('name') ... @endfragment` boundaries belong to the requested root View and render transparently in a full page. `View::fragment($view, $name, $data)` executes only the selected body and returns immutable HTML plus finalized named asset stacks. Root View context, Auth/Session/form state, Includes and Components continue to work; unrelated siblings and Layout markup do not execute. The application explicitly chooses an HTTP transport; no AJAX detection, client protocol, or returnable View response was added. See [Fragments and Partial Responses](Fragments.md). |
| **14M Diagnostics and Testing — implemented in working tree** | High | Structured source-aware compiler diagnostics, logical missing-View/dependency and active-cycle chains, safe runtime View attribution, and generic production HTTP errors. `TestCase::view()`/`fragment()` and `ViewTestResult` exercise the real renderer with output, escaping, ordering, finalized stack, CSRF, and method-field assertions. Existing Auth, Session, and validation testing helpers compose with direct View tests. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md). |
| **14N Compiled Views and Production Lifecycle — implemented in working tree** | High | Source-authoritative, content-addressed artifacts under Application-owned `Storage/Views`; atomic publication, current-source containment, same-size/mtime invalidation, bounded corruption recovery, optional targeted OPcache invalidation, and shared-hosting on-demand compilation. `view:cache` precompiles current supported Views without rendering; `view:clear` removes only owned derived artifacts. See [Compiled Views and Production Lifecycle](CompiledViews.md). |
| **14O Package and Namespaced Views — implemented in working tree** | High | Enabled Packages contribute exact, collision-safe View namespaces through the existing Application registry. `Commerce::Orders.Index` selects a safe application override under `Project/PackagesViews/Commerce/` before the Package's `Views/` source; ordinary unqualified lookup remains intact. Package activation, source containment, compiled authority, and platform casing still apply. See [Package and Namespaced Views](PackageViews.md). |
| **14P Returnable View Responses — implemented and cross-platform qualified in working tree** | Required | `View::response($view, $data, $status, $headers)` eagerly captures the existing full render without echoing and returns an ordinary `Response` for the existing HTTP pipeline. It defaults to HTML content type, accepts status and headers through Response validation, and leaves `View::render()` and Fragment transport unchanged. See [Returnable View Responses](ViewResponses.md). |

Phase 14A–14P is complete and cross-platform qualified in the development working tree. The reported Phase 14 Windows/SQLite full suite passed **2,161 tests, 15,009 assertions, and 59 skips**; Linux/SQLite passed **2,161 tests, 15,213 assertions, and 14 skips**, with no failures, errors, or risky tests in either run. Phase 14P adds returnable full-page HTTP responses to the existing View platform. The wider v2.0.0 release gate remains open. The implementation retains the form contracts in [Forms and Validation UX](Forms.md), the presentation/enforcement boundary in [Auth, Guards, Session and Authorization](ViewSecurity.md), the escaping rules in [Template Utilities](TemplateUtilities.md), the selected-body execution boundary in [Fragments and Partial Responses](Fragments.md), the [diagnostic/testing contract](ViewDiagnosticsTesting.md), the [compiled lifecycle](CompiledViews.md), and the [Package namespace contract](PackageViews.md).

### Core template syntax

The examples below show the intended syntax by the end of Phase 14. Phases 14B–14P provide the compiler foundation, validated conditionals and loops, [Layouts and Sections](Layouts.md), [asset stacks](Assets.md), [Includes and Reusable Partials](Includes.md), [Components, Props and Slots](Components.md), [Forms and Validation UX](Forms.md), [Auth, Guards, Session and Authorization](ViewSecurity.md), [Template Utilities](TemplateUtilities.md), [Fragments and Partial Responses](Fragments.md), [View Diagnostics and Testing](ViewDiagnosticsTesting.md), the [compiled View lifecycle](CompiledViews.md), [Package and Namespaced Views](PackageViews.md), and [Returnable View Responses](ViewResponses.md).

```php
{{ $name }}
{!! $trustedHtml !!}

@if ($user)
    ...
@elseif ($guest)
    ...
@else
    ...
@endif

@unless ($disabled)
    ...
@endunless

@foreach ($users as $user)
    {{ $loop->iteration }}. {{ $user->name }}
@endforeach

@forelse ($users as $user)
    ...
@empty
    No users found.
@endforelse

@for ($i = 0; $i < 10; $i++)
    ...
@endfor

@while ($condition)
    ...
@endwhile

@switch($status)
    @case('active')
        ...
        @break

    @default
        ...
@endswitch
```

### Authentication and authorization

Default authenticated user:

```php
@auth
    <p>Welcome, {{ auth()->user()->name }}</p>
@else
    <a href="/login">Login</a>
@endauth
```

Guest-only content:

```php
@guest
    <a href="/login">Login</a>
@endguest
```

Named guard:

```php
@auth('admin')
    <a href="/admin">Admin Dashboard</a>
@else
    <a href="/admin/login">Admin Login</a>
@endauth
```

Authorization:

```php
@auth
    @can('admin.access')
        <a href="/admin">Administration</a>
    @endcan
@endauth
```

Resource-aware authorization:

```php
@can('update', $order)
    <a href="/orders/{{ $order->id }}/edit">Edit</a>
@endcan
```

The distinction must remain clear:

```text
@auth                 → Is the default identity logged in?
@auth('admin')        → Is the named guard authenticated?
@can('admin.access')  → Is the identity authorized?
@session(...)         → Does request/session state contain a value?
```

SqueHub must not assume `admin` is always a special role. It may be a named guard in one application and part of a separately registered global authorization ability in another. A resource policy check uses a policy method name such as `update`; a dotted global ability such as `reports.view` has no resource argument.

Existing flash data uses the same literal Session-key presentation as ordinary data:

```php
@session('status')
    <div role="status">{{ $value }}</div>
@endsession
```

`$value` is scoped to the present-key block. The directive does not consume flash or mutate Session state. View checks do not replace route middleware or server-side `authorize()->require(...)`.

### Template utilities

Phase 14K adds JSON output and small runtime presentation helpers without changing escaped output defaults:

```php
<script>const report = @json($reportData);</script>
<div class="{{ classes(['report', 'report-featured' => $featured]) }}"></div>

@if (environment('local') && debugging())
    <small>Local preview</small>
@endif
```

Use `{{ json($reportData) }}` in a quoted HTML attribute. `@json` emits JSON directly in JavaScript or JSON text; it does not turn arbitrary data into trusted HTML. See [Template Utilities](TemplateUtilities.md) for validation, escaping, and Application-context details.

### Fragments and partial responses

Phase 14L adds an explicit, View-local boundary. A full render includes the body normally; an independent render selects one region without executing siblings or the surrounding Layout:

```php
@extends('Layouts.App')

@section('content')
    <h1>Orders</h1>

    @fragment('orders.list')
        @forelse ($orders as $order)
            @component('OrderCard', ['order' => $order])
            @endcomponent
        @empty
            <p>No orders.</p>
        @endforelse

        @script('/assets/orders/list.js', once: 'orders-list')
    @endfragment
@endsection
```

`View::render('Orders.Index', ['orders' => $orders])` emits the page; `$fragment = View::fragment('Orders.Index', 'orders.list', ['orders' => $orders])` returns immutable `html()` and finalized `stack()`/`stacks()` output for the selected body. The application chooses an existing `Response` or `JsonResponse` to transport those values. Fragment selection never infers AJAX, changes a Section's purpose, or grants authorization. See [Fragments and Partial Responses](Fragments.md).

### Layouts and sections

The `@extends`, `@section`, `@endsection`, and `@yield` forms below are available through Phase 14E. Phase 14F adds the `@stack` locations in this layout. See [Layouts and Sections](Layouts.md) for section precedence and [Template-owned Scripts, Styles and Asset Stacks](Assets.md) for current resource declarations and ordering.

Page:

```php
@extends('Layouts.App')

@section('title')
    Dashboard
@endsection

@section('content')
    <h1>Dashboard</h1>
@endsection
```

Layout:

```php
<!DOCTYPE html>
<html>
<head>
    <title>@yield('title', 'SqueHub Application')</title>

    @stack('head')
    @stack('styles')
</head>

<body>
    @yield('content')

    @stack('scripts')
    @stack('scripts-after')
</body>
</html>
```

### Template-owned scripts and styles

The following `@style` and `@script` directives are available in Phase 14F. They register resources for the layout's stacks without emitting tags at their declaration positions.

A template owns the resources it declares:

```php
@extends('Layouts.App')

@style('/assets/css/dashboard.css')
@script('/assets/js/dashboard.js')

@section('content')
    <h1>Dashboard</h1>
@endsection
```

Conceptually:

```text
Dashboard.Index
    ├── /assets/css/dashboard.css
    └── /assets/js/dashboard.js
```

Those resources are loaded only when `Dashboard.Index` participates in the current render.

Rendering:

```text
auth.login
```

must not load:

```text
dashboard.css
dashboard.js
```

### Asset stacks

`@push` and `@prepend` are available in Phase 14F. Captured blocks execute once per reached declaration and are emitted at the matching stack position.

Templates may still push arbitrary resource blocks:

```php
@push('styles')
<link rel="stylesheet" href="/assets/dashboard.css">
@endpush

@push('scripts')
<script src="/assets/dashboard.js"></script>
@endpush
```

Or prepend something that must appear earlier:

```php
@prepend('scripts')
<script src="/assets/polyfill.js"></script>
@endprepend
```

### External asset registration

When an asset is registered outside its template, ownership must be explicit:

```php
View::assets()
    ->for('Dashboard.Index')
    ->style('/assets/css/dashboard.css')
    ->script('/assets/js/dashboard.js');
```

Multiple owners may be deliberate:

```php
View::assets()
    ->for([
        'Reports.Index',
        'Reports.Show',
    ])
    ->script('/assets/js/reports.js');
```

`View::assets()` is Application-scoped configuration. A registration emits only when one of its exact logical View owners participates in the current render; use the same spelling as `View::render()` or `@include`. See the [asset guide](Assets.md) for the current API and isolation rules.

### Component-owned assets

Phase 14H components use the resource ownership model established by Phase 14F. `Components.Chart` may declare its own dependencies:

```php
@props(['report'])

@style('/assets/components/chart.css', once: 'chart-style')
@script('/assets/components/chart.js', once: 'chart-runtime')

<div class="chart">{!! $slot->toHtml() !!}</div>
```

If that component renders twenty times:

```php
@foreach ($reports as $report)
    @component('Chart', ['report' => $report])
        {{ $report->title }}
    @endcomponent
@endforeach
```

the page should still emit:

```html
<link rel="stylesheet" href="/assets/components/chart.css">
<script src="/assets/components/chart.js"></script>
```

only once. The component body is a captured caller-scope slot; its template receives only the declared `report` prop plus component runtime bindings. See [Components, Props and Slots](Components.md) for named slots and attributes.

### Asset ownership model

For:

```text
Layouts.App
    ↓
Dashboard.Index
    ↓
Partials.Analytics
    ↓
Components.Chart
```

SqueHub collects resources from only those rendered participants:

```text
Layouts.App
    → app.css
    → app.js

Dashboard.Index
    → dashboard.css
    → dashboard.js

Partials.Analytics
    → analytics.js

Components.Chart
    → chart.css
    → chart.js
```

Then:

```text
collect
→ order
→ deduplicate
→ emit into layout stacks
```

The rule is:

> **Templates decide what they need. Layouts decide where it appears.**

External registrations are Application-scoped configuration. Participating owners, collected assets, stack output, and one-time keys belong to one top-level render; they do not leak into another render, request, or Application instance.

### Includes

```php
@include('Partials.Editor')
@include('Partials.Editor', ['mode' => 'compact'])
@includeOptional('Partials.Promo')
@includeWhen($editing, 'Partials.Toolbar', ['mode' => 'compact'])
```

`Partials.Editor` inherits page values such as `$post` automatically. The explicit data argument overrides only the included subtree. An absent optional View emits nothing; a false conditional include does not evaluate its data or activate that View. Required missing Views and unsafe resolutions still fail. See [Includes and Reusable Partials](Includes.md) for cycle protection, source diagnostics, and nested scope.

If `Partials.Editor` declares:

```php
@style('/assets/editor/editor.css')
@script('/assets/editor/editor.js')
```

those resources load only when that partial actually renders.

### Forms

```php
<form method="POST" action="/account">
    @csrf

    <input
        type="text"
        name="name"
        value="{{ old('name', $user->name) }}"
    >

    @error('name')
        <span>{{ $message }}</span>
    @enderror

    <button type="submit">Save</button>
</form>
```

The final API should preserve existing CSRF/ErrorBag/old-input behavior rather than building duplicate systems.

### Complete page example

```php
@extends('Layouts.App')

@style('/assets/css/dashboard.css')
@script('/assets/js/dashboard.js')

@section('title')
    Dashboard
@endsection

@section('content')

    @auth
        <h1>Welcome, {{ auth()->user()->name }}</h1>

        @can('reports.view')

            @forelse ($reports as $report)

                <article>
                    <h2>{{ $report->title }}</h2>

                    @if ($loop->first)
                        <span>Newest report</span>
                    @endif
                </article>

            @empty
                <p>No reports available.</p>
            @endforelse

        @endcan

    @else
        <p>Please sign in.</p>
    @endauth

@endsection
```

### Phase 14 acceptance boundary

By the end of Phase 14, SqueHub Views should support:

```text
{{ }} / {!! !!}

@if / @elseif / @else
@unless
@switch / @case / @default

@foreach / @forelse
@for / @while
@break / @continue
$loop metadata

@auth / @guest
named guards
@can / @cannot
Session/flash presentation

Layouts
Sections
Includes
Components
Props
Slots

CSRF
Old input
Validation errors
Form helpers
JSON output and class composition
Environment/debug presentation

Template-owned CSS
Template-owned JavaScript
Named asset stacks
Asset ordering
Asset deduplication
Component/partial/package assets

Fragments
Returnable View responses
Source-aware errors
View testing
Compiled-view caching
Package namespaces
```

### Architectural boundaries

```text
Templates are trusted application code.

Escaping guarantees must be explicit.

Auth directives use existing SqueHub Auth.

Authorization directives use existing SqueHub Authorization.

Template visibility never replaces server-side security enforcement.

Every page-specific resource belongs to a rendered template/component/partial.

Layouts control resource placement.

Assets never leak between requests or Application instances.

Compilation never embeds runtime secrets.

No Node.js is required for ordinary server-rendered Views.

No Redis or database is required for rendering.

Shared-hosting deployment remains first-class.
```

The intended result is:

> **Simple templates on the surface. A reliable compiler underneath.**


## Phase 15 — HTTP and routing depth (in progress)

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **15A Route model binding** | High | Implemented in the development working tree with route-local `->bind('user', User::class, key: 'slug')`, normal Model primary-key/connection/soft-delete lookup, and existing browser/API 404 handling. Binding happens after route middleware; raw Request route values remain scalar. No registration, introspection, or static contract-export query is part of binding. The user reports successful Linux/WSL qualification; Codex did not run or inspect that execution. This is not a release-ready claim. See [Routing](Routing.md#bind-a-route-parameter-to-a-model). |
| **15B Route parameter depth** | High | Implemented in the development working tree and qualified on Windows and user-run Linux/WSL: trailing `{name?}` segments, route-local `where()` constraints, exact/dynamic `host()` conditions, and static-prefix `fallback()` handlers use the existing path-first registry and middleware. Normal routes and 405 precede fallback. Static route inspection does not resolve Models. Optional-path, host-restricted, and fallback routes are runtime-only because the current native Contract/OpenAPI/SDK model cannot represent them faithfully; attaching `OperationContract` is rejected. Final Windows: 2,309 tests/15,995 assertions/62 skips. User-run WSL Ubuntu: 2,309 tests/16,199 assertions/17 skips, with no failures or errors in either. See [Routing](Routing.md). |
| **15C Response capabilities** | High | Implemented in the development working tree and qualified on Windows and user-run Linux/WSL. Windows: 2,332 tests/16,209 assertions/62 skips. Linux/WSL: 2,332 tests/16,413 assertions/17 skips. The existing HTTP Response gains validated typed cookies, bounded-memory local attachment/inline files, in-memory binary bytes, and lazy chunk producers. Explicit Request passing enables one bounded byte range for regular local files; multipart and conditional ranges remain deferred. Existing JSON, redirect, View, API, CORS, and HEAD behavior remains in the normal response path. See [HTTP Responses](Responses.md). |
| **15D Trusted proxies** | Required for proxy claims | Implemented in the development working tree and qualified on Windows and user-run Linux/WSL. Windows: 2,354 tests/16,327 assertions/62 skips; Linux/WSL: 2,354 tests/16,531 assertions/17 skips, with no failures/errors and no risky tests reported. Application-scoped exact/CIDR proxy trust, one explicit `Forwarded` or `X-Forwarded-*` profile, nearest-hop client IP resolution, safe scheme/Host resolution, optional allowed-host policy, spoofing tests, and deployment guidance are present. No automatic private-network or vendor IP trust; direct deployments retain their existing behavior. See [HTTP request metadata](Http.md#trusted-proxies-and-request-metadata). |
| **15E Base-path/subdirectory hosting** | High | Implemented in the development working tree; Windows and user-run WSL/Linux and XAMPP verification reported: explicit application URL mount such as `/app` through `APP_BASE_PATH`/`Config/Http.php`, while route declarations remain application-relative. Bounded request path stripping, named paths, redirects, browser Views/forms/assets, API scopes, and the built-in development server's mounted assets are covered by the post-change Windows suite: **2,380 tests, 16,488 assertions, 63 skips, 0 failures, 0 errors, 0 risky**. An isolated Windows Apache 2.4.58 `/app/` smoke also passed for root HTML, a static asset, HTML/API 404s, and an outside-mount 404. The user reports that WSL/Linux and a later real XAMPP corrective verification passed. Named-route/redirect live Apache proof, Linux Apache, and cPanel hosting remain pending. The Phase 11G XAMPP root smoke proved document-root hosting only. |

## Phase 16 — Database and ORM depth (implemented early before Phase 15; user-reported Linux and MySQL checks passed)

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **16A Nested eager loading** | High | **Implemented in working tree.** `with('posts.comments.author')`, Model `load()`, and collection `load()` batch shared relation paths with bounded depth and query behavior. See [Relationships](Relationships.md). |
| **16B Polymorphic relations** | Advanced | **Implemented in working tree.** Explicit Application-owned morph maps resolve bounded stored aliases; `morphTo`, `morphOne`, and `morphMany` support lazy and eager loading. Arbitrary stored class names and polymorphic many-to-many are unsupported. See [Relationships](Relationships.md). |
| **16C Cursor pagination** | High | **Implemented in working tree.** Forward opaque `CursorPage` traversal requires stable ordering and a unique tie-breaker; offset `Page` remains available. See [Pagination](Pagination.md). |
| **16D Model lifecycle/observers** | High | **Implemented in working tree.** Explicit Model persistence events and Application-owned observer registration have defined ordering and no automatic discovery. See [Models](Models.md). |
| **16E Concurrency helpers** | High | **Implemented in working tree with backend limits.** Nested savepoints and after-commit callbacks retain their existing contract; bounded transient retries are outermost only. MySQL has explicit transaction isolation and transactional row locks; SQLite rejects those MySQL-specific controls. Migration runs use a narrow deployment lock. See [Database](Database.md) and [Migrations](Migrations.md). |
| **Final ORM relation completeness pass** | High | **Implemented in working tree; Windows/SQLite and user-reported Linux/WSL and live MySQL verification.** Nested `has()`/`doesntHave()`/`whereHas()` paths use correlated `EXISTS`; direct-relation `withCount()` yields non-persisted count projections. Read-only `hasOneThrough` and `hasManyThrough` support bounded lazy/eager reads and same-connection existence/count. MorphTo existence accepts only registered, bounded aliases; target-specific `whereHasMorph()` avoids heterogeneous-schema guesses. Exact later Linux/WSL and MySQL totals and versions were not supplied. Polymorphic many-to-many, composite primary keys, Unit of Work, identity map, automatic flush, second-level cache, replicas, and sharding remain deferred. See [Relationships](Relationships.md) and [Models](Models.md). |

The pre-Phase-15 completion pass also extends bound QueryBuilder operations, bulk inserts/upserts, keyset chunking, Model state and guarded mass assignment, Schema inspection, and migration reliability. The earlier 16A–16E work has user-reported Linux/SQLite and guarded live MySQL 8.4.11 evidence for selected paths. The final relation additions have fresh Windows/SQLite checks; the user later reported that their Linux/WSL and live MySQL runs passed, without exact later totals, versions, or database setup details. This is an implementation checkpoint, not a stable v2.0.0 release or a clean-checkout qualification.

## Phase 17 — Identity and security depth (complete; Windows, Linux/WSL, and applicable live MySQL qualified)

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **17A Remember-me** | High | **Implemented in working tree.** Opt-in persistent login rotates an opaque selector/validator pair, stores only a digest, and checks expiry and credential changes. See [Authentication](Authentication.md#optional-remembered-browser-login). Guarded live MySQL 8.4.11/InnoDB qualification passed on a user-run disposable database. |
| **17B Permissions/RBAC** | High | **Implemented in working tree.** Optional roles and permissions feed Authorization below explicit ability decisions; policies retain their own boundary. See [RBAC](RBAC.md). Guarded live MySQL 8.4.11/InnoDB qualification passed on a user-run disposable database. |
| **17C MFA** | Advanced | **Implemented in working tree.** Optional TOTP and one-time recovery codes guard Session login and remembered recall; secrets are encrypted and proofs rate-limited. WebAuthn/passkeys were evaluated and deferred. See [MFA](MFA.md). Guarded live MySQL 8.4.11/InnoDB qualification passed on a user-run disposable database. |
| **17D Browser security policy** | High | **Implemented in working tree.** Explicit CSP, HTTPS-only HSTS, Referrer-Policy, frame restrictions, and cookie guidance are available; policy defaults off. See [Browser security policy](BrowserSecurityPolicy.md). |
| **17E Signed URLs** | High | **Implemented in working tree.** Purpose-bound, expiring named links use Crypt-derived signing material and strict path/query/method canonicalization. Route middleware gives a generic browser/API 403; root and mounted URLs, key rotation, downloads, and RBAC composition have Windows tests. Signed links do not grant authorization or single-use behavior. See [Signed URLs](SignedUrls.md). Linux/WSL qualification passed in the user-run full suite. |

Phase 17 qualification is complete in the reported Windows, Linux/WSL, and applicable live MySQL profiles. Windows passed **2,471 tests, 17,442 assertions, 69 skips**. The user-run normal Linux suite passed **2,471 tests, 17,656 assertions, 20 skips**; the guarded MySQL 8.4.11/InnoDB remember-me, RBAC, and MFA tests passed **63**, **93**, and **39** assertions respectively. The full Linux suite with MySQL opt-in passed **2,471 tests, 18,107 assertions, 12 skips**; after temporary database/user cleanup and environment-variable removal, the normal suite returned to **2,471 tests, 17,656 assertions, 20 skips**. All reported runs had zero failures and errors; Windows also reported zero risky tests. Codex did not run Linux or live MySQL. Clean committed-checkout reproduction and release approval remain separate.

## Phase 18 — Background work and realtime (complete in working tree)

The existing Queue owns retries, failures, workers, and after-commit dispatch. Phase 18 builds on that system and preserves its **at-least-once** guarantee. Phase 18A–18D are complete after Windows and user-run Linux/WSL qualification. Queue composition also passed a guarded live MySQL path and one selected live Redis client path.

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **18A Queue operational depth** | High | **Complete in working tree.** `queue:status`, `--stop-when-empty`, existing bounded worker controls, restart signalling, and per-attempt Application/Auth isolation. Windows and user-run Linux/WSL qualified. An external supervisor remains responsible for worker processes. See [Queue](Queue.md). |
| **18B Queue composition** | Advanced | **Complete in working tree.** Explicit chains/batches, payload-free status, cooperative cancellation, fenced Database/Redis settlement, Sync behavior, terminal retention, and an additive Database migration. User-run disposable MySQL 8.4.11/InnoDB and the selected live Redis client path passed; the alternate optional Redis client path was skipped. See [Queue composition](QueueComposition.md). |
| **18C Queued event listeners** | High | **Complete in working tree.** Explicit class listeners and `QueueableEvent` JSON snapshots use the normal Queue and optional after-commit timing; synchronous listeners remain the default. Windows and user-run Linux/WSL qualified. See [Events](Events.md#queued-class-listeners). |
| **18D Broadcasting/realtime foundation** | Advanced | **Complete in working tree.** Disabled by default, explicit bounded messages, public/private channels, server-side authorization rules, Array/Null adapter contracts, and optional normal Queue delivery. Windows and user-run Linux/WSL qualified. No core WebSocket server or provider adapter is shipped. See [Broadcasting](Broadcasting.md). |

The Windows PHP 8.2.12 / PDO SQLite 3.39.2 full suite passed **2,540 tests, 17,924 assertions, 72 skips, 0 failures, 0 errors, and 0 risky tests**; a broad regression passed **741 tests, 5,478 assertions, 21 skips**. User-run Linux/WSL PHP 8.5.4 passed a broad **741/5,514/16 skips** regression and a normal full **2,540/18,138/23 skips** suite. MySQL composition passed **1 test/66 assertions** on disposable MySQL 8.4.11/InnoDB, followed by a full **2,540/18,655/14 skips** suite. The selected live Redis client composition path passed **30 assertions** while an alternate client dataset skipped, followed by a full **2,540/18,709/10 skips** suite. The user verified MySQL/Redis cleanup and a final clean Linux **2,540/18,138/23 skips** suite. One first post-cleanup Scheduler CLI run crossed a wall-clock occurrence boundary; the exact test, its file, five repeats, and the subsequent full suite passed. Keep Scheduler test determinism reviewable. All reported successful full suites had zero failures and errors. Codex did not run Linux or live backends. Clean committed-checkout reproduction remains a wider v2.0.0 release gate.

## Phase 19 — Observability, performance, and SqueHub Studio (complete)

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **19A Observability/OpenTelemetry** | Complete; Windows and user-run Linux/WSL qualified | Application-owned, disabled-by-default bounded spans and aggregate metrics across existing operations, with an optional exporter. No OpenTelemetry wire protocol or remote collector integration is claimed. See [Observability](Observability.md). |
| **19B Request correlation** | Complete; Windows and user-run Linux/WSL qualified | A server-generated correlation ID travels through HTTP, Queue, Scheduler, and same-origin outgoing HTTP with scoped reset and strict outbound redirect behavior. Incoming caller IDs are not trusted. See [Correlation](Correlation.md). |
| **19C Development profiler** | Complete; Windows and user-run Linux/WSL qualified | Exact development opt-in, bounded local completed-root records and timelines from 19A observations. Production profiles remain disabled. See [Profiler](Profiler.md). |
| **19D Route/config cache** | Complete; Windows and user-run Linux/WSL qualified | Private, versioned, invalidated base Config and cacheable Route snapshots; explicit build/clear commands, ordinary source fallback for missing/stale artifacts, and fail-safe corrupt-artifact handling. Closure action routes cannot be cached and still run normally. See [framework performance caches](PerformanceCaching.md). |
| **19E Optimization command** | Complete as evaluation; deliberately deferred | Config, Route, and View cache work is measurable, but an aggregate command cannot currently guarantee all-or-nothing activation. Explicit commands are the correct deployment surface for now. No `optimize` command is shipped. |
| **19F SqueHub Studio** | Complete; Windows and user-run Linux/WSL qualified | An optional, loopback-only, read-only development dashboard uses bounded existing inspection data. Its Routes and contract pages use a safe projection of the effective RouteRegistry shared with `route:list`, with normal route registration when the cache is absent or stale; it never invokes route handlers. Its Scheduler page loads registered task definitions through the same loader as `schedule:list` without running tasks. The real loopback HTTP tests cover route visibility, mount-aware pages/assets, Host rejection, source-file denial, and live policy changes. See [SqueHub Studio](Studio.md). |

The `php squehub studio` entry point is gated by development environment and explicit opt-in, binds loopback, and serves a separate fixed read-only surface. Its inspection pages do not become ordinary application routes. Later production access needs a separate safe-access design. Useful local inspection does not require Redis or Node; unavailable data must be labelled honestly. The earlier Windows PHP 8.2.12 / PDO SQLite 3.39.2 working-tree suite passed **2,628 tests, 18,600 assertions, 75 skips, zero failures, and zero errors**, before the Studio Routes-page defect was found. After the route, contract, and Scheduler inspection correction, the Windows suite passed **2,640 tests, 18,677 assertions, 75 skips, zero failures, zero errors, and zero risky tests**; a broad focused regression passed **400 tests, 2,766 assertions, and 20 skips**. The pre-correction user-run Linux full suite passed 2,628 tests, 18,816 assertions, 23 skips, zero failures, and zero errors. The user then reported that corrective Linux/WSL tests, the full corrective suite, and the live Studio smoke passed; exact post-correction totals were not supplied. Phase 19 is complete, while the wider v2.0.0 release gates remain open. This checkpoint is not a v2.0.0 release claim.

## Phase 20 — Internationalization and provider ecosystem (complete)

| Package | Priority | Intended result and acceptance boundary |
| --- | --- | --- |
| **20A Internationalization** | Complete; Windows and user-run Linux/WSL with ICU/ext-intl qualified | Explicit Application locale and fallback, Project/Package JSON catalogs with application overrides, parameters, scoped HTTP/worker locale, escaped `@translate`, and opt-in ICU plural/number/currency/date formatting. Ordinary key lookup works without `ext-intl`; ICU-dependent operations require it. Focused native Linux run passed **12 tests/105 assertions**. See [Internationalization](Internationalization.md). |
| **20B Provider-backed Storage** | Complete; live S3-compatible MinIO qualified | A selected S3-compatible drive uses an optional AWS SDK bridge behind existing Storage contracts. Guarded live MinIO passed **1 test/27 assertions**; the owned prefix was emptied and the disposable bucket, data, and process were removed. AWS S3 itself was not tested. Local Storage remains the default. See [provider-backed Storage](ProviderStorage.md). |
| **20C Provider-backed Mail** | Complete; Resend and Postmark provider adapters live-qualified | Named HTTPS transports reuse Mailer validation, diagnostics, and existing synchronous/queued paths. Each actual SqueHub provider adapter passed a guarded live **1 test/1 assertion** run. Postmark API interoperability passed to a same-domain recipient; cross-domain sending was restricted by provider-account approval status, and no provider SLA is claimed. SMTP/Array behavior remains. See [provider-backed Mail](ProviderMail.md). |
| **20D Other infrastructure adapters** | Complete; live Memcached qualified; Queue brokers deliberately deferred | A selected Memcached Cache driver uses the optional PHP extension and bounded Cache semantics. Guarded Linux Memcached 1.6.40/ext-memcached 3.4.0 passed **7 tests/14 assertions/1 intentional skip** with an isolated namespace and no `flush_all`. Additional Queue brokers were evaluated and deferred; none is shipped in this phase. See [Cache](Cache.md). |

The pre-change Windows PHP 8.2.12 / PDO SQLite 3.39.2 full suite passed **2,640 tests, 18,677 assertions, 75 skips, zero failures, zero errors, and zero risky tests**. The final Phase 20 Windows suite passed **2,691 tests, 19,059 assertions, 79 skips, zero failures, zero errors, and zero risky tests**. User-run native Linux/WSL PHP 8.5.4 with SQLite 3.46.1 and ext-intl passed a broad regression (**1,210 tests/8,581 assertions/13 skips**) and, after optional-service cleanup, the final normal full suite (**2,691 tests/19,277 assertions/28 skips, zero failures, zero errors**). Live MinIO, Memcached, Resend, and Postmark results above complete Phase 20 within their stated boundaries. This does not close clean committed-checkout reproduction or the wider v2.0.0 release gate.

## Phase 21 — Portability, project bundles, and deployment proof (complete)

The current `backup:dev` archives `Project/`, `Config/`, `Database/`, optional `.env`, and legacy `config.php`; it omits `Assets/` and live database records. It is **not** the portable source bundle or full recovery feature. Phase 21A–21E are complete in the development tree. The final Windows suite passed **2,760 tests, 19,557 assertions, 82 skips, zero failures, and zero errors**; the user-run native Linux/WSL suite passed **2,760 tests, 19,784 assertions, 28 skips, zero failures, and zero errors**. Cross-platform bundle checks passed within the [release-readiness evidence](ReleaseReadiness.md); clean committed-checkout reproduction and release approval remain open. See [Backup and portability](BackupAndPortability.md).

| Package | Status | Result and acceptance boundary |
| --- | --- | --- |
| **21A Portable project bundle** | Complete; Windows and user-run Linux qualified | [Project bundles](ProjectBundles.md) export, fully inspect, and review/import bounded application source. Versioned manifest, checksums, exact casing, containment, unsafe-link rejection, and secret-path exclusions; no live records or uploads. |
| **21B Upgrade preflight** | Complete; Windows and user-run Linux qualified | [Upgrade preflight](UpgradePreflight.md) compares a current root with an explicit local target using static Composer, Package/Kit, activation, configuration, and source-file evidence. Unsupported compatibility or unknown migration effects remain unproved; no rewriting or migrations. |
| **21C Recovery boundaries** | Complete; Windows and user-run Linux qualified | [Recovery planning](Recovery.md) distinguishes source, an operator-provided database artifact, persistent files, secrets, sessions, and Queue state. It fingerprints but does not take or restore a database snapshot. Seeders are not user data. |
| **21D Deployment proof profiles** | Complete; Windows and user-run Linux qualified | [Deployment profiles](DeploymentProof.md) extend Doctor for shared hosting, single server, worker-backed, and multi-server configuration. Evidence separates **configured**, **reachable**, and **end-to-end verified**; optional probes are bounded and no deployment occurs. |
| **21E Unified change-plan engine** | Complete; Windows and user-run Linux qualified | The shared [Change Plan](ReviewableChanges.md) now supports bounded source, security, compatibility, and verification-expectation metadata. Bundle import, upgrade preflight, `config:cache --preview`, and read-only `migrate:plan` use it; legacy generator, Package, and Kit plan JSON and fingerprints remain stable. Each subsystem still owns apply, stale checks, and recovery. |

A change plan should show creates, modifications, deletions, and potentially destructive database work before anything applies. Risk must be explicit; a preview alone cannot make arbitrary package code safe. The plan engine coordinates Phase 13D reviewable changes and Phase 25E AI proposals, while each subsystem still owns execution and rollback semantics. Migration execution remains separately authorized.


## Phase 22 — Frontend platform and application profiles (complete; Windows and native Linux qualified)

**Phase 22A–22L is complete: implemented, tested, and passed on Windows and a user-run native, case-sensitive Linux filesystem.** The final Windows PHP 8.2.12 / SQLite 3.39.2 suite passed **2,813 tests, 20,019 assertions, 84 skips, zero failures, zero errors, and zero risky tests**; the broad Windows regression passed **1,695 tests, 11,946 assertions, 46 skips**. Fifty new or touched Phase 22 PHP files passed lint; targeted PHPStan level 5, `composer validate --strict`, optimized autoload, CLI smokes, and `git diff --check` passed. Windows used Node 24.21.0, npm 11.19.0, and Vite 7.3.6 for real disposable Vite, React, and Vue builds; their npm audits reported zero vulnerabilities at qualification time. Hashed production assets had no HMR references, and a supervised loopback proxy passed.

The native Linux run used PHP 8.5.4, Composer 2.9.5, `/usr/bin/node` 22.22.1, `/usr/bin/npm` 9.2.0, and confirmed `process.platform=linux`. All **17 focused files passed: 103 tests, 693 assertions, zero failures, zero errors**. Separate real Vite 7.3.6, React 19.3.0/react-dom 19.3.0, and Vue 3.5.43 builds passed; the selected React and Vue plugins were 5.2.0 and 6.0.9. Each `npm audit` reported zero vulnerabilities, each production manifest was available, and production output contained no Vite client or development-origin reference. A supervised PHP server at `127.0.0.1:18080` and Vite at `127.0.0.1:15173` emitted the HMR client and proxied `/api/phase22-probe` to a SqueHub JSON response. The final Linux suite passed **2,813 tests, 20,249 assertions, 27 skips, zero failures, and zero errors**. The user removed the disposable copies, build files, environment values, and owned processes afterward. This is cross-platform qualification for the tested Windows and native Linux paths, not proof of macOS, every Linux or Node version, live Apache/cPanel hosting, production multi-node operation, a clean committed checkout, or release approval.

React, Vue, and modern browser development are first-class **optional** SqueHub workflows, beyond theoretical HTTP compatibility. Core supplies asset, manifest, SPA, and backend/frontend integration contracts; React, Vue, Vite, Node.js, and npm are installed only for a selected profile. Plain SqueHub MVC, vanilla JavaScript, and native ES modules remain usable without a bundler. Phase 15E defines the base-path behavior that frontend paths respect.

| Package | Verified status | Implemented contract |
| --- | --- | --- |
| **22A Asset mapper** | Implemented, tested, passed | Application-owned `asset()` and View stacks share mount-aware URLs. Logical Package references require active, contained assets; Kit-published files retain ordinary public URLs. Selected Vite manifest entries use build filenames without requiring Node at PHP boot. |
| **22B Native ES modules/import maps** | Implemented, tested, passed | `@frontend` declares native `.js`/`.mjs` modules and explicit, safely encoded import maps through existing View stacks. Individual Package module aliases are activation checked; Package directory-prefix aliases are rejected. |
| **22C Frontend build adapter** | Implemented, tested, passed | A small build-adapter contract selects the implemented Vite adapter; other bundlers are not shipped. Core PHP boot has no bundler runtime dependency. |
| **22D First-class Vite** | Implemented, tested, real build passed | Selected profiles use a loopback development origin and HMR client only in explicit development/local mode; `frontend:build` validates the production manifest and referenced files. Non-Vite applications do not probe Vite. |
| **22E First-class React** | Implemented, tested, real build passed | A reviewable React/Vite profile creates inspectable `Project/Frontend` source, backend View and route, API/CSRF wiring, and the same production manifest path. React is not a core PHP dependency. |
| **22F First-class Vue** | Implemented, tested, real build passed | A reviewable Vue/Vite profile uses the same backend-owned shell, API/CSRF integration, build output, and optional SPA setting. Vue is not a core PHP dependency. |
| **22G SPA routing/fallback** | Implemented, tested, passed | An explicit frontend SPA policy applies only to eligible unmatched browser navigation after ordinary routes, with configured prefixes/exclusions, API and asset boundaries, and Phase 15E mount handling. Disabled mode keeps ordinary 404 behavior. |
| **22H Dev proxy/HMR** | Implemented, tested, real smoke passed | Explicit `dev --frontend` coordinates PHP and a selected local Vite process, chooses a free loopback port or validated requested port, and passes backend origin/base-path settings to Vite. Ordinary `dev` remains Node-free. |
| **22I Frontend CSRF/Session Auth** | Implemented, tested, passed | The backend-rendered shell can supply request-bound CSRF context; same-origin Session cookie and login/logout patterns are exercised by frontend integration tests. Phase 12D token Auth remains an explicit alternative. |
| **22J Production asset manifest** | Implemented, tested, passed | A bounded Vite manifest reader validates paths, physical files, imports, CSS/assets, and changed or missing outputs; selected production entries fail closed and never emit development/HMR URLs. |
| **22K Starter profiles** | Implemented, tested, passed | The implemented reviewed choices are `vite`, `react`, and `vue`. `profile:inspect`, `profile:apply`, and `profile:remove` use the shared Change Plan; `frontend:build` and `frontend:status` operate on a selected profile. Other profile names remain future evaluations rather than shipped choices. |
| **22L Frontend verification gate** | Implemented; Windows and native Linux passed; cross-platform qualified | Both platform suites, all 17 Linux focused files, real Vite/React/Vue builds, and supervised HMR/proxy smoke passed. Deployment-specific live Apache/cPanel proof remains a separate release gate. Node remains optional for ordinary SqueHub applications. |

The selected frontend profile should let a developer understand every created file. A SqueHub API with a separate frontend or mobile client should use the same Phase 12 contracts; there is no second backend architecture.

## Phase 23 — Typed application data (COMPLETE; Windows and native Linux qualified)

Typed data helps larger applications state their input and payload boundaries without making simple array-based code obsolete. Existing `$request->validate(...)`, ordinary arrays, services, Models, Views, API Resources, Queue jobs, Events, and Notifications remain valid. A plain PHP or readonly constructor can own the value; no mandatory DTO base class exists. Phase 23's selected typed persistence reuses the existing Queue JSON and worker rather than introducing another serializer. See [Typed application data](ApplicationData.md).

| Package | Current implementation and boundary |
| --- | --- |
| **23A Data objects** | **Implemented, tested, passed, complete.** `App\Plugins\DataMapper::map()` constructs trusted, caller-selected plain or readonly classes through public constructors with strict scalar/enum conversions. `#[NestedData]` explicitly opts in nested objects; Models and arbitrary object graphs are excluded. |
| **23B Validated request mapping** | **Implemented, tested, passed, complete.** `Request::validatedAs($class, ?array $rules = null, array $messages = [])` runs the existing Validator before mapping declared constructor fields. Explicit rules work with a plain class; omitted rules require `ValidatedData::rules()`. Existing `validate()` still returns arrays. |
| **23C Forms and data objects** | **Implemented, tested, passed, complete.** Typed forms use the same global CSRF, safe ValidationException response, 303 redirect, ErrorBag, filtered old input, and View helpers as array-based forms. |
| **23D API resource integration** | **Implemented, tested, passed, complete.** A typed request value can reach application logic or an explicit `ApiResource` projection. The Resource selects output fields; constructors do not infer public JSON or Application Contract/OpenAPI schemas. |
| **23E Safe Queue/Event payloads** | **Implemented, tested, passed, complete.** `QueuePayloadData` opts in selected values; the Application-owned `TypedPayloadRegistry` registers stable alias/version/class mappings and produces exact `{type,version,data}` arrays. Existing QueueJob, queued Event, and queued Notification payloads may deliberately carry these envelopes. QueueCodec's 60,000-byte outer bound, worker, retries, and at-least-once behavior remain authoritative; no native PHP object serialization is used. |

**Phase 23A–23E is complete, implemented, tested, and cross-platform qualified on the supported Windows and native Linux qualification paths.** The pre-Phase-23 Windows baseline passed **2,813 tests, 20,019 assertions, 84 skips, zero failures, and zero errors**. The final Windows PHP 8.2.12 / SQLite 3.39.2 suite passed **2,846 tests, 20,308 assertions, 84 skips, zero failures, and zero errors**; six focused Phase 23 files passed **33 tests, 286 assertions, zero failures, and zero errors**. The user-reported native Linux run used a confirmed case-sensitive filesystem and disposable SQLite :memory: configuration. Seven focused files, including QueueFoundationTest, passed **45 tests, 361 assertions, zero failures, and zero errors**; the final Linux suite passed **2,846 tests, 20,538 assertions, 27 skips, zero failures, and zero errors**. The Phase 23 qualification directory was removed and no squehub-phase23.* qualification directories remained. Codex did not run Linux. This qualification does not establish macOS, every Linux distribution or PHP minor, production multi-node Queue operation, live MySQL/Redis behavior for Phase 23, a clean committed checkout, or v2.0.0 release readiness. See [release readiness](ReleaseReadiness.md) for focused and static evidence.

## Phase 24 — Reliability, locks, and idempotency (COMPLETE; Windows, native Linux, live Redis, and live MySQL qualified)

**Phase 24A–24E is complete: implemented, tested, passed, and qualified within the reported Windows, user-run native Linux, and guarded live-backend profiles.** The final Windows PHP 8.2.12 / PDO SQLite 3.39.2 suite passed **2,924 tests, 21,890 assertions, 89 skips, zero failures, and zero errors**. Windows focused results were 24A **37/704/3 skips**, 24B **17/719/2 guarded skips**, 24C **25/262**, 24D **11/70**, and the 24E process-race/prior-pillar subset **38/844/1 skip** (tests/assertions). These checks exercised separate-process file and SQLite contention, crash/expiry recovery, HTTP idempotency races, a real local HTTP retry, and circuit half-open recovery.

The user-run native Linux focused/regression set passed **96 tests, 1,817 assertions, zero failures, and zero errors** on a confirmed case-sensitive filesystem, using a native SQLite file for process coordination. The final native Linux suite passed **2,924 tests, 22,122 assertions, 31 skips, zero failures, and zero errors**. Redis Server 8.0.5 with PhpRedis passed guarded live LockRedisLiveTest **1/9** and IdempotencyRedisLiveTest **1/13**. MySQL 8.4.11 with PDO MySQL passed guarded live LockMySqlOptInTest **1/25** and IdempotencyMySqlOptInTest **1/28**. The disposable Redis instance had persistence disabled, was stopped, and its temporary directory removed; its later post-stop scan was not independent key-by-key cleanup proof. The disposable MySQL database and restricted user were dropped, absence was verified, and test variables were unset. Codex did not run Linux or these live backends. See [release readiness](ReleaseReadiness.md) for the qualification boundary.

Existing Queue work remains **at least once**. Lock ownership prevents one owner releasing another's lease, but lease expiry does not fence an external resource. Scoped idempotency stores bounded safe replays; retries and timeouts cannot turn arbitrary remote side effects into exactly-once processing. Local file coordination remains single-server. Qualification of the tested backends does not establish every production deployment, a clean committed checkout, or v2.0.0 release readiness.

| Package | Implemented result and qualification boundary |
| --- | --- |
| **24A Unified lock API** | **Implemented, tested, passed, complete.** `lock()` and `App\Plugins\Lock` return bounded leases with owner-checked release and optional finite waits. Array, private local file, SQLite/MySQL database, and Redis stores are implemented. Windows/native Linux file and SQLite process tests and guarded live Redis/MySQL tests passed. There are no external fencing tokens. See [Locks](Locks.md). |
| **24B HTTP/API idempotency** | **Implemented, tested, passed, complete.** `App\Plugins\IdempotentRequests::authenticated()` is explicit final route middleware. It scopes a bounded client key to the authenticated identity and route, hashes request material, atomically claims file/SQLite/database/Redis state, replays only safe bounded 2xx fields, and returns 409 for conflict, in-progress, or unreplayable results. Windows/native Linux races and guarded live Redis/MySQL tests passed. See [HTTP idempotency](Idempotency.md). |
| **24C Shared retry policy** | **Implemented, tested, passed, complete.** Immutable `App\Plugins\RetryPolicy` bounds attempts, exponential delay, jitter, elapsed time, and `Retry-After`. `Http::withRetryPolicy()` defaults to GET/HEAD retries; unsafe replay needs deliberate consent. Webhooks use the timing policy while retaining signed-body and transient-status rules. Queue retains its existing persisted attempt/worker backoff contract and is **not** executed through `RetryPolicy`. Windows and user-run native Linux regressions passed. See [Retries](Retries.md). |
| **24D Circuit breaker** | **Implemented, tested, passed, complete.** Opt-in `App\Plugins\CircuitBreaker` uses a strict private local file store with closed/open/half-open state, explicit failure classification, one tokenized probe, bounded cooldown and crash recovery. It is single-server only and requires a stable policy per name. Windows and user-run native Linux process recovery checks passed. See [Circuit breaker](CircuitBreaker.md). |
| **24E Reliability verification gate** | **Complete.** Windows and user-run native Linux focused/process/full suites passed; the two guarded Redis and two guarded MySQL tests passed live against disposable backends. Local files do not establish distributed behavior or exactly-once external effects. Clean-checkout and final release gates remain open. See [release readiness](ReleaseReadiness.md). |

## Phase 25 — Agent and AI integration (25A–25F complete; Windows and native Linux qualified)

The SqueHub-specific direction is **framework-aware, version-aware, permission-aware, and plan-first**. AI remains optional. Phase 25 uses the official experimental PHP MCP SDK **0.8.1** as an optional dependency. SqueHub's local STDIO integration advertises **2025-11-25**; `mcp/sdk` 0.8.1 itself also implements the **2026-07-28** modern/stateless era. No remote HTTP endpoint, built-in LLM, or Node dependency is required. The [Agent and AI guide](AgentAndAI.md) gives the exact supported surface and security limits. Reading and proposing come before writing; an Agent cannot receive blanket host access or treat a generated plan as approval.

| Package | Current implementation and qualification boundary |
| --- | --- |
| **25A SqueHub MCP server — implemented, tested, passed, complete** | Optional `agent:mcp` STDIO adapter uses official `mcp/sdk` **0.8.1** and registers only bounded, capability-filtered resources/tools. It reports actual served revision **2025-11-25**, reserves stdout for protocol, and installs no remote listener. The SDK is not an ordinary application runtime dependency. |
| **25B Read-only agent mode — implemented, tested, passed, complete** | Six bounded metadata capabilities are granted by default. Route/contract inspection reads existing registration or validated route-cache data and can report a partial inventory; it never executes Project route PHP merely to fill one. No source writes, migrations, Package lifecycle actions, shell, network tool, or `.env` resource are exposed. |
| **25C Capability permissions — implemented, tested, passed, complete** | One Application-scoped registry validates explicit grants. `read_schema` requires exact connection names; `create_plan` requires exact operation names. `read_source`, `read_logs`, `run_tests`, `apply_plan`, `run_migration`, and `manage_package` remain defined but unsupported remotely; configuration cannot enable them. Unknown or unscoped grants fail. |
| **25D Version-aware context — implemented, tested, passed, complete** | Reports this development framework as `2.0.0-dev`, current path-first routing style, bounded Plugins symbols, registered CLI commands when launched through `agent:mcp`, existing route/contract metadata, Package/Kit activation, frontend/deployment status, and safe Health summaries. Absent source data is partial/unavailable rather than invented. |
| **25E AI change plans — implemented, tested, passed, complete** | A scoped `create_plan` tool reuses existing Change Plans for `migration_source`, `package_enable`, `kit_enable`, and `feature_blueprint`. It returns fingerprint/provenance and `applied: false`; the owning subsystem retains freshness, approval, and apply. No generic Agent apply tool exists. |
| **25F Agent verification — Windows passed, native Linux passed, official SDK client interoperability passed, complete** | The final Windows suite passed **2,946 tests/22,117 assertions/90 skips**; the focused Agent filter passed **22/204/1 skip**. The user-run native Linux focused Agent files passed **22 tests/219 assertions/0 skips**; the full Linux suite passed **2,946 tests/22,364 assertions/31 skips**. Both full suites had zero failures and errors. The official SDK client STDIO case executed and passed on Linux. The disposable Linux qualification directory was removed and no `squehub-phase25.*` directory remained. Phase 25 is cross-platform qualified within these Windows/native Linux profiles; wider v2 release gates remain open. |

## Phase 26 — Stabilization (26A–26E complete; Windows and native Linux qualified)

Phase 26A–26E are **complete** in the development working tree and qualified in the recorded Windows and native Linux profiles. The pre-change Windows PHP 8.2.12 / PDO SQLite 3.39.2 baseline passed **2,946 tests, 22,117 assertions, 90 skips, zero failures, zero errors, and zero risky tests**. The final Windows suite passed **2,955 tests, 22,755 assertions, 90 skips, zero failures, zero errors, and zero risky tests**. The user-run native Linux PHP 8.5.4 / SQLite 3.46.1 focused stabilization passed **45 tests and 1,292 assertions**, with zero failures or errors on a confirmed case-sensitive filesystem. The live MySQL-enabled Linux full suite passed **2,955/23,572/20 skips**; after database/user cleanup and opt-in variable removal it passed **2,955/23,004/31 skips**, both with zero failures and errors. Codex did not run the Linux qualification. See the [stabilization evidence](Stabilization.md) and [release readiness](ReleaseReadiness.md).

| Package | Status | Evidence and remaining boundary |
| --- | --- | --- |
| **26A Public API audit** | **Complete** | [Inventory](PublicApiAudit.md) classifies 142 Plugins symbols, 53 helpers, 72 CLI commands, 39 Config files, routing, HTTP/API, ORM, and Package/Kit contracts. Six unreleased Plugins Route static verb shortcuts were removed; `lock()` and eight Plugins symbols were added to their incomplete reference tables. The v1 `$router` bridge remains. Windows and user-run native Linux full-suite qualifications passed. |
| **26B Security audit** | **Complete** | A legacy Mail template source/data collision and cross-origin HTTP redirect credential/body forwarding were fixed and tested. The first-party Windows security matrix passed **1,122 tests, 9,317 assertions, 42 skips**, with zero failures and errors; native Linux regression passed. This is not penetration testing or legal certification. |
| **26C Compatibility audit** | **Complete** | Windows PHP 8.2.12 / SQLite 3.39.2 and native Linux PHP 8.5.4 / SQLite 3.46.1 passed. User-run Redis 8.0.5 / PhpRedis passed **8 tests/68 assertions/3 alternate-client skips**; MySQL 8.4.11 / PDO MySQL / InnoDB passed **8 tests/448 assertions** for Schema, queries, tokens, RBAC, MFA, Queue, Locks, and Idempotency. Both Windows and Linux disposable no-dev paths passed. Isolated Apache, real cPanel, macOS, live Predis, and Redis auth/TLS remain separate profiles. |
| **26D Performance/long-running audit** | **Complete** | Repeated Applications/requests, Queue worker, Scheduler, Agent reads, and persistent MCP STDIO were measured and checked for state isolation on Windows. The bounded sample showed no retained Application/request state; see [measurements](Stabilization.md#long-running-lifecycle-measurements). User-run native Linux focused stabilization passed **45 tests/1,292 assertions**. These samples do not establish a universal production memory bound. |
| **26E Dependency/supply-chain audit** | **Complete** | Unused Flysystem and orphan mime-type-detection were removed; `php-http/discovery` plugin execution was disabled. The Windows locked audit reports zero advisories and zero abandoned packages. Strict validation, install, optimized autoload, and license inventory passed. The user-run disposable Linux `composer install --no-dev --no-interaction`, validation, and zero-advisory audit passed without `mcp/sdk`. |

## Phase 27 — Public documentation and ecosystem (local implementation complete)

Phase 27A–27D are **complete for the local Windows/XAMPP documentation implementation**. The current v2 site has a responsive portal and separate `/docs/v1.x` and `/docs/v2.x` catalogs; `/docs` selects current v2. The local catalog has **18 historical v1 articles and 121 public v2 guides plus a curated v2 home** (140 catalog pages). `VerifyPortal.py` reported **1,312 checked internal links and zero issues**, and the local Apache run checked **141 documentation HTTP pages**. Representative desktop/mobile, version, search, theme, and 404 behavior was inspected. A current direct Windows PHPUnit run with a temporary OpenSSL-clean `PHPRC` passed **2,955 tests, 22,781 assertions, 87 skips, zero failures, and zero errors**; the ordinary `composer test` attempt hit its 300-second timeout in the warning-affected local configuration. This does not claim production publication or a released Composer v2 package. See the [internal Phase 27 report](Phase27PublicDocs.md) for all route mappings, source coverage, design, verification limits, and the build/stage/deploy workflow.

The four sources retain distinct roles: `Docs/V2.x/` in this core repository is the current technical authority; `on_doc/squehub` supplies historical v1 content only; `on_doc/html-doc` supplies the reviewed visual prototype; and `D:/xampp/htdocs` is the current site's local implementation target. The old v1 application's architecture is not the new docs runtime. The production `/docs/v1.x` history and planned `/docs/v2.x` publication remain a Phase 28 release check.

| Package | Local completion evidence and boundary |
| --- | --- |
| **27A Public v2 portal** | Responsive landing and article layout, grouped sidebar, heading TOC, version selector, current-version search, code copy, theme, previous/next navigation, and docs 404 are implemented in the local v2 site. Browser inspection was representative, not formal accessibility certification. |
| **27B Versioned docs** | The build accounts for all 18 historical v1 templates without converting their APIs to v2 and catalogs 121 public v2 guides. The v2 home is curated; internal status pages are excluded from public output and search. `/docs` redirects to local current v2. Production host redirects still need publication review. |
| **27C Verified examples** | The current CLI registry returned 72 commands. A public-guide scan found 73 distinct CLI tokens; all map to registered commands except the working `h` help alias and explicitly unavailable `optimize` references. Forty-six selected configuration names matched `.example.env`/`Config` or documented test-only controls. Twenty representative doc/source/test signature checks and the portal link/HTTP checks passed. These are bounded documentation checks, not fresh execution of every example or every optional backend. |
| **27D v1 → v2 upgrade guide** | [Upgrade from v1](UpgradeFromV1.md) is included at `/docs/v2.x/upgrade-from-v1`; it covers route, layout, View, data, Auth, CLI, Package/Scheduler, deployment, and backup changes with manual-review limits. |

The [installation guide](Installation.md) explicitly uses `composer install` for a complete source snapshot, copies `.example.env` to `.env`, runs `php squehub key:generate`, pastes its printed value into `APP_KEY`, reviews application/database settings, runs `php squehub doctor`, and then starts the server or configures `public/` as the web document root. A published `composer create-project` flow still needs proof against a real v2 artifact in Phase 28.


## Phase 28 — Final v2.0.0 release candidate (planned)

Passing a local suite alone does not authorize a release.

| Package | Priority | Required release proof |
| --- | --- | --- |
| **28A Reproducible artifact** | Required | Build and verify from an intended clean source artifact, not the active uncommitted tree. Resolve the 11G.1 Git/ignore-policy blocker. |
| **28B Composer installation** | Required | Against that actual release candidate, run `composer create-project squehub/squehub my-app` and verify `.env`, `APP_KEY`, Doctor, route, view, database, CLI, and web request. |
| **28C Public documentation** | Required | Publish accurate `/docs/v2.x` and retain `/docs/v1.x`. |
| **28D Release decision** | Required | Close all required gates, record selected platform/backend support, and obtain explicit authorized approval before tagging or publishing. |

## Inspect → Plan → Prove

This is a **planned product direction**, not a current command family. SqueHub should answer three questions with minimal setup: **What does this application do? What will this change affect? Did the result work on this host?** The intended path is to understand what exists, preview what will change, apply deliberately, then verify the outcome.

| Step | Owning work packages |
| --- | --- |
| **Inspect** | 13C side-effect-free explanation; 12G explicit API/application contract; 19F Studio; 25A read-only MCP context. |
| **Plan and apply deliberately** | 13D reviewable generators/packages; 13I Feature Blueprints; 21B upgrade preflight; 21E shared change-plan engine; 25E reviewed AI proposals; 22K inspectable starter profiles. |
| **Prove** | 12H selected API contract cases; 21D evidence-based deployment profiles; Phase 19 observability; 22L frontend verification; Phase 26 stabilization; Phase 28 installation and release checks. |

No step should upload secrets, execute application side effects during static inspection, silently apply risky changes, or claim to predict arbitrary PHP branches. An AI proposal uses the same change-plan and permission boundaries as a human-initiated operation.

The result should still feel small: a developer can write a route, controller, or view without learning the inspection system. Request cases (13G) are deferred until strict route-metadata privacy, fail-closed outbound replay behavior, and privacy/isolation evidence are established. Evaluate each addition against real SqueHub applications rather than copying another framework's feature list.

## Release boundary and remaining gaps

The historical Phase 11G runs close the *specific* Linux, case-sensitive, guarded MySQL, and PhpRedis questions listed in that record. Phases 12A–12D have later Windows and user-reported manual WSL evidence above. Phase 12E has Windows working-tree and local simulated-provider verification, including an opt-in real loopback HTTP transport check; live provider interoperability remains unqualified. Phase 12F has Windows and local loopback verification, but no new WSL/Linux, live MySQL, or external peer qualification at its original checkpoint. Phase 12G has Windows and later user-reported manual WSL evidence. Phase 12H was first qualified in the Windows working tree; a later user-run Phase 26 Linux full suite supplies broader regression coverage, while dedicated external deployment profiles remain separate. Phase 12I has Windows PHP real-HTTP and JavaScript Fetch evidence; the user later reported successful Linux/WSL execution of generated clients and TypeScript compilation, without exact later totals or tool versions. The Phase 11G MySQL result predates the Phase 12D token repository; later user-run Phase 26 MySQL 8.4.11 / PDO MySQL / InnoDB qualification included API tokens among **8 representative tests and 448 assertions**, and its Linux full suite with live MySQL opt-in passed **2,955 tests, 23,572 assertions, 20 skips** without failures or errors. **Distribution integrity remains open.** Required v2 source and `composer.lock` are absent from `HEAD`, while `Docs/` and `Tests/` remain ignored; a clean committed checkout and published Composer v2 installation are unproved. Optional Predis live execution, Redis auth/TLS, successful SMTP TLS and broader delivery beyond the guarded Resend/Postmark adapter paths, controlled HTTPS, macOS, Linux Apache, and real cPanel/shared-host URL-subdirectory hosting remain separate qualification or scope decisions. The Phase 11G XAMPP smoke proved document-root hosting only; Phase 15E adds a bounded isolated Windows Apache `/app/` smoke, and the user later reported successful real XAMPP corrective verification. Linux Apache and cPanel deployment remain separate.

The roadmap assigns completed and remaining work to phases: webhook vendor adapters → later integration work; generators/setup/blueprints/packages → **13**; view context/parser/components/cache → **14**; route parameter depth, response types, proxies, and base path → **15**; Database/ORM depth and user-reported Linux/MySQL checks → **16**; identity/security depth qualified on Windows, Linux/WSL, and applicable live MySQL paths → **17**; Queue composition/realtime qualified on Windows, Linux/WSL, disposable MySQL, and a selected live Redis client path → **18**; tracing/caches/Studio → **19**; i18n/provider adapters → **20**; bundles/change plans/deployment proof → **21**; frontend platform/application profiles → **22**; optional typed data → **23**; reliability, locks, and idempotency completed within recorded Windows/Linux/live Redis/live MySQL profiles → **24**; permission-bound agent integration → **25**; audits/stabilization → **26**; local public documentation/ecosystem implementation → **27**; reproducible artifact, production publication, and release proof → **28**. Consult each phase's status rather than treating every assignment as already implemented. The [feature inventory](FeatureStatus.md) remains the current API source, and [release readiness](ReleaseReadiness.md) holds verification evidence.

All **28 phases** in this roadmap belong to the **SqueHub v2.0.0 development plan**. Completing one work package does not publish a release. Phase 27's local documentation implementation is complete within its recorded checks; public publication is still required in Phase 28. SqueHub v2.0.0 can be tagged only after required implementation, release-gate remediation, a reproducible artifact, Phase 28 installation and publication proof, and explicit release approval are complete.
