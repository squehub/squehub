# SqueHub v2.x documentation

This is the canonical v2.0.0 documentation source used to build the site's `/docs/v2.x` section. The versioned portal also preserves historical `/docs/v1.x` content. Review both versions against the selected release artifact and verify the public routes during publication.

**Documentation target:** SqueHub v2.0.0 source. These pages describe v2 APIs and project conventions. The [v1.x documentation](https://squehub.com/docs/v1.x) describes the earlier generation and remains a separate archive.

**Phase 27A–27D is complete.** The 3 October 2026 source rebuild includes 18 historical v1 articles, 124 public v2 guides (including the [public status guide](Status.md) and two Agent/MCP guides), and a curated v2 home: **143 catalog pages**. The local verifier checked **1,451 internal links** and **144 documentation HTTP pages**, all with zero issues. The original Phase 27 baseline was 140 catalog pages and 1,312 links.

The subsequent Phase 28 root-domain deployment at [squehub.com](https://squehub.com/) passed **144 live documentation HTTP pages** and **35 ecosystem HTTP checks**, with zero issues in either verifier. This confirms the tested public documentation deployment, not parity with an eventual final release commit or public v2 Composer installation. The [internal Phase 27 report](Phase27PublicDocs.md) and [release readiness record](ReleaseReadiness.md) separate those gates.

Start with [Installation](Installation.md), build the [first application](FirstApplication.md), then use the topic guides below. Application examples prefer `App\Plugins` imports. Global helpers and legacy APIs are called out where they remain supported.

## Getting started

| Guide | What it covers |
| --- | --- |
| [Installation](Installation.md) | PHP requirements, source checkout, `.env`, `APP_KEY`, Doctor, local server |
| [SqueHub Setup](Setup.md) | Optional guided configuration, reviewable plan, manual alternative, and safety boundaries |
| [SqueHub Dev](Dev.md) | Foreground development session, PHP server, Doctor preflight, and optional persistent Queue worker |
| [First application](FirstApplication.md) | Route, controller, view, form, validation, and database example |
| [Directory structure](DirectoryStructure.md) | Where application code, packages, migrations, templates, and runtime files live |
| [Configuration](Configuration.md) | Environment keys, configuration loading, debug policy, and service drivers |
| [Feature status](FeatureStatus.md) | Implemented features, supported backends, and current limits |
| [Public v2 status](Status.md) | Concise release boundary, qualified environments, and Package/Kit availability for site readers |
| [Phase 26 stabilization](Stabilization.md#phase-26-stabilization--complete) | Completed public API, security, compatibility, lifecycle, and dependency audit; Windows, native Linux, guarded MySQL/Redis, and no-dev evidence |
| [Phase 27 public docs report](Phase27PublicDocs.md) | Internal v1 route mapping, every public v2 source route, portal design, verification scope, and build/deploy workflow |
| [Public API audit](PublicApiAudit.md) | Phase 26A classification of Plugins, helpers, routing, CLI, Config, HTTP/API, ORM, and Package/Kit contracts |
| [Release readiness](ReleaseReadiness.md#phase-26-stabilization--complete) | Exact Phase 26 verification results and remaining v2 release gates |
| [Global helpers](Helpers.md) | Complete v2 helper reference, Application context, public asset URLs, and v1 compatibility |
| [Code generators](Generators.md) | Controller, Model, Middleware, Migration, and Seeder creation, previews, and Package target limits |
| [SqueHub Feature Blueprint](FeatureBlueprints.md) | Reviewed Model, Migration, Controller, Validation, Resource, bounded GET route, and test scaffolding |
| [Reviewable changes](ReviewableChanges.md) | Shared source-change plans, risks, conflicts, stale protection, and deliberate apply |
| [Agent and AI integration](AgentAndAI.md) | Optional local MCP STDIO, default read-only context, scoped capabilities, and review-only plans |
| [Local MCP setup](AgentMcpSetup.md) | Client-neutral STDIO process setup, documented client examples, and connection troubleshooting |
| [MCP tools and resources](AgentMcpTools.md) | Exact capability grants, resources, tool arguments, result shapes, and Change Plan boundary |
| [Project bundles](ProjectBundles.md) | Portable application source export, full archive inspection, and reviewed import |
| [Upgrade preflight](UpgradePreflight.md) | Static compatibility and collision assessment against a local target source |
| [Recovery boundaries](Recovery.md) | Distinct source, database, uploads, secrets, sessions, and Queue recovery material |
| [Deployment proof profiles](DeploymentProof.md) | Configured, reachable, and bounded end-to-end evidence for selected deployments |
| [Testing](Testing.md) | PHPUnit, disposable applications, real-Kernel requests, direct View/Fragment assertions, isolation, and qualification boundaries |
| [Large applications](LargeApplications.md) | Keep small apps in `Project/`; introduce Package capability boundaries, dependency graphs, optional owners, and multi-Package tests when needed |
| [SqueHub Kits](Kits.md) | Compose Packages and publish application files through a separately managed Kit lifecycle |
| [SqueHub Activation Registry](ActivationRegistry.md) | Unified Package/Kit activation, dependencies, boot selection, and legacy state compatibility |
| [Roadmap](Roadmap.md) | Completed development foundations, v2.0.0 release gates, and proposed v2.0.0 phases |
| [Upgrade from v1.x](UpgradeFromV1.md) | Public API changes and migration steps |
| [v1.x documentation comparison](V1DocumentationComparison.md) | What the published v1.x pages cover and what v2 changes or adds |

## Application and HTTP

[Application lifecycle](Application.md) · [Container](Container.md) · [Plugins API](Plugins.md) · [Global helpers](Helpers.md) · [Routing](Routing.md) · [Controllers](Controllers.md) · [Middleware](Middleware.md) · [HTTP requests and responses](Http.md) · [HTTP response capabilities](Responses.md) · [Errors](Errors.md) · [API development](ApiDevelopment.md) · [Application contracts and OpenAPI](ApplicationContract.md) · [API verification](ApiVerification.md) · [Client SDK generation](SdkGeneration.md) · [API resources](ApiResources.md) · [API responses and errors](ApiResponses.md) · [API versioning](ApiVersioning.md) · [CORS](Cors.md) · [API tokens](ApiTokens.md) · [Webhooks](Webhooks.md) · [Views](Views.md) · [Returnable View Responses](ViewResponses.md) · [Package and Namespaced Views](PackageViews.md) · [Compiled Views and Production Lifecycle](CompiledViews.md) · [View Diagnostics and Testing](ViewDiagnosticsTesting.md) · [Fragments and Partial Responses](Fragments.md) · [Template Utilities](TemplateUtilities.md) · [Includes and Reusable Partials](Includes.md) · [Components, Props and Slots](Components.md) · [Shared View Context](ViewContext.md) · [Reliable Template Compiler](TemplateCompiler.md) · [Conditionals and Control Flow](Conditionals.md) · [Loops and Iteration State](Loops.md) · [Layouts and Sections](Layouts.md) · [Template-owned Scripts, Styles and Asset Stacks](Assets.md) · [Forms and Validation UX](Forms.md) · [Auth, Guards, Session and Authorization](ViewSecurity.md) · [Validation](Validation.md) · [Sessions](Sessions.md) · [CSRF](Csrf.md)

## Frontend

[Frontend assets and native modules](FrontendAssets.md) · [Frontend profiles and Vite builds](FrontendProfiles.md) · [SPA routing](SpaRouting.md) · [Frontend Session Auth and CSRF](FrontendAuth.md) · [Views and layouts](Views.md) · [Deployment](Deployment.md)

The PHP-only default uses normal Views and `asset()` without Node.js. A native browser module can remain build-free; React, Vue, and Vite are optional reviewed profiles. The backend remains authoritative for routes, API responses, Session Auth, and CSRF. A selected Vite profile uses the same View shell and a validated production manifest; SPA fallback is separate and opt-in.

## Data

[Database and query builder](Database.md) · [Schema](Schema.md) · [Migrations](Migrations.md) · [Models](Models.md) · [Relationships](Relationships.md) · [Collections](Collections.md) · [Pagination](Pagination.md) · [Named scopes](Scopes.md) · [Soft deletes](SoftDeletes.md) · [Seeders](Seeders.md) · [Factories](Factories.md) · [Typed application data](ApplicationData.md)

New v2 data setup uses Seeders. The v1 Dumper base and commands are retired; [Upgrade from v1.x](UpgradeFromV1.md) explains the migration.

## Security

[Security overview](Security.md) · [Authentication and remember-me](Authentication.md#optional-remembered-browser-login) · [Multi-factor authentication](MFA.md) · [OIDC login](OAuth.md) · [Authorization](Authorization.md) · [Roles and permissions](RBAC.md) · [Auth, Guards, Session and Authorization in Views](ViewSecurity.md) · [Account security](AccountSecurity.md) · [Rate limiting](RateLimiting.md) · [HTTP idempotency](Idempotency.md) · [Cryptography](Cryptography.md) · [Browser security policy](BrowserSecurityPolicy.md) · [Signed URLs](SignedUrls.md)

## Services and integrations

[Packages](Packages.md) · [SqueHub Kits](Kits.md) · [Activation Registry](ActivationRegistry.md) · [Large applications](LargeApplications.md) · [Contributions and provenance](Contributions.md) · [Reviewable changes](ReviewableChanges.md) · [Internationalization](Internationalization.md) · [Cache](Cache.md) · [Optional infrastructure adapters](InfrastructureAdapters.md) · [Redis](Redis.md) · [Events and queued listeners](Events.md) · [Storage](Storage.md) · [S3-compatible Storage](ProviderStorage.md) · [Mail](Mail.md) · [HTTP Mail providers](ProviderMail.md) · [Notifications](Notifications.md) · [Queue](Queue.md) · [Queue composition](QueueComposition.md) · [Redis Queue](RedisQueue.md) · [Broadcasting](Broadcasting.md) · [Scheduler](Scheduler.md) · [Outgoing HTTP client](HttpClient.md) · [Shared retry policy](Retries.md) · [Locks](Locks.md) · [Circuit breaker](CircuitBreaker.md)

Packages and Kits use [explicit installation sources](Packages.md#installing-packages-from-an-explicit-source), followed by a reviewed plan and deliberate enable. A central installation registry is not required. Package source currently means a local directory or credential-free HTTPS Git checkout; Kit source means a local directory. ZIP files and release archive URLs are not direct installer inputs. The [Package](Packages.md) and [Kit](Kits.md) guides include source matrices, manifests, lifecycle commands, security limits, and planned official examples.

## Operations

[Public v2 status](Status.md) · [CLI](Cli.md) · [SqueHub Setup](Setup.md) · [SqueHub Dev](Dev.md) · [SqueHub Studio](Studio.md) · [Agent and AI integration](AgentAndAI.md) · [Local MCP setup](AgentMcpSetup.md) · [MCP tools and resources](AgentMcpTools.md) · [Code generators](Generators.md) · [SqueHub Feature Blueprint](FeatureBlueprints.md) · [Testing](Testing.md) · [Logging](Logging.md) · [Diagnostics](Diagnostics.md) · [Observability](Observability.md) · [Development profiler](Profiler.md) · [Request correlation](Correlation.md) · [Framework performance caches](PerformanceCaching.md) · [Health and Doctor](Health.md) · [Performance](Performance.md) · [Deployment](Deployment.md) · [Deployment proof profiles](DeploymentProof.md) · [Project bundles](ProjectBundles.md) · [Upgrade preflight](UpgradePreflight.md) · [Recovery boundaries](Recovery.md) · [Backup and portability](BackupAndPortability.md) · [Troubleshooting](Troubleshooting.md)

## Documentation policy

Examples in this set are based on the current core source and integration tests. A feature is documented as available only when its implementation exists. Optional infrastructure, including Redis, MySQL, external SMTP, and workers, needs its own configuration and verification. [Feature status](FeatureStatus.md) separates implemented APIs from release verification.

For framework behavior changes, update source and tests first, then the technical guides in Docs/V2.x, then the public website documentation, and finally the html-doc design/content mirror where the change affects its pages or components. Core source and tests determine behavior; the website and prototype must agree with those contracts. Carry deliberate public design changes between the website and prototype in both directions. The historical v1.x documentation remains an archive, not the v2 technical authority.

The [Request Cases research decision](RequestCases.md) records why Phase 13G does not currently provide a capture or replay API. Use explicit [integration tests](Testing.md) or [API contract verification](ApiVerification.md) for executable request checks.

`Docs/V2.x/` is tracked source for the v2 documentation portal. Its public guides are build-time input; generated portal output remains ignored, and internal status and audit pages are excluded by `BuildPublicPortal.py`. Build and check the catalog from the exact source revision selected for publication, verify the deployed public routes, and keep the historical v1.x routes and content intact.
