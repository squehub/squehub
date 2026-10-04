# SqueHub Setup

**SqueHub Setup** is the optional guided configuration command for a v2 application:

```bash
php squehub setup
```

It inspects the existing application before proposing changes. The normal manual installation path remains supported and does not require Setup. Select and verify a complete v2 source or package as described in [Installation](Installation.md).

## Fresh application

Run `php squehub setup` from the application root in a real interactive terminal. When `.env` is missing, select an environment and a database, review the displayed **SqueHub Setup Plan**, then answer the apply confirmation. The default confirmation answer is **No**. The supported environment values are `development`, `local`, `staging`, and `production`; the supported database choices are `sqlite` and `mysql`.

For a non-interactive shell, provide the required non-secret choices explicitly. Review without writing first:

```bash
php squehub setup --environment=local --database=sqlite --preview
```

To apply that reviewed choice without a terminal confirmation, run:

```bash
php squehub setup --environment=local --database=sqlite --yes
```

`--yes` confirms only the Setup Plan. It does not supply a password, rotate an existing key, or approve Migrations. The short form is `-y`. Without a terminal or the required choices, Setup reports what is missing and exits; it does not guess an environment or database. `--preview` makes no project changes, does not generate an application key, and does not run Doctor.

For SQLite, Setup plans an empty persistent `Storage/Database.sqlite` file if it does not exist and points `DB_SQLITE_DATABASE` to it. Relative SQLite paths are resolved from the application root, independent of the shell's current directory; relative traversal outside the project is rejected. If `Storage/` is absent, its creation appears in the reviewed plan. New local runtime paths request private permissions; review deployment ACLs for the account running PHP. An existing unwritable or symlinked Storage path is an error. A custom file-backed SQLite path is preserved when its file already exists; if it is missing, Setup stops before Doctor could create an unplanned file. Setup creates no tables; run applicable Migrations separately after reviewing them. For MySQL, Setup selects the driver but leaves host, database, user, and password configuration to the developer in `.env`. It does not create a MySQL database or account.

## Existing application

Run `php squehub setup` again to inspect the current `.env`. Existing nonblank environment and database choices are retained unless you explicitly select a new one. A valid existing `APP_KEY` remains untouched. If no required changes remain, Setup reports that state and runs Doctor; it does not rewrite `.env` for formatting. An invalid existing key or unsafe environment file needs manual correction rather than silent replacement.

The inspection labels values as missing, configured, or invalid where appropriate. They are configuration observations, not a guarantee that selected services are reachable. If `.env` is absent and `.example.env` is also unavailable, Setup cannot create a private environment file and exits safely.

## Manual setup remains available

From the project root, copy `.example.env` to `.env`, run `php squehub key:generate`, paste the generated `base64:` value into `APP_KEY`, review application and database settings, and run `php squehub doctor`. See [Installation](Installation.md#configure-the-environment) for platform-specific copy commands. `key:generate` prints a new key; it does not edit `.env`.

## Review and apply

Setup plans only the configuration it understands: creating `.env` from `.example.env` when missing, setting the selected `APP_ENV` and `DB_CONNECTION`, setting `DB_SQLITE_DATABASE` for the managed SQLite file when appropriate, generating `APP_KEY` only if missing, setting `APP_DEBUG=false` for a deliberately selected production environment, and preparing missing local Storage for SQLite. It does not rewrite unknown `.env` keys, comments, or unrelated values. The editor validates input and output with the existing dotenv parser and preserves line endings where practical. Files with unsupported multiline assignments or invalid syntax need manual editing.

The reviewable Setup Plan identifies file actions and safe warnings before apply. It does not contain application keys or passwords. The application key is generated only during deliberate application using the framework's existing secure generator; Setup does not print it. Setup rechecks `.env`, `.example.env` when creating from the template, and the managed SQLite path before writing. A changed file makes the plan stale instead of overwriting newer work. Environment updates publish a complete validated file through a temporary file and replacement where supported. Cooperating Setup processes use a lock; this is not a promise of isolation from arbitrary external file writers. See [Reviewable changes](ReviewableChanges.md) for the shared planning vocabulary.

After a successful apply, Setup boots fresh configuration for [SqueHub Doctor](Health.md). Doctor checks required services and reports optional warnings independently. `SETUP COMPLETE` means the required setup and Doctor checks succeeded, not that the deployment is production ready. A Doctor failure after apply does not undo the configuration; Setup reports that changes were applied and needs attention. A partial file-apply failure reports the actions known to have completed so a rerun can inspect the resulting state.

After Setup, use `php squehub start` for only the local PHP server, or optionally use `php squehub dev` for [SqueHub Dev](Dev.md)'s preflight and coordinated development session. Dev does not repeat Setup or apply configuration changes. Queue workers start only when deliberately selected on a supported persistent connection.

## Database and infrastructure boundaries

The guided database choices are limited to supported SQLite and MySQL application settings. SQLite's in-memory database is for tests, not the normal persistent application default. MySQL configuration does not create a database, account, or privileges. Doctor evaluates the selected connection without running Migrations; a configured connection is not automatically a reachable one.

Setup reports whether the local `Storage/` directory is writable. A basic application can use local Storage, file Cache, the configured native Session backend, and sync Queue without Redis, Node.js, or an external worker. Setup does not change Cache, Queue, Session, Redis, Mail, or external Storage credentials. Their configured availability belongs to Doctor and deployment checks. Writing a Queue driver name does not start a worker.

## Migrations, Seeders, Packages, and Kits

Setup does not run Migrations or Seeders and does not install or enable Packages or [Kits](Kits.md). A simple application with neither component needs no persistent [Activation Registry](ActivationRegistry.md), and Setup does not create one. Migration execution, when needed, remains an explicit `php squehub migrate` operation with its normal safety requirements. `--yes` on Setup does not authorize it. Seeder is the v2 data-seeding API; Dumper is retired. [Frontend Profiles](FrontendProfiles.md) use their own reviewed `profile:apply` and `profile:remove` commands after initial Setup, because they publish Project source and select a build adapter rather than configure secrets or a database. Kit selection and Feature Blueprints keep their own explicit review flows.

The optional frontend profile workflow has passed Windows and native Linux qualification. Setup remains a separate configuration command and does not install Node/npm or perform a frontend build. See [current status](Status.md) for qualification limits.

## Secrets and failure boundaries

Keep `.env` private. A Setup Plan and its ordinary output must not reveal `APP_KEY`, database passwords, Redis credentials, mail credentials, or other secrets. Do not pass passwords or keys as command-line options, where shell history and process listings can retain them. An existing `APP_KEY` is preserved; rotating it is a separate deliberate operation that can invalidate encrypted data and sessions.

Setup completion does not mean an application is production ready. Doctor can still report warnings or failures for selected services. If an apply or verification step fails, inspect the reported changes and rerun Setup after addressing the problem; do not assume earlier configuration changes were rolled back. Use [Deployment](Deployment.md) and [Health](Health.md) for release checks.

## Scope

Setup is an orchestration tool for existing framework services. It does not provision MySQL accounts, Redis, SMTP providers, or external storage; execute Seeders; install Packages or Kits; or replace the manual workflow. It does not capture or replay HTTP requests; use [integration tests](Testing.md) or explicit [API verification cases](ApiVerification.md) to check request behavior.
