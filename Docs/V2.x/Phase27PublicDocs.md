# Phase 27 public documentation: internal implementation record

**Recorded:** 2026-10-03. **Scope:** the local documentation and ecosystem portal for the current SqueHub v2 site, the historical v1 content migration, and the development-tree v2 guides. This is an internal handoff record. `BuildPublicPortal.py` excludes this file from the public catalog.

Phase 27 covers the public documentation portal (27A), versioned v1/v2 articles (27B), example and link verification (27C), and the v1-to-v2 upgrade guide (27D). The local XAMPP implementation is distinct from a public production deployment. SqueHub v2.0.0 remains an unpublished development tree; clean-source reproduction, a v2 Composer artifact, production documentation publication, and release approval belong to Phase 28.

## Source and delivery boundaries

| Location | Role |
| --- | --- |
| `on_dev/squehub-v2/Docs/V2.x/` | Authoritative v2 technical prose, internal status, static builders, generated catalog, and staged site artifacts. |
| `on_doc/squehub/project/Views/docs/v1x/` | Historical v1 article source. Its PHP application architecture is not copied into the v2 site. |
| `on_doc/html-doc/` | Reviewed visual prototype, CSS tokens, responsive layout, and browser interactions. It is design input, not v2 API authority. |
| `D:/xampp/htdocs/` | Local current SqueHub v2 website and live Apache test target for the versioned documentation implementation. |

`BuildPublicPortal.py` reads the two explicit content roots at build time. It renders v2 Markdown through a bounded renderer and sanitizes historical v1 markup into inert HTML fragments. Its manifest determines slugs, categories, headings, navigation, and search text. `BuildSiteArtifacts.py` stages the manifest and fragments, prototype assets, version-aware search index, and landing View. The site's documentation controller selects only a catalog entry, checks its slug and resolved fragment path, and renders through SqueHub v2 Views. Runtime requests do not read the source repositories or render PHP from v1 templates.

## 27B: historical v1 source to route mapping

All 18 historical article templates in `on_doc/squehub/project/Views/docs/v1x/` are represented. The old site's `project/Routes/docs-1x.php` supplies the direct versioned routes; `project/Routes/doc-anchor.php` supplies the old unversioned links. The new catalog uses `/docs/v1.x` as the archive home. A dash means the old anchor file has no matching unversioned redirect. The old `/docs` default pointed to v1; the new `/docs` redirects to current `/docs/v2.x`.

| Historical template | Old unversioned URL | Local v1 route | Migration note |
| --- | --- | --- | --- |
| `installation.squehub.php` | `/docs/installation` | `/docs/v1.x/installation` | The old anchor redirected to the v1 home. |
| `directory-structure.squehub.php` | `/docs/directory-structure` | `/docs/v1.x/directory-structure` | Historical layout retained. |
| `configuration.squehub.php` | `/docs/configuration` | `/docs/v1.x/configuration` | Historical settings retained. |
| `cli.squehub.php` | `/docs/cli` | `/docs/v1.x/cli` | Historical commands are labeled v1. |
| `namespace.squehub.php` | `/docs/namespace` | `/docs/v1.x/namespace` | Historical namespace guide retained. |
| `backup.squehub.php` | `/docs/backup-and-upgrade` | `/docs/v1.x/backup-and-upgrade` | Source name differs from route slug. |
| `routing.squehub.php` | `/docs/routing` | `/docs/v1.x/routing` | `$router->add(...)` remains v1 content. |
| `controllers.squehub.php` | `/docs/controllers` | `/docs/v1.x/controllers` | Historical controller syntax retained. |
| `middleware.squehub.php` | `/docs/middleware` | `/docs/v1.x/middleware` | Historical middleware syntax retained. |
| `views.squehub.php` | `/docs/views-and-blade` | `/docs/v1.x/views-and-blade` | The old unversioned redirect targeted `/docs/v1.x/views`, while its versioned route was `views-and-blade`; the catalog uses the real versioned slug and recognizes `views` as an alias for source links. |
| `models.squehub.php` | `/docs/models` | `/docs/v1.x/models` | Historical Model behavior retained. |
| `migrations.squehub.php` | `/docs/migrations` | `/docs/v1.x/migrations` | Historical migration behavior retained. |
| `dumper.squehub.php` | — | `/docs/v1.x/dumper` | Retired in v2; remains in the archive. |
| `helper.squehub.php` | `/docs/helper` | `/docs/v1.x/helper` | Historical helper behavior retained. |
| `packages.squehub.php` | `/docs/packages` | `/docs/v1.x/packages` | Historical Package behavior retained. |
| `mail.squehub.php` | `/docs/mail` | `/docs/v1.x/mail` | Historical Mail behavior retained. |
| `notification.squehub.php` | `/docs/notification` | `/docs/v1.x/notification` | Historical Notification behavior retained. |
| `task.squehub.php` | `/docs/task` | `/docs/v1.x/task` | Historical scheduled-task behavior retained. |

