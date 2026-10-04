# Recovery boundaries

SqueHub classifies recovery material by what it can actually restore. A portable project bundle covers application **source**. It does not contain production database rows, uploads, deployment secrets, or pending Queue work. `backup:dev` is a separate development ZIP that can include `.env`; do not treat it as a complete recovery artifact.

## Inspect the recovery plan

```bash
php squehub recovery:plan
php squehub recovery:plan --source-bundle=project.sqhb --json
php squehub recovery:plan --database-snapshot=/private/snapshot.sql --snapshot-driver=mysql
php squehub recovery:plan --database-snapshot=/private/snapshot.sql \
  --snapshot-driver=mysql --snapshot-sha256=<independently-recorded-sha256>
```

The command is read-only. Supplying a source bundle verifies its format, paths, sizes, and SHA-256 checksums against its manifest. This is **integrity**, not proof that its author or PHP code is trusted. A supplied database artifact receives a size and SHA-256 fingerprint; the report deliberately says `provided_unverified`. Supply `--snapshot-sha256` from an independent operator record to compare it with the calculated digest. Without that value the digest is `calculated_unanchored`. Neither result proves snapshot consistency or restorability. The report leaves capture time, capture tool, and database identity unknown because it cannot infer them from file metadata. The declared snapshot driver must match the selected application database driver. Source and snapshot paths are never printed in the report.

| Boundary | What to preserve | SqueHub's current action |
| --- | --- | --- |
| Source | `Project/`, application configuration, migrations, Seeders, assets, Composer metadata | Inspect a separate portable source bundle. |
| Database records | A backend-consistent logical or physical snapshot | Register an operator-supplied artifact for checksum review only. No database dump or restore is performed. |
| Persistent files | Uploads and private application files | Plan a separate local backup, or a separate S3-compatible provider recovery procedure. The source bundle excludes them. |
| Secrets | `APP_KEY`, previous Crypt keys, provider, mail and database credentials, signing keys | Preserve through an operator-controlled secret store, separately from ordinary bundles. No values enter the report. |
| Runtime | Cache, compiled Views, route/config caches, temporary files | Usually rebuild rather than restore as durable business data. |
| Sessions | Active browser state | Decide whether recovery needs it; it is excluded by default. |
| Queue | Pending and failed work | Decide separately. Restoring old Queue state may repeat external side effects because delivery is at least once. |

Neither Migrations nor Seeders are a database backup. They define schema and deliberate initial/reference data, not the live records produced by an application. Losing `APP_KEY` or a required historical Crypt key may make existing encrypted values unreadable; generating a new key does not recover them.

The report lists secret categories without values: `APP_KEY`, previous Crypt keys if used, database credentials, Mail/provider credentials, and OIDC client secrets. Preserve required signing or encryption material using a private operator-controlled process. Recovery planning does not copy or verify any of these secrets.

## Snapshot and restore limits

SqueHub does not yet orchestrate `mysqldump`, copy live MySQL data files, take an in-process SQLite snapshot, or execute a database restore. A filesystem copy of a live WAL-backed SQLite file is not presented as consistent. Use a backend-supported, operator-reviewed snapshot procedure and rehearse a restore in an isolated environment. A destructive database restore needs an explicit target, reviewable risk and separate authorization; `bundle:import` never performs one.

Local persistent Storage and remote S3-compatible Storage also require their own backup and restore procedures. `recovery:plan` does not download remote objects, write local files, run Queue workers, or send Mail. The report is a planning aid, not a complete disaster-recovery guarantee.
