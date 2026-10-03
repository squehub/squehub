# Packages and application extensions

A SqueHub Package is a reusable runtime capability under `Project/Packages/<PackageName>/`. Small applications can continue using ordinary `Project/` classes. [Large applications](LargeApplications.md) can introduce Package boundaries gradually; Packages are never required for ordinary controllers, routes, models, or views. [SqueHub Kits](Kits.md) compose broader application solutions in a separate lifecycle. `App\Plugins` is the framework API gateway, not a Package or Kit installation location.

## Structure and public API

```text
Project/Packages/Weather/
├── Weather.php
├── Controllers/
├── Models/
├── Middleware/
├── Utils/
├── Views/
├── Routes/
├── Assets/
├── Config/
└── composer.json
```

Only `Weather.php` is required; the other directories are optional. Its `Packages\Weather\Weather` class is the Package entry, bootstrap, and deliberate public API. The entry extends `App\Plugins\ServiceProvider` and may implement `register()` for container bindings and `boot()` for runtime setup. The compatible `Project\Packages\Weather\Weather` namespace is also accepted. The existing `Packages\` public namespace remains available, so application code can use `Packages\Weather\Weather::today()` when the Package exposes that method. Internal Package classes do not become global framework APIs; an integration needing another Package must declare the dependency and use an intentionally exposed entry API or service contract.

Package names, the `Project/Packages/` root, and entry filenames must use exact canonical casing for managed activation. A sole lowercase `project/` may serve older application/view lookups, but Package lifecycle writes require `Project/`; separate `Project/` and `project/` trees in one application are unsafe on case-sensitive hosting. Discovery checks structure and metadata without including the entry, routes, commands, or other Package PHP. A successful static inspection is not a security review or an execution sandbox.

## Installed and enabled state

Installation and activation are separate. A Package can be absent, installed and disabled, installed and enabled, or broken. Only enabled Packages contribute runtime behavior. A new Package copied manually into `Project/Packages/` is discovered as disabled; enable it explicitly after inspection. A newly installed Package is also disabled by default.

The [SqueHub Activation Registry](ActivationRegistry.md) stores Package and Kit activation in one private Application-scoped state file. Package ownership and the last verified contribution snapshot remain Package-specific records within that state. It requires no database, Redis, or Node.js. Managed records use safe source labels and SHA-256 file fingerprints, never a raw absolute source path or credential-bearing URL. Lifecycle operations share the registry lock; do not edit Package files or framework-owned state while one is running. Older Package and Kit state files are read for compatibility when canonical state is absent, without a write during normal boot.

At application boot, SqueHub validates enabled Packages, resolves their declared Package dependencies, then runs entry `register()` methods before entry `boot()` methods in deterministic dependency order. Missing, disabled, or broken requirements prevent activation. If a state-enabled Package becomes broken, normal Application boot fails safely. Discovery, `package:list`, and the `doctor` CLI use inspection mode so their Package reports remain available for repair without running Package PHP. Enabling a Package permits its PHP to execute during later application boot, so inspect third-party source before enabling it. State changes take effect in a fresh Application or process. Package `Utils/` files may define PHP globals, which cannot be unloaded from an already running process.

[SqueHub Dev](Dev.md) starts the ordinary PHP server and uses the same Application and Package activation state for web requests. It does not create Dev-specific enabled Packages, activate disabled Packages, or mask a broken enabled Package. Its Doctor preflight can inspect aggregate Package status without executing Package PHP; normal web boot still runs enabled Package code.

## Dependencies

An optional `composer.json` can declare exact Package dependencies:

```json
{
  "name": "example/commerce",
  "version": "1.0.0",
  "extra": {
    "squehub": {
      "requires": ["Payments"],
      "owner": "commerce-platform"
    }
  }
}
```

Dependencies name other SqueHub Packages with exact casing. SqueHub checks missing or disabled requirements, self-dependencies, and cycles. It never silently enables a dependency. Disabling or removing a Package required by an enabled Package is blocked. Shared dependencies register and boot once, in deterministic dependency order. The optional `owner` is a bounded static team/maintainer label; it does not grant privileges or affect activation. This initial dependency form does not resolve Composer version ranges. See [Large applications](LargeApplications.md) for diamond dependencies, ownership, and Package communication.

The dependency direction remains intentional: Package → Package and Kit → Package are supported; Package → Kit is prohibited. A Kit may require Payments as a reusable capability, but Payments must remain usable without that Kit. A reviewed Kit enable plan identifies required Package activation before the Kit changes state. Disabling or removing a Kit does not automatically disable or remove Packages it required. An installed Kit's declared requirement may continue to protect a Package from removal because Kit-published application files can remain after Kit disable. See [SqueHub Kits](Kits.md) for that ownership and disable boundary.

## Lifecycle commands

```bash
php squehub package:list
php squehub package:inspect Weather
php squehub package:inspect Weather --type=route
php squehub package:install <source> --preview
php squehub package:install <source> --yes
php squehub package:enable Weather --preview
php squehub package:enable Weather --yes
php squehub package:verify Weather
php squehub package:disable Weather --preview
php squehub package:disable Weather --yes
php squehub package:upgrade Weather <source> --preview
php squehub package:upgrade Weather <source> --yes
php squehub package:remove Weather --preview
php squehub package:remove Weather --yes
```

`package:list` and `package:inspect` report safe status and static owner metadata without executing Package PHP. `package:list` keeps one concise row per Package; `package:inspect` reports direct requirements and reverse dependents, including installed Kits that declare this Package as required. There is no separate `package:graph` command in this phase. `doctor` reports only aggregate installed, enabled, disabled, and broken counts; a broken Package fails Doctor. State-changing lifecycle commands and Doctor use an inspection-only Application bootstrap, so they remain available when an enabled Package cannot load. `package:verify` is the deliberate exception: it boots enabled Package code and loads route and Scheduler definitions to refresh one Package's provenance snapshot. Review source before verification. Lifecycle commands first render the shared [reviewable change plan](ReviewableChanges.md). `--preview` applies nothing; `--yes` deliberately applies. In a real interactive terminal, the command can ask for confirmation; non-interactive execution without `--yes` fails promptly. Lifecycle operations validate names, paths, metadata, dependencies, and conflicts before changing state. Applying install or upgrade stages the source and rechecks its fingerprints against the reviewed plan before publishing files. Before removal or upgrade deletes the previous tree, SqueHub rechecks the renamed tree against its owned-file snapshot; filesystem edits made outside the lifecycle lock can still race with the operation. If post-commit backup cleanup fails, the CLI reports that the change was applied and names the relative backup location for manual recovery. Local directory sources are supported for reproducible install and upgrade. Remote Git sources must use HTTPS without URL credentials, query strings, or fragments; lifecycle cloning uses an absolute empty hooks directory outside the checkout and does not run submodules or Composer scripts. Remote preview uses disposable system temporary checkout files but leaves the application project and Package state unchanged. The remote path has not been network-qualified on this host. No lifecycle command automatically runs migrations or Seeders.

Managed installation records its source label and fingerprints for Package-owned files. Upgrade compares the candidate with those fingerprints and refuses to overwrite a modified owned file. Removal refuses to delete a modified owned file. A manually copied Package has no recorded ownership, so remove or upgrade its files manually; it can still be validated and enabled. Removal also checks enabled dependents and filesystem containment before deletion. Back up Package data and application customizations separately: source removal does not roll back migrations or delete application data.

## Contributions

Enabled Package `Routes/` PHP files load through the existing Router in deterministic order. Disabled Package routes do not load. Bundled `.squehub.php` templates under `Views/` are available only from enabled Packages. An enabled Package named `Commerce` owns the exact `Commerce::` View namespace, with source under `Project/Packages/Commerce/Views/` and an optional application override under `Project/PackagesViews/Commerce/`. Ordinary unqualified names retain their existing `Project/Views/`, flat `Project/PackagesViews/`, then enabled Package-root precedence. An override or compiled template cannot activate a disabled Package. See [Routing](Routing.md), [Views](Views.md), and [Package and Namespaced Views](PackageViews.md).

Phase 22 adds an activation-aware path for an enabled Package's private `Assets/` files. A logical reference such as `asset('Commerce::js/app.mjs')` resolves to the framework-owned `/assets/Packages/Commerce/js/app.mjs` URL (with the application base path when mounted). The HTTP boundary serves a physical file from `Project/Packages/Commerce/Assets/` only while Commerce is active. It rejects unsafe paths, executable file extensions, links, and case aliases, and sends `no-store` so a disable does not leave a framework-cached response. This is deliberate runtime delivery, not a copy into `public/`; installing or enabling a Package does not publish public files. A Package asset request is handled only for GET or HEAD. Ordinary literal `asset('/assets/...')` URLs remain application-managed and are not interpreted as Package claims.

An entry may register middleware, services, or other supported runtime behavior through existing Application APIs. There is no automatic provider, listener, command, or `Config/` directory scan. Package code can add only its own `packages.<Name>.*` configuration defaults; replacing an existing value or setting another configuration namespace fails boot. In `register()`, check `$this->app->config()->has(...)` before setting a default, so explicit application configuration wins. Package `Scheduler/` definitions are loaded only for Scheduler commands when that Package is enabled. Package-local Migration and Seeder runners, static asset publication, and a general Package command contribution contract are not established here. Files in those directories are not executed or copied into `public/` by installing or enabling a Package. See [Scheduler](Scheduler.md), [Migrations](Migrations.md), and [Seeders](Seeders.md).

The [code generators](Generators.md) can create a Controller, Model, or Middleware inside an existing, valid, exactly cased Package with `--package=<PackageName>`. A generator writes one class file; it does not install or enable a Package. Package-local Migration and Seeder generation remains unsupported until their runners have an explicit discovery and execution contract.

The [SqueHub Feature Blueprint](FeatureBlueprints.md) command can plan related files for an existing Package:

```bash
php squehub make:feature Order --package=Commerce --preview
php squehub make:feature Order --package=Commerce --yes
```

Package Model, Controller, Validation rules class, API Resource, and `Routes/Features/Order.php` use the supported Package tree. This example defaults to table `commerce_order`, GET path `/commerce/order`, and route name `commerce.order.index`; `--table` and `--route` override the first two explicitly. The Blueprint places `create_commerce_order_table` under root `Database/Migrations/` and `CommerceOrderFeatureTest.php` under root `Tests/Integration/`, where existing runners can discover them, while attributing every planned action to `Package Commerce`. It does not create or activate a Package, change dependencies, run the Migration, or run a Seeder. Route loading still requires the Package to be enabled. The root Migration remains available to the migration runner even if the Package is disabled; inspect and run it only when appropriate for the application.

## Contribution ownership

SqueHub records observed Package-owned routes, middleware aliases, View namespace claims and selected views, Package configuration defaults, service bindings, and Scheduler definitions in one Application-owned provenance registry. The owner context follows each enabled Package's entry and `register()`/`boot()` hooks, plus its route and Scheduler files. Disabled Packages register none of these at runtime. Application routes are marked separately where their origin is known. Route metadata can include a declared controller class without loading or executing that controller. See [Contributions and provenance](Contributions.md) for category details and inspection boundaries.

`package:inspect Weather` reads only safe descriptor, activation state, fingerprint, and previously verified contribution metadata. It reports the explicit registry state, declared dependencies, and Package and Kit dependents. These requirements do not claim a causal history for why Weather was enabled. `--type=route` (also `middleware`, `view_namespace`, `view`, `config`, `service`, or `scheduler`) narrows the displayed records. If no snapshot exists, provenance is `unavailable`. A source edit or broken Package makes a saved snapshot `stale`. A disabled Package may retain historical records, but they are not described as active. `package:verify Weather` requires an enabled Package and refreshes its snapshot after trusted boot; it does not enable the Package or execute route handlers, views, or scheduled tasks.

The snapshot is stored with the Package's private Activation Registry record. It contains sorted, bounded structural metadata and a Package source fingerprint, never configuration values or runtime objects. Keep registry metadata outside the public document root. A managed upgrade changes source and requires another explicit verification. Static inspection remains side-effect free even if the Package entry is broken or malicious, because it does not include that PHP file.
