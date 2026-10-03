# SqueHub Kits

SqueHub Kits compose an application solution from reusable [Packages](Packages.md) and ordinary application files. **Packages build capabilities. Kits build solutions.** A Package can contribute runtime services, routes, and views while enabled; a Kit is a deliberately managed definition that may publish controllers, routes, views, configuration, assets, Migrations, and Seeders into their normal application locations. An enabled Kit is not a second per-request Package runtime.

This guide describes the v2.0.0 Kit lifecycle. Review a Kit's source before installing or applying it: a manifest and a Change Plan make framework-managed file effects visible, but neither sandboxes executable Kit or generated application PHP.

## Installing Kits from an explicit source

```bash
php squehub kit:install <source>
```

`<source>` is an explicit Kit definition directory supplied by the developer. Installing does not require a SqueHub Kit registry, a central account, or Packagist discovery. A future website catalog can help developers find official or community Kits and copy a supported source; discovery and installation are separate. Local private organization source follows the same lifecycle. The source boundary for Kits is narrower than for [Packages](Packages.md#installing-packages-from-an-explicit-source): `App\Kits\KitManager::localSource()` currently accepts only an ordinary local directory.

```text
developer supplies local Kit directory
  -> SqueHub inspects kit.json, the entry shape, and source files
  -> source-path and definition Change Plan checks
  -> reviewed install of the definition into Project/Kits/<Name>/
  -> installed disabled; no application files published
  -> enable plan checks required Packages, mapped files, target ownership, and conflicts
  -> inspect state and planned composition; deliberately enable
```

The last step is important: applying a Kit install or another lifecycle action **can execute declared Kit hooks** from reviewed source. Static inspection and `--preview` do not execute them. A disabled installed Kit does not publish its `files` mappings or run as a per-request Package. Installing the definition is therefore distinct from enabling its composition, but approval of an install is still a trust decision when the manifest declares install hooks.

| Source form | Example | Current status | Use |
| --- | --- | --- | --- |
| Physical local directory | `./AppStarter` or `D:\Kits\AppStarter` | Supported | Local development, inspected downloaded source, private source already local |
| Local ZIP/archive | `./AppStarter-1.0.0.zip` | Unsupported | Extract and review into an `AppStarter` directory first |
| HTTP(S) archive | `https://example.test/AppStarter.zip` | Unsupported | Download and extract outside the installer, then use a local directory |
| GitHub release ZIP URL | `https://github.com/squehub/app-starter/releases/download/v1.0.0/squehub-app-starter-1.0.0.zip` | Unsupported; illustrative planned release | Archive extraction is outside the Kit installer |
| GitHub repository URL | `https://github.com/acme/AppStarter` | Unsupported | Obtain the repository as a local, correctly named directory first |
| HTTPS Git URL, tag, or branch | `https://git.example.test/team/AppStarter.git` | Unsupported | Kit has no Git clone or ref selector |
| `file://` URL | `file:///path/to/AppStarter` | Unsupported | Pass a physical directory path |
| Authenticated/private remote URL | Token, credentials, or private HTTPS source | Unsupported | Make the source available through a reviewed local directory |

This installer does not download, redirect, extract, validate archive entries, check archive checksums, or resolve Git refs. Those concerns belong to the external acquisition step when using an archive or repository. Do not pass an archive URL or ZIP file to `kit:install` and expect extraction. The source directory basename must be an exact capitalized Kit identifier matching `kit.json` and `<Name>.php`; linked/reparsed or nonportable source trees are rejected.

### Local installation, inspection, and activation

For a valid `AppStarter/` source outside the destination `Project/Kits/AppStarter/`:

```bash
php squehub kit:install "./AppStarter" --preview
php squehub kit:install "./AppStarter" --yes
php squehub kit:inspect AppStarter
php squehub kit:list
php squehub kit:enable AppStarter --preview
php squehub kit:enable AppStarter --yes
```

On Windows, a physical path such as `php squehub kit:install "D:\Kits\AppStarter" --preview` is also accepted. `--preview` leaves application files, activation state, and hooks untouched. Applying with `--yes` is the non-interactive route; a real interactive terminal can confirm the plan. Inspect the `kit:enable` plan for required Package activation, target collisions, generated files, and hook warnings before applying. The Kit has no `kit:verify` command. Test the resulting ordinary application routes, classes, and views with your application's tests after enable.

For a managed upgrade, stage a new definition in another parent while retaining the exact `AppStarter` basename:

```bash
php squehub kit:upgrade AppStarter "./releases/v2/AppStarter" --preview
php squehub kit:upgrade AppStarter "./releases/v2/AppStarter" --yes
php squehub kit:inspect AppStarter
```

The preview compares the old definition and published-file fingerprints with the new explicit source and reviews Package requirements, collisions, and hooks. A disabled Kit upgrades its definition but leaves prior published application files until a later reviewed enable. `kit:disable AppStarter --yes` stops Kit activation state without removing files it already published; ordinary routes and other application files can still run. `kit:remove AppStarter --preview` and `kit:remove AppStarter --yes` attempt to remove safely owned definition and published files. Modified or unowned files block removal; Kit-owned Migrations are preserved and block their replacement or removal rather than guessing database history. Disabling or removing a Kit does not automatically disable required Packages or roll back data.

### Versions, official sources, and private Kits

The `version` in `kit.json` must use the bounded `major.minor.patch` form, optionally with a prerelease suffix. It describes the Kit definition; SqueHub has no remote version-constraint resolver. Choose and acquire a specific source revision yourself, then present its extracted local directory to `kit:install` or `kit:upgrade`. Keep any upstream commit or archive digest in your release records; SqueHub fingerprints owned files but does not verify an external archive checksum.

The official organization is [`squehub`](https://github.com/squehub). `squehub/app-starter` and a future URL such as `https://github.com/squehub/app-starter/releases/download/v1.0.0/squehub-app-starter-1.0.0.zip` are **planned illustrative** examples here, not live Kit installation commands. Even after a release exists, the current Kit installer needs a reviewed local `AppStarter/` directory. Treat an **Official** designation as appropriate only for a real SqueHub-owned Kit; independently hosted community Kits can use the same local source lifecycle. Authenticated remote fetching is outside the Kit installer. Acquire a private Kit through your organization's normal authenticated process, review it locally, and pass its physical directory path; do not put tokens in command-line URLs.

### Source safety and troubleshooting

Static inspection reads a bounded `kit.json` and checks the expected entry shape without including Kit PHP. Source and destination checks reject traversal, absolute or drive paths in mappings, linked/reparsed entries, case collisions, unsupported application targets, stale fingerprints, and ownership conflicts. There is no Kit archive extractor, so archive traversal protection is not an installation feature. These checks protect framework-managed file operations; **third-party Kit hooks and published PHP remain executable code**. Review both before applying an install or enable plan. See [Security](Security.md) and [reviewable changes](ReviewableChanges.md).

| Symptom | Current cause or next check |
| --- | --- |
| `Kit source must be a safe local directory.` | Pass a physical directory path, not a URL or linked source. |
| `Local Kit source is unavailable.` | Check that the directory exists and is readable; extract archives first. |
| `Kit name must be an exact, capitalized PHP identifier.` | Use a matching capitalized source basename, `kit.json` name, and entry. |
| `Kit manifest is missing, too large, or unavailable.` | Put a readable `kit.json` at the source root. |
| `Kit manifest fields are invalid.` | Use only required `format`, `name`, `version` and optional `requires`, `files`, `hooks`. |
| `Kit file mapping targets an unsupported application location.` | Review the strict target allowlist below. |
| A plan has conflicts | Resolve target ownership, modified files, Package requirements, or unsafe paths and preview again. |

### Kit and Package roles

| | Package | Kit |
| --- | --- | --- |
| Purpose | Reusable runtime capability | Application composition and starter structure |
| Installed source | `Project/Packages/<Name>/` | `Project/Kits/<Name>/` definition |
| Main effect after enable | Entry and enabled contributions participate in application boot | Reviewed files are published into normal application locations; no per-request Kit entry |
| Manifest | Same-named PHP entry; optional `composer.json` metadata | Required strict `kit.json` plus same-named Kit entry |
| Source forms today | Local directory or credential-free HTTPS Git checkout | Local directory only |
| Ownership | Managed Package source files and contribution snapshot | Managed Kit definition and published application files |
| Upgrade | Explicit replacement source with Package ownership checks | Explicit local replacement with definition, published-file, and conflict checks |

A Package adds a capability such as Media; a Kit composes application files and may require Packages. Kit-to-Package requirements are explicit. A Package cannot require a Kit.

## Definition and application files

The canonical definition lives under `Project/Kits/<KitName>/`:

```text
Project/Kits/Ecommerce/
├── Ecommerce.php
├── kit.json
├── Templates/
├── Config/
└── Assets/
```

`kit.json` is the non-executable, versioned metadata source. A minimal useful manifest is:

```json
{
  "format": 1,
  "name": "Ecommerce",
  "version": "1.0.0",
  "requires": ["Payments"],
  "files": [
    {
      "source": "Templates/ShopController.php",
      "target": "Project/Controllers/ShopController.php"
    },
    {
      "source": "Config/Shop.php",
      "target": "Config/Shop.php"
    },
    {
      "source": "Assets/storefront.css",
      "target": "public/assets/Kits/Ecommerce/storefront.css"
    }
  ],
  "hooks": ["beforeEnable"]
}
```

`format` describes the manifest format and is currently `1`; `version` is the Kit release identifier, not that format. Versions use a bounded `major.minor.patch` spelling with an optional prerelease suffix. `requires`, `files`, and `hooks` may be empty or omitted. Manifest fields are strict: an unknown field, duplicate or case-colliding source/target, invalid path, unsupported target, oversized manifest, or invalid Kit identity is an error. Requirements use exact Package names, and `files` are explicit source-to-target mappings. Template sources can publish application classes, routes, views, Migrations, Seeders, and tests; `Config/` sources target `Config/` PHP files, and `Assets/` sources target the Kit's own `public/assets/Kits/<KitName>/` namespace. The manifest carries paths and metadata, never secret values.

The current `files` mapping allowlist is precise:

| Source prefix | Supported target |
| --- | --- |
| `Templates/` | Files below `Project/Controllers/`, `Project/Middleware/`, `Project/Models/`, `Project/Routes/`, `Project/Views/`, `Project/Scheduler/`, `Project/Validation/`, `Project/Api/`, `Project/Services/`, or `Project/Utils/` |
| `Templates/` | PHP files under `Database/Migrations/`, `Database/Seeders/`, or `Tests/` |
| `Config/` | `Config/<Name>.php` |
| `Assets/` | An allowed static asset extension under `public/assets/Kits/<KitName>/` |

Targets are application-relative slash paths, never absolute or traversal paths. Mapping collisions and modified files are conflicts, not merge requests. `kit.json` does not contain file ownership hashes or conflict operations: the lifecycle records fingerprints and derives those plan actions from actual source and target state. Published file bytes are bounded during apply. There is no implicit scan that publishes every file under `Templates/`, `Config/`, or `Assets/`; only listed mappings are published.

Publication copies the declared files to the reviewed destination. A Config mapping does not merge into an existing configuration file; a collision must be resolved first. Assets are static files under the public Kit namespace, not a frontend build pipeline. Publishing them does not require Node.js. Template mapping in this phase does not invent application names, business rules, or a general template language.

`Ecommerce.php` is the deliberate lifecycle entry, not a ServiceProvider. It does not provide Package-style `register()` or `boot()` hooks and is not loaded on normal HTTP, CLI, or Queue application boot. Kit authors extend the `App\Plugins\Kit` gateway and declare only the hooks listed in the manifest:

```php
<?php

declare(strict_types=1);

namespace Project\Kits\Ecommerce;

use App\Plugins\Kit;
use App\Plugins\KitContext;

final class Ecommerce extends Kit
{
    public function beforeEnable(KitContext $context): void
    {
        // Trusted application preparation for this explicit lifecycle action.
    }
}
```

The context supplies only `operation`, `name`, and `version`; it does not expose `.env` or credentials. The entry must be exactly `Project\Kits\Ecommerce\Ecommerce` in `Ecommerce.php` and extend the public `Kit` gateway. The files published by a Kit go to normal application paths rather than forming a second runtime tree under `Project/Kits/Ecommerce/Controllers/`:

```text
Project/Controllers/ShopController.php
Project/Routes/Shop.php
Project/Views/Shop/Index.squehub.php
Project/Kits/Ecommerce/kit.json
```

Kit and directory names use canonical casing on Windows and Linux. Treat `Ecommerce` and `ecommerce` as the same logical identity, not as two Kits. Standard required filenames such as `kit.json` keep their specified spelling.

## Lifecycle and state

Installation and activation are separate. A Kit can be absent, installed and disabled, installed and enabled, or broken. Static discovery can also find a manually copied definition that has not been acquired as a managed installation. An installed Kit does not silently enable its required Packages or publish its application composition.

The [SqueHub Activation Registry](ActivationRegistry.md) stores Kit and Package activation together in private, versioned metadata. Kit records retain source kind, last-known Package requirements, and owned-file fingerprints needed for safe lifecycle changes. The registry has one lock for cooperating lifecycle operations. It is application source metadata, not a database table, Redis record, or public interchange format; do not edit it by hand. Legacy `Project/Kits/State.json` is read for compatibility when canonical registry state does not yet exist.

```bash
php squehub kit:list
php squehub kit:inspect Ecommerce
php squehub kit:install ./Ecommerce --preview
php squehub kit:install ./Ecommerce --yes
php squehub kit:enable Ecommerce --preview
php squehub kit:enable Ecommerce --yes
php squehub kit:disable Ecommerce --preview
php squehub kit:disable Ecommerce --yes
php squehub kit:upgrade Ecommerce ./Ecommerce-v2 --preview
php squehub kit:upgrade Ecommerce ./Ecommerce-v2 --yes
php squehub kit:remove Ecommerce --preview
php squehub kit:remove Ecommerce --yes
```

`kit:list` and `kit:inspect` examine metadata and saved state. They do not include the Kit entry PHP, execute hooks, boot Packages, run Migrations or Seeders, or connect to a database. A malformed or broken Kit remains inspectable where its metadata can be read. A manual copy may be inspected and deliberately adopted/enabled, but its source files are not automatically owned for managed upgrade and removal.

`kit:install` and `kit:upgrade` accept a local directory source in this foundation. Remote Git installation and archive extraction are deferred. A source can contain executable PHP; review it before applying a lifecycle plan.

For a manually copied Kit, place its complete definition at `Project/Kits/Ecommerce/`, then run `kit:list` and `kit:inspect Ecommerce` before `kit:enable Ecommerce --preview`. Manual discovery does not mean SqueHub acquired or owns those definition files. A deliberate enable can record activation and the files it publishes, but managed upgrade/removal cannot assume ownership of the manually copied source. Keep manual source changes under your application's own version control and review them separately.

`kit:install` validates and acquires a Kit definition, leaving it disabled. `kit:enable` reviews its requirements and intended published files before activation. Applying an enable plan can place generated or published files in ordinary application locations and mark the Kit enabled. `kit:disable` changes the Kit's managed activation state but **does not delete already published application files, assets, Migrations, or Seeders**. Those files can still affect the application through normal discovery and routing; disabling a Kit cannot promise to turn off every behavior it created. `kit:remove` is the deliberate, reviewed operation for removing Kit-owned files that are still safe to remove.

Use `--preview` to inspect without applying a change. A non-interactive apply requires `--yes`; an interactive terminal may ask for confirmation. A preview reports framework-managed effects, not all effects arbitrary trusted hook PHP might perform. Apply plans use the same [reviewable change](ReviewableChanges.md) vocabulary as generators and Package lifecycle, including conflicts and stale-state checks. A failed filesystem operation may have partial effects; inspect the reported result and recovery information rather than assuming every prior action was rolled back.

`php squehub doctor` reports aggregate installed, enabled, disabled, and broken Kit counts from static metadata. Broken Kit definitions remain visible in `kit:list` and `kit:inspect` for repair. Doctor does not execute Kit entry PHP or hooks and does not fix ownership or missing dependencies. A disabled Kit is not an error by itself. See [Health and Doctor](Health.md).

## Required Packages

A Kit may require Packages, for example an Ecommerce Kit requiring `Orders`, `Payments`, and `Inventory`. Package-to-Package and Kit-to-Package dependencies are supported; a runtime Package does not depend on a Kit. Kit-to-Kit dependencies are outside this foundation.

An absent required Package is a conflict unless its source can be supplied through an explicitly supported, reviewed path. An installed but disabled requirement must be enabled deliberately through a visible state action. Kit code must not rewrite activation state directly. The registry validates Package requirements and commits reviewed Package and Kit activation together.

Disabling or removing a Kit does not automatically disable or remove a required Package. A Package may be shared by two Kits or by application code. SqueHub protects a required Package conservatively while an installed Kit still declares it; remove that Kit through its own reviewed lifecycle before removing the shared capability. The registry combines dependency inspection, while only the Package dependency graph supplies runtime boot order. A Kit never boots as a Package.

## Ownership, generated files, and upgrades

SqueHub records ownership for managed Kit definitions and files it publishes. Fingerprints distinguish unchanged owned files from files the application developer has modified. Upgrade and removal must not overwrite or delete modified or unowned application files silently. A target collision, unsafe path, unexpected file change, or stale plan blocks apply and calls for a fresh review.

Published controllers, routes, views, configuration, assets, Migrations, and Seeders are normal application files. The Kit state records which published paths it owns. When a `Project/Routes/` file owned by a Kit loads, the existing contribution registry may attribute its route declarations to `kit:<Name>` as **file provenance**, even after the Kit is disabled. The route PHP still loads through normal Project routing; SqueHub does not execute the Kit entry class to register it. Other generated files do not acquire a separate Kit runtime merely because Kit state names their origin. A manually copied Kit may be discoverable without giving SqueHub permission to delete its source later.

An upgrade may add or remove required Packages and may add, replace, or retire published files for an enabled Kit. A Kit that has never been enabled can upgrade its definition while remaining disabled, without publishing its new composition. Review application file changes independently. An old owned file should only be removed if its recorded bytes and current ownership still match. A developer-modified file needs explicit handling; SqueHub does not discard it to complete an upgrade. Kit-owned Migration replacement or deletion is blocked rather than guessing whether a Migration has run. [Contributions and provenance](Contributions.md) explains the distinction between file ownership and observed runtime registrations.

## Migrations and Seeders

A Kit may publish Migration and Seeder files, but Kit install, enable, upgrade, disable, and remove do not run them. Review the generated PHP and target database separately, then use the existing Migration and Seeder commands deliberately. Upgrade and removal conservatively block overwriting or deleting Kit-owned Migration files, even when their bytes match, because preview does not query migration status or touch the database. A Kit with such files may need explicit manual database and source migration work before a later removal can proceed. Kit removal never rolls back an applied Migration or erases application data. Seeder execution and rollback remain separate explicit commands.

## Lifecycle hooks and trust

Kit authors may provide explicit lifecycle hooks for deliberate operations. The base class defines `beforeInstall` / `afterInstall`, `beforeEnable` / `afterEnable`, `beforeDisable` / `afterDisable`, `beforeUpgrade` / `afterUpgrade`, and `beforeRemove` / `afterRemove`, each accepting `KitContext` and returning `void`. Declare the hooks you intend to use in the manifest's `hooks` list. There is no Package-style per-request `register()` or `boot()` on a Kit. Preview can identify that a hook would execute but **never executes hook PHP**. Before/after hooks run only during the corresponding approved lifecycle operation. If a before hook fails, framework-managed changes should not begin where feasible; an after-hook failure may leave applied changes and must be reported as such.

**Hooks are executable trusted application code, not a sandboxed extension language.** They may have side effects outside the framework-managed Change Plan. A plan cannot prove arbitrary PHP code safe or automatically reverse it. Do not store secrets in `kit.json`, file mappings, state, plan output, or diagnostics. Review third-party Kit sources and generated files before applying them.

Kit source and destination paths are restricted to supported, application-relative locations. Traversal, absolute/drive/UNC paths, linked or nonportable source entries, unsafe destination ancestors, and case-only collisions are rejected rather than resolved through a guessed filesystem spelling. These checks protect the framework-managed file operations; they do not establish trust in arbitrary PHP that a Kit or its generated application code contains.

## Testing and nearby tools

Use [SqueHub testing helpers](Testing.md) with a disposable Application to exercise the normal route/controller/view behavior after a Kit has published its files. Tests should explicitly prepare required Packages and use a disposable database when running Migrations or Seeders. `kit:list`, `kit:inspect`, and Doctor are static inspections, not behavioral tests of generated application code.

[SqueHub Setup](Setup.md) does not choose or install Kits. [SqueHub Dev](Dev.md) starts the normal registry-driven Package runtime without running Kit hooks. Contract export may see ordinary published routes when they load, but has no separate Kit contract or runtime. The local-only [SqueHub Studio](Studio.md) and optional [Agent/MCP integration](AgentAndAI.md) show bounded Kit activation metadata without executing Kit hooks or changing state. [Application Profiles](FrontendProfiles.md) are also implemented in the development tree; see [v2 status](Status.md) for qualification and release boundaries.