The local controller redirects recognized unversioned article slugs to the archive and handles the `routes` → `routing` and `views` → `views-and-blade` aliases. This local behavior does not establish that every historical public URL is already redirected on the production host; publication requires a separate route audit.

## 27B: v2 public source coverage

The current source contains **122 v2 guide files plus one curated v2 index fragment**, and **18 v1 articles**, for **141 catalog pages after rebuild**. The added [public status guide](Status.md) summarizes the release boundary and published Package/Kit availability. Each row below gives a source file in `Docs/V2.x/` and the suffix under `/docs/v2.x/`. These rows establish source inclusion and catalog routing. They do not, by themselves, assert that every code sample was executed. The canonical home route `/docs/v2.x` is assembled by `DocumentationController::home()` from the catalog; `BuildPublicPortal.py` also emits a curated index fragment for `/docs/v2.x/index` and search. Neither uses the internal `README.md` as public copy.

| Category | Source file | Route suffix |
| --- | --- | --- |
| Getting started | [Installation.md](Installation.md) | `installation` |
| Getting started | [Setup.md](Setup.md) | `setup` |
| Getting started | [Dev.md](Dev.md) | `dev` |
| Getting started | [FirstApplication.md](FirstApplication.md) | `first-application` |
| Getting started | [DirectoryStructure.md](DirectoryStructure.md) | `directory-structure` |
| Getting started | [Configuration.md](Configuration.md) | `configuration` |
| Getting started | [Generators.md](Generators.md) | `generators` |
| Getting started | [FeatureBlueprints.md](FeatureBlueprints.md) | `feature-blueprints` |
| Getting started | [Helpers.md](Helpers.md) | `helpers` |
| Getting started | [Testing.md](Testing.md) | `testing` |
| Application and HTTP | [Application.md](Application.md) | `application` |
| Application and HTTP | [Container.md](Container.md) | `container` |
| Application and HTTP | [Plugins.md](Plugins.md) | `plugins` |
| Application and HTTP | [Routing.md](Routing.md) | `routing` |
| Application and HTTP | [Controllers.md](Controllers.md) | `controllers` |
| Application and HTTP | [Middleware.md](Middleware.md) | `middleware` |
| Application and HTTP | [Http.md](Http.md) | `http` |
| Application and HTTP | [Responses.md](Responses.md) | `responses` |
| Application and HTTP | [Errors.md](Errors.md) | `errors` |
| Application and HTTP | [Sessions.md](Sessions.md) | `sessions` |
| Application and HTTP | [Csrf.md](Csrf.md) | `csrf` |
| Application and HTTP | [Validation.md](Validation.md) | `validation` |
| Application and HTTP | [Forms.md](Forms.md) | `forms` |
| Application and HTTP | [SignedUrls.md](SignedUrls.md) | `signed-urls` |
| Views and frontend | [Views.md](Views.md) | `views` |
| Views and frontend | [ViewResponses.md](ViewResponses.md) | `view-responses` |
| Views and frontend | [PackageViews.md](PackageViews.md) | `package-views` |
| Views and frontend | [CompiledViews.md](CompiledViews.md) | `compiled-views` |
| Views and frontend | [ViewDiagnosticsTesting.md](ViewDiagnosticsTesting.md) | `view-diagnostics-testing` |
| Views and frontend | [Fragments.md](Fragments.md) | `fragments` |
| Views and frontend | [TemplateUtilities.md](TemplateUtilities.md) | `template-utilities` |
| Views and frontend | [Includes.md](Includes.md) | `includes` |
| Views and frontend | [Components.md](Components.md) | `components` |
| Views and frontend | [ViewContext.md](ViewContext.md) | `view-context` |
| Views and frontend | [TemplateCompiler.md](TemplateCompiler.md) | `template-compiler` |
| Views and frontend | [Conditionals.md](Conditionals.md) | `conditionals` |
| Views and frontend | [Loops.md](Loops.md) | `loops` |
| Views and frontend | [Layouts.md](Layouts.md) | `layouts` |
| Views and frontend | [Assets.md](Assets.md) | `assets` |
| Views and frontend | [ViewSecurity.md](ViewSecurity.md) | `view-security` |
| Views and frontend | [FrontendAssets.md](FrontendAssets.md) | `frontend-assets` |
| Views and frontend | [FrontendProfiles.md](FrontendProfiles.md) | `frontend-profiles` |
| Views and frontend | [SpaRouting.md](SpaRouting.md) | `spa-routing` |
| Views and frontend | [FrontendAuth.md](FrontendAuth.md) | `frontend-auth` |
| Database and ORM | [Database.md](Database.md) | `database` |
| Database and ORM | [Schema.md](Schema.md) | `schema` |
| Database and ORM | [Migrations.md](Migrations.md) | `migrations` |
| Database and ORM | [Models.md](Models.md) | `models` |
| Database and ORM | [Relationships.md](Relationships.md) | `relationships` |
| Database and ORM | [Collections.md](Collections.md) | `collections` |
| Database and ORM | [Pagination.md](Pagination.md) | `pagination` |
| Database and ORM | [Scopes.md](Scopes.md) | `scopes` |
| Database and ORM | [SoftDeletes.md](SoftDeletes.md) | `soft-deletes` |
| Database and ORM | [Seeders.md](Seeders.md) | `seeders` |
| Database and ORM | [Factories.md](Factories.md) | `factories` |
| Database and ORM | [ApplicationData.md](ApplicationData.md) | `application-data` |
| Identity and security | [Security.md](Security.md) | `security` |
| Identity and security | [Authentication.md](Authentication.md) | `authentication` |
| Identity and security | [MFA.md](MFA.md) | `mfa` |
| Identity and security | [OAuth.md](OAuth.md) | `o-auth` |
| Identity and security | [Authorization.md](Authorization.md) | `authorization` |
| Identity and security | [RBAC.md](RBAC.md) | `rbac` |
| Identity and security | [AccountSecurity.md](AccountSecurity.md) | `account-security` |
| Identity and security | [RateLimiting.md](RateLimiting.md) | `rate-limiting` |
| Identity and security | [Cryptography.md](Cryptography.md) | `cryptography` |
| Identity and security | [BrowserSecurityPolicy.md](BrowserSecurityPolicy.md) | `browser-security-policy` |
| Identity and security | [ApiTokens.md](ApiTokens.md) | `api-tokens` |
| API and integration | [ApiDevelopment.md](ApiDevelopment.md) | `api-development` |
| API and integration | [ApplicationContract.md](ApplicationContract.md) | `application-contract` |
| API and integration | [ApiVerification.md](ApiVerification.md) | `api-verification` |
| API and integration | [SdkGeneration.md](SdkGeneration.md) | `sdk-generation` |
| API and integration | [ApiResources.md](ApiResources.md) | `api-resources` |
| API and integration | [ApiResponses.md](ApiResponses.md) | `api-responses` |
| API and integration | [ApiVersioning.md](ApiVersioning.md) | `api-versioning` |
| API and integration | [Cors.md](Cors.md) | `cors` |
| API and integration | [Webhooks.md](Webhooks.md) | `webhooks` |
| API and integration | [AgentAndAI.md](AgentAndAI.md) | `agent-and-ai` |
| API and integration | [HttpClient.md](HttpClient.md) | `http-client` |
| Services and infrastructure | [Packages.md](Packages.md) | `packages` |
| Services and infrastructure | [Kits.md](Kits.md) | `kits` |
| Services and infrastructure | [ActivationRegistry.md](ActivationRegistry.md) | `activation-registry` |
| Services and infrastructure | [LargeApplications.md](LargeApplications.md) | `large-applications` |
| Services and infrastructure | [Contributions.md](Contributions.md) | `contributions` |
| Services and infrastructure | [ReviewableChanges.md](ReviewableChanges.md) | `reviewable-changes` |
| Services and infrastructure | [Internationalization.md](Internationalization.md) | `internationalization` |
| Services and infrastructure | [Cache.md](Cache.md) | `cache` |
| Services and infrastructure | [InfrastructureAdapters.md](InfrastructureAdapters.md) | `infrastructure-adapters` |
| Services and infrastructure | [Redis.md](Redis.md) | `redis` |
| Services and infrastructure | [Events.md](Events.md) | `events` |
| Services and infrastructure | [Storage.md](Storage.md) | `storage` |
| Services and infrastructure | [ProviderStorage.md](ProviderStorage.md) | `provider-storage` |
| Services and infrastructure | [Mail.md](Mail.md) | `mail` |
| Services and infrastructure | [ProviderMail.md](ProviderMail.md) | `provider-mail` |
| Services and infrastructure | [Notifications.md](Notifications.md) | `notifications` |
| Services and infrastructure | [Queue.md](Queue.md) | `queue` |
| Services and infrastructure | [QueueComposition.md](QueueComposition.md) | `queue-composition` |
| Services and infrastructure | [RedisQueue.md](RedisQueue.md) | `redis-queue` |
| Services and infrastructure | [Broadcasting.md](Broadcasting.md) | `broadcasting` |
| Services and infrastructure | [Scheduler.md](Scheduler.md) | `scheduler` |
| Services and infrastructure | [Retries.md](Retries.md) | `retries` |
| Services and infrastructure | [Locks.md](Locks.md) | `locks` |
| Services and infrastructure | [CircuitBreaker.md](CircuitBreaker.md) | `circuit-breaker` |
| Services and infrastructure | [Idempotency.md](Idempotency.md) | `idempotency` |
| Operations and deployment | [Status.md](Status.md) | `status` |
| Operations and deployment | [Cli.md](Cli.md) | `cli` |
| Operations and deployment | [Studio.md](Studio.md) | `studio` |
| Operations and deployment | [Logging.md](Logging.md) | `logging` |
| Operations and deployment | [Diagnostics.md](Diagnostics.md) | `diagnostics` |
| Operations and deployment | [Observability.md](Observability.md) | `observability` |
| Operations and deployment | [Profiler.md](Profiler.md) | `profiler` |
| Operations and deployment | [Correlation.md](Correlation.md) | `correlation` |
| Operations and deployment | [PerformanceCaching.md](PerformanceCaching.md) | `performance-caching` |
| Operations and deployment | [Health.md](Health.md) | `health` |
| Operations and deployment | [Performance.md](Performance.md) | `performance` |
| Operations and deployment | [Deployment.md](Deployment.md) | `deployment` |
| Operations and deployment | [DeploymentProof.md](DeploymentProof.md) | `deployment-proof` |
| Operations and deployment | [ProjectBundles.md](ProjectBundles.md) | `project-bundles` |
| Operations and deployment | [UpgradePreflight.md](UpgradePreflight.md) | `upgrade-preflight` |
| Operations and deployment | [Recovery.md](Recovery.md) | `recovery` |
| Operations and deployment | [BackupAndPortability.md](BackupAndPortability.md) | `backup-and-portability` |
| Operations and deployment | [Troubleshooting.md](Troubleshooting.md) | `troubleshooting` |
| Upgrade | [UpgradeFromV1.md](UpgradeFromV1.md) | `upgrade-from-v1` |

