# Directory structure

The framework internals can be extensive; an application normally works mainly in `Project/`, `Config/`, `Database/`, and its needed `Assets/` files. Keeping most application PHP inside `Project/` makes it easier to identify developer-owned code when reviewing a framework upgrade or preparing an application export. The directory boundary helps with review; configuration, assets, dependencies, and data still need their own upgrade and backup plan. See [Backup and portability](BackupAndPortability.md).

The [code generators](Generators.md) create Controller, Model, and Middleware classes under `Project/`, and migrations and Seeders under `Database/`. They create files only; they do not run database changes or Package installation.

```text
App/                 Framework source and App/Plugins public gateway
Assets/              Shipped public asset sources
Bootstrap/           Application, web, and development-server bootstraps
Config/              Application configuration arrays
Database/
  Migrations/        Tracked schema changes
  Seeders/           Deliberate data setup
  Factories/         Repeatable model test/data construction
Docs/V2.x/           Local v2 documentation and verification notes
Project/
  Controllers/       Application controller classes
  Middleware/        Application middleware
  Models/            Application models
  Kits/              Kit definitions and lifecycle metadata
  Packages/          Installed application packages
  Routes/            Application route files
  Scheduler/         Recursive scheduled-task definition files
  Utils/             Application utilities
  Views/             Application .squehub.php templates
  PackagesViews/     Optional published package templates
public/              HTTP document root and index.php
Storage/             Runtime cache, logs, files, and Queue markers
Tests/               Framework test suite
vendor/              Composer dependencies
.example.env         Distributed environment template
.env                 Private application environment; never publish
squehub              CLI entry point
```

`Project/Kits/<KitName>/` holds a Kit definition. Files that a Kit publishes for the application live in the ordinary `Project/`, `Config/`, `Database/`, and `Tests/` locations, or under `public/assets/Kits/<KitName>/`, as identified in its reviewed plan; Kit PHP is not part of normal request boot. The private [Activation Registry](ActivationRegistry.md) may create `Project/Activation.json` after the first explicit Package/Kit lifecycle mutation; a tiny application needs no such file. See [SqueHub Kits](Kits.md). `Project/PackagesViews` may be absent until a package publishes templates. A package may also bundle `Views`, routes, Scheduler definitions, commands, and other resources under `Project/Packages/<PackageName>/`. `Project/Scheduler/` supports nested directories; the obsolete `Project/Schedule.php` is not loaded. See [Scheduler](Scheduler.md) and [Plugins](Plugins.md).

New code and directories follow the capitalized convention. Composer uses PSR-4 for `App\`, `Project\`, and migration, seeder, and factory namespaces. A bounded compatibility resolver accepts first-letter case variants for documented legacy paths. Standard tool-required filenames stay as written: `composer.json`, `.env`, `public/index.php`, and `squehub`. Use canonical spelling in new projects and on case-sensitive hosts.

Views resolve from `Project/Views`, then `Project/PackagesViews`, then bundled package `Views`. There is **no** repository-root `views/` fallback in v2. Runtime `Storage/` contents are generated and must be writable where used; `App/Storage` is framework source and must remain in the distribution. See [Views](Views.md), [Storage](Storage.md), and [Deployment](Deployment.md).
