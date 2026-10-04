# SqueHub v2.x documentation

These guides describe SqueHub v2.0.0 APIs and application conventions. Read the [official v2.x documentation](https://www.squehub.com/docs/v2.x) online, or browse the guides in this directory alongside the framework source.

Start with the [installation guide](Installation.md) and [first application](FirstApplication.md). The [public status guide](Status.md) explains supported capabilities and verification boundaries. The [v1.x documentation](https://www.squehub.com/docs/v1.x) remains available for older applications.

Application examples prefer `App\Plugins` imports. Global helpers and legacy APIs are called out where they remain supported.

## Getting started

| Guide | What it covers |
| --- | --- |
| [Installation](Installation.md) | Composer installation, PHP requirements, `.env`, `APP_KEY`, Doctor, and local server |
| [SqueHub Setup](Setup.md) | Optional guided configuration, reviewable plan, manual alternative, and safety boundaries |
| [SqueHub Dev](Dev.md) | Foreground development session, PHP server, Doctor preflight, and optional persistent Queue worker |
| [First application](FirstApplication.md) | Route, controller, view, form, validation, and database example |
| [Directory structure](DirectoryStructure.md) | Where application code, packages, migrations, templates, and runtime files live |
| [Configuration](Configuration.md) | Environment keys, configuration loading, debug policy, and service drivers |
| [Public v2 status](Status.md) | Supported features, qualified environments, and Package/Kit availability |
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
| [Upgrade from v1.x](UpgradeFromV1.md) | Public API changes and migration steps |

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

Examples are based on the framework source and integration tests. Optional infrastructure, including Redis, MySQL, external SMTP, and workers, needs its own configuration and verification. The [public status guide](Status.md) distinguishes supported APIs from environment-specific qualification.

The framework source and tests determine behavior. Public guides explain supported contracts and known limits; the [official documentation](https://www.squehub.com/docs/v2.x) is the preferred reading experience. The historical v1.x documentation describes an earlier API and is not the v2 technical reference.
