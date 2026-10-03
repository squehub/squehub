# Code generators

SqueHub v2.0.0 provides five focused `make:*` commands for common application files. They create source files; they do not boot routes, execute application code, connect to the database, run migrations or Seeders, or install Packages. Run them from the application root with `php squehub`. The commands are available in this development working tree; v2.0.0 has not been published as a stable release. To plan several related files for one application capability, use the [SqueHub Feature Blueprint](FeatureBlueprints.md) command, `make:feature`.

```bash
php squehub make:controller UserController
php squehub make:model User
php squehub make:middleware RequireProfile
php squehub make:migration create_users_table
php squehub make:seeder UserSeeder
```

Use `php squehub <command> --help` for the installed command's argument and option list. New application classes use canonical capitalized names, directories and namespaces. Existing source remains yours to edit after generation.

## One file or one feature

The five commands above each plan **one file**. `php squehub make:feature Post --preview` instead plans one coherent set of Model, Migration, Controller, Validation rules class, API Resource, route, and test files through the same `ChangePlan` vocabulary. It uses the existing SqueHub APIs and does not replace the focused generators. Its GET index scaffold returns a fixed first Page of 20 records in the normal Resource `data`/`meta` envelope; add validated page selection for real application pagination. A Feature Blueprint generates a minimal structural starting point; you must supply your own fields, rules, policies, and business logic. Use `--table` and `--route` when the singular defaults differ from your application naming. See [Feature Blueprints](FeatureBlueprints.md) for the precise target and Package behavior.

## Targets and generated contracts

| Command | Default target | Generated class |
| --- | --- | --- |
| `make:controller UserController` | `Project/Controllers/UserController.php` | Ordinary `Project\Controllers\UserController`; no required controller superclass. |
| `make:model User` | `Project/Models/User.php` | `Project\Models\User` extending the modern `App\Plugins\Model`. |
| `make:middleware RequireProfile` | `Project/Middleware/RequireProfile.php` | `Project\Middleware\RequireProfile` with the modern `handle(Request $request, Closure $next): Response` pipeline contract. |
| `make:migration create_users_table` | A dated file under `Database/Migrations/` | A migration class whose `up()` creates `users` with `Schema` and whose `down()` drops it. Other valid migration names generate empty `up()`/`down()` bodies for the developer to complete. |
| `make:seeder UserSeeder` | `Database/Seeders/UserSeeder.php` | `Database\Seeders\UserSeeder` extending `App\Plugins\Seeder`, with an empty `run(): void`. |

The model stub does not guess a table, connection, `$fillable`, casts, timestamps, or relationships. Declare the metadata that your application actually needs before using mass assignment. The migration is a starting point: review the generated table name and columns, add the needed Schema calls, and write a matching `down()` before running `migrate`. Seeder bodies are deliberately empty; add explicit, repeatable data setup before running `seed`. See [Models](Models.md), [Migrations](Migrations.md), [Schema](Schema.md), and [Seeders](Seeders.md).

Controller, Model and Middleware generation accept nested class names such as `Admin/UserController` (or `Admin\UserController`). Each segment becomes a capitalized PHP class or namespace segment under the corresponding application directory. For example:

```bash
php squehub make:controller Admin/UserController
```

creates `Project/Controllers/Admin/UserController.php` with namespace `Project\Controllers\Admin`. Migration and Seeder names remain single names; a slash or namespace separator does not make them nested.

## Existing Package targets

The three `Project/` class generators can target an **existing** Package by its exact, capitalized name:

```bash
php squehub make:controller OrderController --package=Commerce
php squehub make:model Order --package=Commerce
php squehub make:middleware RequireOrder --package=Commerce
```

These create files in `Project/Packages/Commerce/Controllers/`, `Models/`, and `Middleware/` respectively, with `Project\Packages\Commerce\...` namespaces. They do not install, create, enable, or boot the Package. A missing, invalid, or differently cased Package is an error. The Package may be disabled while a class is generated. Package targeting for `make:migration` and `make:seeder` remains unavailable because their runners do not promise Package-local discovery. See [Packages](Packages.md).

## Preview, collisions, and safety

Add `--preview` to any of the five commands to inspect its target without writing files or directories:

```bash
php squehub make:controller Admin/UserController --preview
php squehub make:controller OrderController --package=Commerce --preview
```

The preview uses the shared [reviewable change plan](ReviewableChanges.md). It reports the proposed `CREATE` path, owner, risk, conflicts, and deterministic plan fingerprint without source contents. Apply deliberately with `--yes`, or answer the confirmation prompt in a real interactive terminal. Non-interactive commands without `--yes` fail promptly. Actual generation rechecks the target and refuses an existing file, including a conflicting casing variant. There is no `--force` overwrite option. If a file already exists, edit it yourself or choose another class name. Invalid identifiers, traversal, absolute paths, links that escape the application root, and unsafe Package targets fail before a write. Generation does not call `composer dump-autoload` for normal PSR-4 application classes.

The generated content is prepared privately, published using a complete temporary file and create-if-absent operation, then checked against its planned SHA-256 fingerprint. A stale plan cannot overwrite an externally created target. A generated Migration or Seeder is source only: neither is executed by a change plan.

## Seeder replaces Dumper

Use `make:seeder` for new data setup. The v1 Dumper class and its generator and run commands are retired from v2; they are not aliases for `Seeder`. Move application-owned Dumper logic into explicit `App\Plugins\Seeder` classes before upgrading. A generated Seeder is never run automatically and does not include rollback code. Where an intentional inverse exists, implement `App\Plugins\ReversibleSeeder` and call `seed:rollback Class`; execution history is not persisted. See [Seeders](Seeders.md) and [Upgrade from v1.x](UpgradeFromV1.md).