`CodeStandards.md`, `FeatureStatus.md`, `Phase27PublicDocs.md`, `PreCommitManifest.md`, `PublicApiAudit.md`, `README.md`, `ReleaseReadiness.md`, `RequestCases.md`, `Roadmap.md`, `Stabilization.md`, and `V1DocumentationComparison.md` are internal source and are excluded from public article and search output. Links from public guides to the internal feature status, release readiness, or roadmap resolve to the curated public [v2 status](Status.md) page with generic link text. Review `INTERNAL_V2` when adding a new internal Markdown file.

## 27A: design and interaction record

The design uses the prototype's SqueHub navy, cool paper surfaces, and teal accents (`--navy`, `--paper`, `--accent`), a small set of layout widths and surface tokens, Inter/system UI for prose, and a monospace stack for code. The full article layout has site header, version selector, grouped left navigation, readable article width, right heading TOC, previous/next links, and footer. Article components include labeled copyable code blocks, callouts, responsive tables, breadcrumbs, cards, and a docs-specific 404 page. The site's `/` route renders the landing View and links to the versioned docs; `/index.html` is not a published route or physical entry point.

The CSS changes layout at 1300, 1100, 850, 640, and 430 px: the right TOC drops before the mobile navigation drawer, and the article becomes primary on narrow screens. The portal CSS loads after the base responsive sheet and keeps the version selector visible at phone widths; a 390 px browser check confirmed the v2 button and menu without horizontal overflow. Reduced-motion and print rules are present. Theme selection cycles system/light/dark, follows the system preference in system mode, and stores a preference in `localStorage` where available. The search index is built from intended public pages, filtered to the active version, bounded in the browser, and uses title, heading, and article terms with keyboard selection and an empty state. The version selector targets the same slug when a counterpart exists and otherwise falls back to that version's home. Formal WCAG certification and cross-browser support claims are not made by this local smoke record.

