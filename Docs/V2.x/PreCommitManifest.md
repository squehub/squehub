# SqueHub v2 pre-commit source manifest

This inventory records the historical Phase 8E working tree. Nothing was staged by that audit. It is **not** the Phase 28 release manifest: the local `release/v2.0.0` candidate now tracks source, `Docs/`, `Tests/`, and the lock file in a cleanly cloned commit. Its historical Dumper path mappings are superseded by Phase 13A's v2 retirement. See [Seeders](Seeders.md) and [Release readiness](ReleaseReadiness.md).

## Include as source

- Canonical App, Assets, Bootstrap, Config, Database, Project, and Scripts trees. At this Phase 8E checkpoint, `Docs/` and `Tests/` were local and ignored by Git; Phase 28 changed that policy.
- Root entry points and compatibility bridges, public/index.php, squehub, composer.json, composer.lock, README.md, and .example.env. The PHPUnit configuration was local with the ignored test suite at that checkpoint; the release candidate tracks `phpunit.xml.dist`.
- Capitalized files that replace old lowercase tracked paths, and intentional tracked deletions.

## Keep generated state out

- .env, vendor, root Storage runtime contents, compiled views, logs, cache entries, backups, test/coverage output, IDE files, and project-backup.
- Runtime Logs, Cache, and Files directories are created lazily and need no committed placeholder.

## Git index casing migrations

At the Phase 8E checkpoint the Windows index had 45 tracked spellings whose physical working-tree paths differed. Phase 28 resolved casefold collisions before its local candidate commit and verified the checkout on a native case-sensitive Linux filesystem. Four old starter application files had been removed after this inventory was first written.

- `app/Clis/Make/MakeController.php` → `App/Clis/Make/MakeController.php`
- `app/Clis/Make/MakeDumper.php` → `App/Clis/Make/MakeDumper.php`
- `app/Clis/Make/MakeMiddleware.php` → `App/Clis/Make/MakeMiddleware.php`
- `app/Clis/Make/MakeMigration.php` → `App/Clis/Make/MakeMigration.php`
- `app/Clis/Make/MakeModel.php` → `App/Clis/Make/MakeModel.php`
- `app/Clis/clis.php` → `App/Clis/Clis.php`
- `app/Clis/dump/dump.php` → `App/Clis/Dump/Dump.php`
- `app/Components/ControlStructuresComponent.php` → `App/Components/ControlStructuresComponent.php`
- `app/Components/DateTimeComponent.php` → `App/Components/DateTimeComponent.php`
- `app/Components/Notification.php` → `App/Components/Notification.php`
- `app/Core/Controller.php` → `App/Core/Controller.php`
- `app/Core/Database.php` → `App/Core/Database.php`
- `app/Core/Dumper.php` → `App/Core/Dumper.php`
- `app/Core/Exceptions/CustomPrettyPageHandler.php` → `App/Core/Exceptions/CustomPrettyPageHandler.php`
- `app/Core/Exceptions/Debug.php` → `App/Core/Exceptions/Debug.php`
- `app/Core/Helper.php` → `App/Core/Helper.php`
- `app/Core/Mail.php` → `App/Core/Mail.php`
- `app/Core/MiddlewareHandler.php` → `App/Core/MiddlewareHandler.php`
- `app/Core/Model.php` → `App/Core/Model.php`
- `app/Core/Notification.php` → `App/Core/Notification.php`
- `app/Core/Service.php` → `App/Core/Service.php`
- `app/Core/Task.php` → `App/Core/Task.php`
- `app/Core/Verification.php` → `App/Core/Verification.php`
- `app/Core/View.php` → `App/Core/View.php`
- `assets/css/styles.css` → `Assets/Css/Styles.css`
- `assets/default/favicon/android-chrome-192x192.png` → `Assets/Default/Favicon/android-chrome-192x192.png`
- `assets/default/favicon/android-chrome-512x512.png` → `Assets/Default/Favicon/android-chrome-512x512.png`
- `assets/default/favicon/apple-touch-icon.png` → `Assets/Default/Favicon/apple-touch-icon.png`
- `assets/default/favicon/favicon-16x16.png` → `Assets/Default/Favicon/favicon-16x16.png`
- `assets/default/favicon/favicon-32x32.png` → `Assets/Default/Favicon/favicon-32x32.png`
- `assets/default/favicon/favicon.ico` → `Assets/Default/Favicon/favicon.ico`
- `assets/default/favicon/icon.png` → `Assets/Default/Favicon/Icon.png`
- `assets/default/favicon/squehub-icon.png` → `Assets/Default/Favicon/squehub-icon.png`
- `assets/default/favicon/site.webmanifest` → `Assets/Default/Favicon/site.webmanifest`
- `assets/default/img/logo-icon.png` → `Assets/Default/Img/Logo-icon.png`
- `assets/default/img/squehub-icon.png` → `Assets/Default/Img/squehub-icon.png`
- `assets/img/logo-icon.png` → `Assets/Img/Logo-icon.png`
- `assets/img/squehub-icon.png` → `Assets/Img/squehub-icon.png`
- `config/debug.php` → `Config/Debug.php`
- `config/mail.php` → `Config/Mail.php`
- `database/dumper/SampleDumper.php` → `Database/Dumper/SampleDumper.php`
- `database/migrations/2025_07_12_create_sample_table.php` → `Database/Migrations/2025_07_12_create_sample_table.php`
- `project/Packages/index.php` → `Project/Packages/Index.php`
- `project/Routes/web.php` → `Project/Routes/Web.php`
- `project/Utils/index.php` → `Project/Utils/Index.php`
- `project/Views/Index.php` → `Project/Views/Index.php`
- `scripts/message` → `Scripts/Message`

## True removals

The v1 `app/Routes/web.php` and its transitional `App/Routes/Web.php` placeholder have no v2 replacement under `App/`. Application routes belong in `Project/Routes/`; enabled Packages contribute their own `Routes/` files.

These six tracked v1 root view files have no physical replacement at their old path. The supported v2 view roots are Project/Views, Project/PackagesViews, and package-bundled Views.
- `views/default/error/404.php`
- `views/default/error/404.squehub.php`
- `views/default/error/500.php`
- `views/default/error/500.squehub.php`
- `views/home/welcome.squehub.php`
- `views/home/welcome2.squehub.php`

Four unused starter application classes were retired. Their directories now contain `readme.md` guidance for v2 code:

- `project/Controllers/SampleController.php`
- `project/Models/SamlpeModel.php`
- `project/Middleware/AuthMiddleware.php`
- `project/Middleware/SampleMiddlware.php`

## Application-facing Plugins import

This guide covers a subsystem or maintenance workflow; see [Plugins](Plugins.md) for supported application imports. Canonical framework namespaces remain supported.
