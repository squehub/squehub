# Portable project bundles

SqueHub's project bundle moves **application source** between project directories. It is separate from `backup:dev`: the development backup can include `.env` and needs PHP `zip`, while a project bundle deliberately excludes deployment secrets and uses SqueHub's bounded `.sqhb` format. A bundle is not a database backup or a complete recovery point.

## Export, inspect, import

Run these commands from the source SqueHub application. The export destination must be a new file; its parent directory must already exist.

```bash
php squehub bundle:export ../myapp.sqhb
php squehub bundle:inspect ../myapp.sqhb
php squehub bundle:inspect ../myapp.sqhb --json
php squehub bundle:import ../myapp.sqhb ../restored-app --preview
php squehub bundle:import ../myapp.sqhb ../restored-app --yes
```

`bundle:inspect` validates the full archive, including checksums and agreement between the manifest's Package/Kit enabled-state summary and the activation registry payload, without writing to the target. A summary that claims enabled state without a registry, or conceals enabled state present in that registry, is rejected. `bundle:import --preview` displays a [Change Plan](ReviewableChanges.md) and does not apply it. A normal interactive import asks for confirmation; unattended import requires `--yes`. A plan with conflicts cannot be applied. Review both the source and plan before approving executable application code. Import does not run Composer, migrations, Seeders, Package or Kit hooks.

The destination may be a new directory, an empty existing directory, or an existing SqueHub project with `Project/` and `composer.json`. For an existing project, the Composer project name must match the bundle. Files with identical bytes are left alone. Differing files appear as `modify` actions with their prior SHA-256 hashes and review risk. Missing files and directories appear as `create` actions. Unrelated target files are retained. A Package or Kit owned file that has changed since its registry snapshot, or has shared ownership, blocks replacement. Filename casing conflicts and unsafe links also block import. The apply step rechecks the bundle and target against the reviewed plan; if either changed, preview again.

If a filesystem error occurs partway through apply, the result reports what was applied and what was left. File changes are verified individually, but the entire import is **not** one atomic transaction. Inspect the target before retrying. No imported PHP is executed by the importer; enabled Package source in an imported activation registry can run on a later, separate application boot.

## Bundle contents

The exporter uses these exact, canonical source roots when present:

| Source | Purpose |
| --- | --- |
| `Project/` | Application routes, classes, Views, Scheduler definitions, and project Package/Kit source. |
| `Config/` | Application configuration files. |
| `Database/Migrations/`, `Database/Seeders/`, `Database/Factories/` | Schema, initial/reference data, and factory source. |
| `Assets/` and `public/assets/` | Project-owned source and public static assets. |
| `composer.json`, `composer.lock` | Dependency declaration and lockfile where present. |

`Project/` and `composer.json` are required. The exporter reads the [Activation Registry](ActivationRegistry.md), includes `Project/Activation.json` when present, and records a non-secret Package/Kit enabled-state summary in the manifest. Where only supported legacy activation state exists, the bundle carries a canonical registry representation. The source registry lock and legacy `State.json` files are excluded.

The source bundle excludes `.env` and `.env.*`, credential and common private-key files, `.git`, `vendor`, `node_modules`, `Storage`, `uploads` directories, cache, logs, sessions, backups, and runtime state. It does not include database rows, uploaded user files, Queue jobs, Redis data, compiled Views, or generated dependency directories. This is a path-based policy: **it cannot detect secrets hard-coded into application PHP or ordinary configuration files**. Review source before sharing the archive.

## Format and security boundary

`.sqhb` version 1 is a simple frame: a fixed format marker, a bounded UTF-8 JSON manifest, then the file bytes in sorted manifest order. There is no ZIP extraction, compression, PHP object serialization, or executable archive metadata. The manifest records framework identity, creation time, Composer project/PHP identity and checksum, source roots, directories, file sizes and SHA-256 checksums, and Package/Kit activation summary. Source path spelling and case are preserved.

The current limits are 10,000 files, 16 MiB per file, 256 MiB of total file bytes, and 4 MiB for the manifest. Inspection rejects truncation, trailing bytes, unsupported versions, invalid checksums, traversal and absolute paths, drive or separator tricks, duplicate/case-colliding paths, reserved names, and linked source/target paths. Without PHP `intl`, non-ASCII bundle paths are rejected because their Unicode normalization cannot be verified portably. The format needs no PHP `zip` extension.

SHA-256 proves payload bytes match the manifest. It **does not authenticate who created the bundle**: someone who can replace both payload and manifest can recalculate the hashes. Treat a received bundle as untrusted source. Store and transfer it using controls appropriate for application source and activation metadata. Inspection and import do not perform malware analysis or a Package trust review.

## After import

Review the imported code and configuration, then separately arrange dependencies, environment settings, database schema/data, persistent uploads, and deployment secrets. For example, `composer install` and migrations are explicit later steps; they are never triggered by bundle import. Keep the original `APP_KEY` securely outside the source bundle when restoring data encrypted under that key. See [Backup and portability](BackupAndPortability.md), [Deployment](Deployment.md), and [Recovery](Recovery.md) for the distinct source, data, uploaded-file, and secret boundaries.

The PHP API is also explicit:

```php
use App\Bundles\ProjectBundle;

$bundles = new ProjectBundle($sourceApplicationRoot);
$manifest = $bundles->export($newArchivePath);
$verified = $bundles->inspect($newArchivePath);
$plan = $bundles->planImport($newArchivePath, $targetApplicationRoot);

if (!$plan->hasConflicts()) {
    // Present the plan for deliberate application approval first.
    $result = $bundles->apply($plan);
}
```

`export()` and `inspect()` return `BundleManifest`; `planImport()` returns `ChangePlan`; `apply()` returns `ChangeResult` on success. The plan is bound to the `ProjectBundle` instance that created it and is not an importable serialized authorization token. A failed apply may throw `ChangeApplyException` with a partial `ChangeResult`.