The routed landing, docs, and ecosystem pages use the same primary Home, Docs, Packages, Kits, and Community navigation; the landing marks Home as the current page. The landing shares the ecosystem header's SqueHub brand and search/theme controls, while version selection remains on documentation pages. A slim banner below the header identifies **v2.0.0 as the current SqueHub development version** on the landing, every v1/v2 documentation page, and the ecosystem pages. It stays distinct from the documentation selector's `v1.x`/`v2.x` generation labels and does not claim that v2.0.0 has been released. `BuildSiteArtifacts.py` regenerates the landing banner and metadata from the read-only prototype. The homepage title is **SqueHub — The PHP Framework for Modern Web Builders**; its description and Open Graph/Twitter tags describe the framework and point to the canonical `https://www.squehub.com/` URL. Each documentation article has a distinct title of the form `Article — SqueHub v2.0.0 Documentation` (or `v1.x` for the historical archive); the version homes have their own concise titles. HTML, Open Graph, and Twitter titles match. The shared 1200 × 630 social preview is served at `/assets/images/og/squehub.png` and uses the official SqueHub icon. The official `sq_icon.png` source from the historical first-party design is copied unchanged as `squehub-icon.png` for current header marks and PNG favicons. The two-piece preloader uses the actual historical `sq-l.png` and `sq-r.png` files. It converges on a dark navy surface, settles briefly, then fades out in under two seconds. The overlay stays hidden without JavaScript and for reduced-motion users; a timeout and CSS exit prevent an indefinite page block. The historical source does not contain a `sq-2.png` file.

