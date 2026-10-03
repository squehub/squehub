# Application ownership, backups, and portability

SqueHub keeps most application PHP in `Project/` so developers can identify their own code when reviewing a framework upgrade or moving an application. Framework internals live primarily in `App/`. The boundary makes changes easier to compare; an upgrade may still require application code, configuration, schema, and dependency changes. The canonical directory name is singular: `Project/`.

## What belongs to an application

| Location | Application-owned material to review or preserve |
| --- | --- |
| `Project/` | Routes, controllers, middleware, models, views, package code, Scheduler definitions, and application utilities. |
| `Assets/` | Assets the application needs to serve or rebuild, including its styles, scripts, and images. |
| `Config/` | Application configuration and any deliberate overrides. Review these for compatibility during upgrades. |
| `Database/Migrations/` | Schema changes made for the application. |
| `Database/Seeders/` | Deliberate setup or reference data definitions; these are not a copy of live records. |
| `Database/Factories/` | Factory definitions used for test or deliberate application data construction. |
| `composer.json`, `composer.lock` | Dependency declarations and the selected dependency versions needed for a reproducible install. |

Published package resources may live outside their package directory; include the application-owned copies where they are used. Check [Directory structure](DirectoryStructure.md), [Packages](Packages.md), [Migrations](Migrations.md), and [Seeders](Seeders.md) when making an inventory. `vendor/` is normally recreated from Composer metadata rather than copied as application source.

## What `backup:dev` does today

The existing development command is:

```bash
php squehub backup:dev
```

It requires the PHP CLI `zip` extension and writes a ZIP under `Storage/Backups/Dev/`. When present, it archives `Project/`, `Config/`, `Database/`, `.env`, and the legacy root `config.php`. It does **not** include `Assets/`, Composer metadata or lockfile, live database records, or runtime uploads. Archiving migration and Seeder files does not archive the database contents they may have created.

Because the ZIP can contain `.env`, it can contain `APP_KEY` and infrastructure credentials. Keep it private and apply an appropriate retention policy. `backup:dev` is neither the portable export/import tool nor a complete production recovery backup. See [CLI](Cli.md) for the command inventory.

## Portable project export and import

Phase 21A adds the [portable project bundle](ProjectBundles.md). `bundle:export` writes a bounded `.sqhb` source archive containing canonical `Project/`, relevant `Assets/` and `public/assets/`, `Config/`, project Migration, Seeder and Factory source, and Composer metadata where present. Its versioned manifest records exact relative paths, casing, sizes, checksums, and an activation summary. It excludes `.env`, private key files, runtime Cache, logs, Queue state, compiled views, `vendor/`, and development backups by path policy. Application PHP or configuration can still contain a hard-coded secret; review source before sharing.

`bundle:inspect` checks the full archive without extraction. `bundle:import --preview` produces a [Change Plan](ReviewableChanges.md) and detects unsafe paths, casing and ownership conflicts without writing the target. A deliberate import is a separate operation; it does not execute Composer, imported PHP, migrations, Seeders, Package or Kit hooks. Source integrity is not publisher authentication. See [Project bundles](ProjectBundles.md) for exact commands, limits, and partial-apply behavior.

For an explicit local target source tree, `upgrade:check <target>` gives a read-only [upgrade preflight](UpgradePreflight.md) with compatible, review, blocked, or unknown status. It compares static Composer, Package/Kit, activation, configuration and file evidence without applying an upgrade. A changed PHP configuration file or an unsupported compatibility constraint remains unknown until reviewed; preflight does not claim a migration is safe by filename.

## Full recovery needs data and persistent files

A source bundle can rebuild code and schema definitions. Recovering an operating application also requires a consistent snapshot of **live database records** and its persistent uploads or other selected application files, such as content under `Storage/Files`. Neither migration files nor Seeders substitute for user data. The database snapshot must be made with a method appropriate to the selected database and coordinated with writes so the restored data is consistent.

Keep deployment secrets separate from the ordinary source bundle. Recovery of encrypted data requires the correct historical `APP_KEY` or other key material; generating a fresh key does not decrypt existing ciphertext. Restore only to an intended destination, inspect the proposed file and database changes, and rehearse the process in a disposable environment before relying on it. External services may hold additional state that needs its own recovery plan.

Inventory and back up each required component explicitly. The read-only [recovery plan](Recovery.md) classifies source, operator-provided database artifacts, uploads, secrets, sessions, and Queue work; it does not capture or restore them. Do not treat `backup:dev` or a source bundle as proof that an application can be restored. Phase 21A–21E passed Windows and user-run native Linux/WSL qualification, including cross-platform bundle checksum and inspection checks; the clean-checkout release gate remains open. See [Deployment](Deployment.md), [Upgrade from v1.x](UpgradeFromV1.md), and [Cryptography](Cryptography.md).
