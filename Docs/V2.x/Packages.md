# Packages and application extensions

A SqueHub Package is a reusable runtime capability under `Project/Packages/<PackageName>/`. Small applications can continue using ordinary `Project/` classes. [Large applications](LargeApplications.md) can introduce Package boundaries gradually; Packages are never required for ordinary controllers, routes, models, or views. [SqueHub Kits](Kits.md) compose broader application solutions in a separate lifecycle. `App\Plugins` is the framework API gateway, not a Package or Kit installation location.

## Installing Packages from an explicit source

```bash
php squehub package:install <source>
```

`<source>` identifies the Package definition you chose. SqueHub does not require Packagist discovery, a SqueHub Package registry, or a central Package account to install it. A website catalog can help you **discover** a Package and copy its supported source; discovery and installation are separate. Official, community, organization-private, and local development Packages follow the same review and activation lifecycle when supplied through a currently supported source. See [Kits](Kits.md#installing-kits-from-an-explicit-source) for the different Kit source boundary.

```text
developer supplies source
  -> SqueHub inspects a local tree or checks out an HTTPS Git repository
  -> static entry and optional composer.json inspection
  -> dependency, path, ownership, and Change Plan checks
  -> reviewed install into Project/Packages/<Name>/
  -> installed disabled
  -> inspect source and state; deliberately enable and verify
```

The current source resolver in `App\Packages\PackageManager::prepareSource()` accepts a physical local directory or a credential-free HTTPS Git repository URL. It has **no archive downloader or extractor**. The Package name comes from the local directory basename or the URL's last path component (with an optional `.git` suffix removed). That name must be an exact capitalized PHP identifier such as `Media`, and its root entry must be `Media.php` with a matching class. A repository with a lowercase last component such as `/media` cannot be installed directly through the present Git resolver, even if its contents use `Media` internally.

| Source form | Example | Current status | Use |
| --- | --- | --- | --- |
| Physical local directory | `./Media` or `D:\Packages\Media` | Supported | Local development, downloaded and inspected source, private source already available locally |
| Local ZIP/archive | `./Media-1.0.0.zip` | Unsupported | Extract and review into a correctly named `Media` directory first |
| HTTP(S) archive | `https://example.test/Media.zip` | Unsupported | Download and extract outside this installer, then use a local directory |
| GitHub release ZIP URL | `https://github.com/squehub/media/releases/download/v1.0.0/squehub-media-1.0.0.zip` | Unsupported; illustrative planned release | A release asset URL is an archive, not a Git repository source |
| GitHub repository URL | `https://github.com/acme/Media` | Supported by the HTTPS Git code path when the repository exists and has a matching `Media` root; live network success is not qualified by the current tests | Explicit repository checkout; this example is illustrative |
| HTTPS Git URL with `.git` | `https://git.example.test/team/Media.git` | Supported by the same Git code path, subject to a reachable repository and matching `Media` root | Explicit Git checkout; this example is illustrative |
| Git tag or branch selector | `?ref=v1.0.0`, `#v1.0.0`, or a version constraint | Unsupported | The installer has no ref argument or constraint resolver |
| `file://` URL | `file:///path/to/Media` | Unsupported | Pass the physical directory path instead |
| Authenticated/private remote URL | URL credentials, token query, or interactive Git prompt | Unsupported | Make the source available as a reviewed local directory |

The HTTPS Git path performs a shallow, single-branch clone of the remote's default branch into a disposable system temporary directory, with submodules disabled and an empty Git hooks directory. It does not run Composer scripts. It then inspects the checkout and copies reviewed files; `.git` is omitted from the installed Package. Preview can therefore contact the remote and create temporary checkout files, while leaving application Package files and state unchanged. Git must be installed for this path. The source URL must use HTTPS and have no user information, query string, or fragment. The CLI disables interactive Git prompts; authenticated private remotes are not handled by this installer. The current suite proves rejected credential-bearing URLs and local installation, but does not include a successful live Git-host clone, so qualify repository-host behavior in your environment before relying on it.

### Local installation and deliberate activation

Prepare a valid `Media/` directory outside the destination `Project/Packages/Media/`, review its PHP, then run:

```bash
php squehub package:install "./Media" --preview
php squehub package:install "./Media" --yes
php squehub package:inspect Media
php squehub package:list
php squehub package:enable Media --preview
php squehub package:enable Media --yes
php squehub package:verify Media
```

The same physical directory form works on Windows, for example `php squehub package:install "D:\Packages\Media" --preview`. The path may be outside the application root, but it must resolve to an ordinary, readable directory; linked or reparsed source entries are rejected. `--preview` displays the plan and applies nothing to the application. In a non-interactive shell, `--yes` is required to apply; a real interactive terminal can confirm the plan. `package:inspect` and `package:list` are static and do not include Package PHP. Enable permits the entry and enabled contributions to execute on later application boot. `package:verify` requires an enabled Package and deliberately boots enabled Package definitions, loads route and Scheduler definitions, and refreshes the contribution snapshot. It does not execute a route handler, render a View, or run a scheduled task. Review source before enabling or verifying.

**Installation is not activation or trust.** A managed install records ownership and installs the Package disabled by default. A manually copied Package is also discovered disabled, but lacks managed source ownership, so its source cannot be upgraded or removed through the managed lifecycle. Verification is separate from enabling: it records observed contributions after a trusted boot. Inspect the [contribution snapshot](Contributions.md) after verification.

### Repository sources, versions, and official examples

An HTTPS Git repository URL is a checkout source, not a request for a ZIP or a release asset. It follows the remote default branch at the time of each plan and apply; that branch can move, and there is no `--ref`, tag, branch, or version-constraint selector. Preview and apply clone again and compare fingerprints, so a change between them blocks a stale plan. For a production deployment that needs a specific revision, obtain and review that revision as a local `Media/` directory under your own release process, then install or upgrade from that directory. Record the upstream commit or archive digest outside SqueHub: the installer stores a safe source label and file fingerprints, not a Git commit pin or a verified archive checksum. Package `composer.json` version is display metadata, not a dependency or release constraint resolver.

The official organization is [`squehub`](https://github.com/squehub). `squehub/media` is a **planned illustrative** official Package repository in this guide, not a live installation instruction. Its lowercase `/media` path also fails the current Package URL identity rule. A future official release URL such as `https://github.com/squehub/media/releases/download/v1.0.0/squehub-media-1.0.0.zip` is an illustrative archive address only: `package:install` cannot consume it today. Likewise, a neutral community URL such as `https://github.com/acme/Media` is only a source-format example; the repository must exist, be reachable, and contain a valid `Media` Package before installation succeeds. An **Official** designation should be used only for real SqueHub-owned releases; other authors can distribute from their own supported sources without a registry.

To change a managed Package from another explicit source, keep the source directory basename and Package identity exactly the same. For example, put the next version under `./releases/v2/Media`:

```bash
php squehub package:upgrade Media "./releases/v2/Media" --preview
php squehub package:upgrade Media "./releases/v2/Media" --yes
php squehub package:verify Media
```

An upgrade checks the new source, current ownership fingerprints, dependencies, and reviewed plan before replacing the installed tree. Modified owned files, stale plans, mismatched identity, or dependent conflicts block the change. A changed Package source makes an earlier contribution snapshot stale, so verify again after a trusted enabled upgrade. `package:disable Media --yes` stops Package activation without deleting source; `package:remove Media --preview` and `package:remove Media --yes` remove only safely owned managed source after dependency and fingerprint checks. Package removal does not roll back Migrations or application data.

### Package source layout and metadata

The required manifest-like identity is the source directory plus its same-named PHP entry; **there is no required `squehub.package.json` file**. For example, a minimal valid source is:

```text
Media/
└── Media.php
```

```php
<?php

namespace Packages\Media;

use App\Plugins\ServiceProvider;

final class Media extends ServiceProvider
{
}
```

An optional root `composer.json` supplies metadata that SqueHub reads statically:

```json
{
  "name": "example/media",
  "version": "1.0.0",
  "extra": {
    "squehub": {
      "name": "Media",
      "owner": "Media Team",
      "requires": []
    }
  }
}
```

`extra.squehub.name`, when present, must match the directory identity. `extra.squehub.owner` is an optional display label, not an authorization grant. `extra.squehub.requires` is an optional list of exact Package names, not Composer version constraints. `version` is optional bounded text; SqueHub does **not** enforce SemVer or resolve version ranges for Packages. Routes, Views, Assets, and Scheduler definitions are convention-based optional directories described below, not `composer.json` manifest arrays. There is no installer field for ownership or contribution metadata: SqueHub records owned file fingerprints and observes runtime contributions through its activation/provenance registries.

### Source safety and troubleshooting

The installer validates canonical names and the Package entry, inspects metadata without including PHP, rejects linked/reparsed or nonportable source entries and case-colliding filenames, fingerprints reviewed files, and rechecks them while staging. The plan exposes conflicts before application. Ownership checks protect managed upgrades and removal from overwriting or deleting changed files. These checks reduce accidental and unreviewed changes; **third-party PHP is still third-party code**. SqueHub does not make malicious Package PHP safe. Review its entry, routes, definitions, and dependencies before activation.

| Symptom | Current cause or next check |
| --- | --- |
| `Local Package source is unavailable.` | The path does not resolve to a directory. Extract an archive first if needed. |
| `Package name must be an exact, capitalized PHP identifier.` | The source basename is invalid, commonly a lowercase repository slug or `.zip` filename. |
| `Expected Package entry file is missing.` | Put `<Name>.php` at the source root with the matching namespace/class. |
| `Package metadata is invalid JSON.` | Correct the optional root `composer.json`. |
| `Git Package source must be a credential-free HTTPS URL.` | Remove URL credentials, query, or fragment; use a local directory for private source. |
| `Git Package source could not be inspected.` | Check Git availability, network access, repository reachability, and clone permission. |
| `Package name already exists or conflicts by casing.` | Use `package:upgrade <Name> <source>` for a managed installed Package. |
| A plan has conflicts | Resolve ownership, dependency, unsafe-path, or changed-file issues and preview again. |

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