## Public ecosystem pages

The landing now introduces Packages, Kits, snapshots/recovery guidance, and planned product concepts. Snapshots link to the existing recovery guide; there is no new snapshot endpoint or claim that a project source bundle includes live database records. Public routes are `/packages`, `/kits`, `/community`, `/partners`, `/changelogs`, and `/contact`. The requested `/Packages` and `/Kits` spellings return 301 redirects to lowercase canonical paths. Catalog detail routes use `/packages/{package_name}/{section?}` and `/kits/{kit_name}/{section?}`; `overview` canonicalizes to the item base URL, while available `details` and `docs` sections have distinct URLs. Unknown entries and unavailable sections return uncached, unindexed 404 responses.

`Project/Ecosystem/catalog.json` separates published releases from planned concepts. Its published Package and Kit arrays are currently empty. The planned first Package is **`squehub/media`** at `/packages/media`: media records, Model attachments, collections, validation and access policies, downloads, thumbnails, and cleanup are a proposed layer over core Storage. The planned first Kit is **`squehub/app-starter`** at `/kits/app-starter`: account UI, verification and recovery, dashboard, admin scaffolding, responsive Views, and tests would compose existing core Auth rather than reimplement it. Both pages say they are **not released or installable**, and planned detail pages use `noindex,follow` metadata. The Kit's named `kit:install` example is labeled a proposed future registry workflow: the present CLI accepts a local Kit directory, and no App Starter artifact exists. Neither catalog entry is represented as a download, implemented Package/Kit, or current CLI installation target.

