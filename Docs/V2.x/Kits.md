# SqueHub Kits

SqueHub Kits compose an application solution from reusable [Packages](Packages.md) and ordinary application files. **Packages build capabilities. Kits build solutions.** A Package can contribute runtime services, routes, and views while enabled; a Kit is a deliberately managed definition that may publish controllers, routes, views, configuration, assets, Migrations, and Seeders into their normal application locations. An enabled Kit is not a second per-request Package runtime.

This guide describes the v2.0.0 development working tree, not a published stable release. Review a Kit's source before installing or applying it: a manifest and a Change Plan make framework-managed file effects visible, but neither sandboxes executable Kit or generated application PHP.

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