The Community page links to the SqueHub repository and the Telegram channel published by the historical [SqueHub community page](https://www.squehub.com/community). The Changelogs page identifies its entries as historical v1 releases and links the [original v1 changelog](https://www.squehub.com/changelog); it does not announce a v2 release. Contact uses the `hello@squehub.com` address present in the earlier first-party site source and adds no unimplemented contact form. The `/partners` page offers individual and company contact paths at `partner@squehub.com`; `/partner` redirects to it. CybQu is identified as the actual sponsor using the mark from its first-party site, while every additional showcase slot is labeled a placeholder. Both the landing and Partners strips scroll right to left, pause on hover, and stop under reduced-motion preference. The landing has four separate CSS 3D scenes: a hero stack for routing, Views, data, security, and Queue; an Agent diagram for bounded local MCP inspection and reviewable plans; layered interface panels for optional Vite, React, and Vue frontend profiles; and an illustrative Studio dashboard with floating Routes, Profiler, and Health cards. The Agent, frontend, and Studio scenes remain promotional illustrations, with links to their documented implementation boundaries. Each scene has a reduced-motion fallback. The site renders these routes through the normal SqueHub registry, a bounded catalog controller, and escaped SqueHub Views; it does not use physical HTML files for live routes.

## 27C: verification record and limits

The original Phase 27 local `VerifyPortal.py` pass, before the public Status guide was added, reported **140 catalog pages, 1,312 internal links, and zero issues**. It checks rendered fragment safety, duplicate IDs, local and cross-article anchors, and catalog destinations. With `--site-root` it checks deployed assets, including the reviewed 1200 × 630 social preview, versioned search URLs, and late-article search coverage. With a local Apache base URL it also checks `/docs` redirect behavior, all versioned article routes and homes, page-specific titles, homepage Open Graph/Twitter metadata, the social preview URL, docs 404s, selected protected paths, metadata, content-type hardening, and the root landing. That original live run covered **141 documentation HTTP pages**. The latest 3 October 2026 rebuild and local XAMPP verification covered **141 catalog pages, 1,401 internal links, and 142 documentation HTTP pages, with zero issues**. The separate ecosystem verifier passed **35 HTTP checks with zero issues**, including `/partners`, `/partner` canonicalization, and the CybQu logo response. These are local Windows/XAMPP results, not production-host or Linux Apache evidence.

Browser inspection covered representative desktop and mobile pages, version switching, current-version search, theme, and docs 404. `php squehub list --raw` succeeded and enumerated **72 current commands**. A scan of public guides found **73 distinct `php squehub ...` command tokens**: each was registered except `h` (a working `help` alias, separately run successfully) and `optimize` (both mentions explicitly say it is unavailable). Selected installation, configuration, deployment, frontend, Queue, Redis, and Agent guides referenced **46 configuration names**. Forty-four were in `.example.env` or `Config/*.php`; the other two, `SQUEHUB_TEST_MYSQL_ENABLED` and `SQUEHUB_TEST_REDIS_URL`, were confirmed as opt-in test controls under `Tests/`. Representative doc/source/test file and signature checks covered **20 areas**: installation, routing, controllers, Views, validation, Database, Schema, Models, Auth, API resources, Queue, Scheduler, Redis, Storage, Packages, frontend profiles, Locks, idempotency, Agent, and deployment. All 20 resolved after checking the Package command's dynamic registration against the live CLI registry. These are static contract checks and test-source inspection, not fresh runtime executions of every example.

The original local ecosystem verifier passed **31 HTTP checks with zero issues**; 30 distinct internal links sampled from the then-current landing and ecosystem pages returned HTTP 200. Live browser checks at 900 px and 390 px showed the original five primary links at tablet width, a bounded 30 px brand mark, a working mobile menu, and no horizontal overflow. Both preloader images loaded and animated; the overlay dismissed after about 1.9 seconds. SHA-256 hashes of the three deployed PNGs matched their read-only first-party source images. The reduced-motion guard passed CSS inspection and a JavaScript match-media simulation; the browser's OS motion preference was not toggled. The current 35-check run is recorded above. These are local layout and behavior checks, not accessibility certification.

The final local full core regression ran as `composer test` with a temporary `PHPRC` copy of the XAMPP `php.ini` that omitted a duplicate `extension=php_openssl.dll` directive and a command-local `COMPOSER_PROCESS_TIMEOUT=900`. It passed **2,957 tests, 22,785 assertions, 87 skips, zero failures, and zero errors** in **05:56.794**. An earlier `composer test` invocation reached its default 300-second process timeout while tests were still passing; it is not counted as a completed suite. No global PHP or Composer configuration was changed. `composer validate --strict`, lint on 16 touched PHP files, portal Python/JavaScript syntax checks, and `git diff --check` also passed. These are local Windows working-tree checks, not clean-artifact or optional-backend qualification. Phase 26's native Linux, Redis, MySQL, and no-dev qualification is an earlier, separately scoped record in [Release readiness](ReleaseReadiness.md); Phase 27 did not rerun those backend suites.

## 27D: upgrade guide boundary

The public [Upgrade from v1](UpgradeFromV1.md) guide has a stable `/docs/v2.x/upgrade-from-v1` route. It compares PHP/project layout, path-first routing, View escaping and placement, Request/Response behavior, Models, configuration, Seeders replacing Dumpers, Packages and Scheduler activation, helpers, CLI, deployment, and backup/recovery limits. It recommends a disposable migration copy, data backups, explicit `.env` and `APP_KEY` handling, targeted tests, and manual review. It promises no automatic whole-application conversion and does not present v1 syntax as a v2 API.

## Build, stage, deploy, and verify

Run from the v2 core root. Paths shown here are the local Windows Phase 27 workspaces; use the actual reviewed roots on another host.

```powershell
python Docs/V2.x/BuildPublicPortal.py `
  --core-docs-root Docs/V2.x `
  --v1-templates-root D:/Projects/Squehub/on_doc/squehub/project/Views/docs/v1x `
  --output-dir Docs/V2.x/PortalBuild

python Docs/V2.x/BuildSiteArtifacts.py `
  --catalog Docs/V2.x/PortalBuild `
  --prototype-root D:/Projects/Squehub/on_doc/html-doc `
  --stage Docs/V2.x/PortalSite

python Docs/V2.x/VerifyPortal.py `
  --catalog Docs/V2.x/PortalBuild
```

Review the manifest, generated fragments, ecosystem catalog, search index, CSS/JS, controllers, routes, Views, and current site diff. Deploy only the reviewed `PortalSite` artifacts to their matching locations in the current v2 site (`Project/Documentation/`, `Project/Ecosystem/`, `Project/Controllers/`, `Project/Routes/`, `Project/Views/Docs/`, `Project/Views/Site/`, and the site's served docs asset paths). Include `landing.css`, `landing-platform.css`, `landing-studio.css`, `landing.js`, `ecosystem.css`, `preloader.css`, `preloader.js`, the reviewed CybQu SVG, and the three reviewed PNGs wherever the site serves docs assets. Copy the reviewed OG PNG to the site's served `/assets/images/og/squehub.png` path; it is separate from `/assets/docs/images/`. Copy the current framework's official favicon asset and manifest with any core View/error-handler favicon changes. Keep site-specific configuration and unrelated pages intact. The site's `/` route serves the landing View; do not deploy a physical `/index.html`. The staged fragments are content artifacts; do not expose source Markdown, historical PHP templates, internal reports, `.env`, or private project files through document root or search.

After local deployment, run `python Docs/V2.x/VerifyPortal.py --catalog Docs/V2.x/PortalBuild --site-root D:/xampp/htdocs --base-url http://localhost` and `python Docs/V2.x/VerifyEcosystem.py --base-url http://localhost`. The first `--site-root` option checks deployed `Assets/docs/` paths; the staging tree keeps those assets under `public/assets/docs/`, so use catalog-only verification before deployment. Check representative v1/v2 routes, ecosystem listings and detail pages, shared and missing version counterparts, title/heading/content search, no-result and long-query search, theme persistence, desktop/tablet/mobile layouts, keyboard focus, code copying, protected-path responses, and important preexisting site pages. Record the environment and exact results. Rebuild and redeploy after any content, prototype, or portal-source change; do not edit generated fragments as the source of truth.

## Phase 28 boundary

This report documents a local documentation implementation and its bounded checks. It does not establish a public `squehub.com` deployment, a clean committed source distribution, a published v2 Composer package, `composer create-project` proof, or release approval. Those remain Phase 28 work, along with any additional platform and provider claims chosen for v2.0.0.
