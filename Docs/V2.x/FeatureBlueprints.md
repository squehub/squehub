# SqueHub Feature Blueprint

A **SqueHub Feature Blueprint** plans the structural starting point for one application capability. `make:feature` combines the existing generator, routing, API Resource, testing, Package ownership, and reviewable-change contracts into one `ChangePlan`. The command is available in the v2.0.0 development working tree; v2.0.0 is not yet a published stable release.

```bash
php squehub make:feature Post --preview
php squehub make:feature Post --yes
```

The five single-file `make:*` commands remain available when you need only one class or migration. A Feature Blueprint is useful when you want the related application files planned together. It does not invent business requirements from a class name.

A [SqueHub Kit](Kits.md) is a separate solution-composition lifecycle that can publish related application files and require Packages. Kits do not have to call `make:feature`; a Feature Blueprint remains independently usable for one feature's scaffold.

## Naming and target

Give the feature a singular, capitalized PHP name such as `Post`. The default database table and route path are deliberately derived without English pluralization: `Post` uses table `post` and route `/post`. Specify a different database table or route deliberately:

```bash
php squehub make:feature Post --table=posts --route=/posts --preview
```

`--table` and `--route` set the intended names; they do not inspect the live database. The table option accepts a bounded lowercase snake-case identifier, and the route option accepts a bounded literal lowercase URL path. An overridden route also determines the generated route name by replacing path separators with dots and hyphens with underscores, then appending `.index`. The feature name must be a safe, bounded class identifier. Names containing path separators, controls, or invalid PHP identifier characters are rejected. Review the plan before apply, especially if your application's naming policy differs from the defaults.

A normal application Feature Blueprint creates these seven files:

```text
Project/Models/Post.php
Project/Controllers/PostController.php
Project/Api/Resources/PostResource.php
Project/Validation/PostValidation.php
Project/Routes/Features/Post.php
Database/Migrations/<timestamp>_create_post_table.php
Tests/Integration/PostFeatureTest.php
```

The migration filename uses the established generator's timestamp format, so invocations at different times can have different plan fingerprints. The name and remaining content are deterministic for equivalent inputs. The migration creates only an `id` column and has a matching `down()` skeleton; it does not guess domain fields.

The generated controller has one `index()` action. It queries a **fixed first page of 20 records** and returns `PostResource::collection(Post::query()->page(1, 20))->response()`. The response uses the existing Page Resource envelope: `data` contains at most 20 resource items, and `meta` contains `page`, `per_page`, `total`, `pages`, `from`, `to`, `has_next`, and `has_previous`. The scaffold does not read a page number from the Request; add validated page selection and a suitable page-size policy when your application needs them. The Resource exposes only `id` as an explicit public field; no other Model attributes are published automatically. The dedicated route file registers one GET `/post` route with `Route::path('/post')->get([PostController::class, 'index'])->named('post.index')`. It loads through the existing recursive `Project/Routes/` loader. There is no automatic create, update, or delete endpoint.

`PostValidation::rules(): array` returns an empty rule map as an explicit starting point. Its `validate(Request $request): array` method forwards to `$request->validate(self::rules())`. The generated test calls that method to prove the existing Request validation contract. Add real rules before calling it from a future write handler; an empty map is not input protection. The GET index does not call validation, and no domain-specific rules are inferred. This is a small application rules class that uses the existing validation engine, not a second validation runtime.

Generated source is a starting point: add the real table columns, Model metadata, validation rules, Resource fields, routes, and authorization policy that your application needs. A Feature Blueprint does not infer database fields, relationships, CRUD actions, business rules, authorization, or an API contract declaration.

## Existing Package target

Target an existing, exactly named Package explicitly:

```bash
php squehub make:feature Order --package=Commerce --preview
php squehub make:feature Order --package=Commerce --yes
```

For `Order --package=Commerce`, the default table is `commerce_order`, path `/commerce/order`, and route name `commerce.order.index`. The Package's Model, Controller, API Resource, Validation rules class, and route definition live under its supported tree:

```text
Project/Packages/Commerce/Models/Order.php
Project/Packages/Commerce/Controllers/OrderController.php
Project/Packages/Commerce/Api/Resources/OrderResource.php
Project/Packages/Commerce/Validation/OrderValidation.php
Project/Packages/Commerce/Routes/Features/Order.php
Database/Migrations/<timestamp>_create_commerce_order_table.php
Tests/Integration/CommerceOrderFeatureTest.php
```

The Migration is in the root `Database/Migrations/` runner, and the test is in the root `Tests/Integration/` suite, because those are the established runnable discovery locations. Every action in the shared plan has owner `Package Commerce`, including the root files. A Package-local Migration or test file would be misleading while the corresponding runner cannot discover it.

Feature generation does not install, create, enable, disable, verify, upgrade, or remove the Package; it does not change its dependencies. A disabled Package's route remains inactive until the Package is deliberately enabled. A root migration file is available to the normal migration runner regardless of Package activation, so review it before manually running migrations. Package-local Seeders and automatic migration execution are outside this command.

## Review and apply

```bash
php squehub make:feature Post --preview
php squehub make:feature Post --yes
```

`--preview` shows one shared [reviewable change plan](ReviewableChanges.md) with action paths, application or Package ownership, risk, warnings, conflicts, and a plan fingerprint. It performs no application writes, database operations, migration or Seeder execution, or Package boot solely for inspection. It does not need a live database. The plan displays paths and fingerprints rather than generated source or application secrets.

The command checks every proposed target before writing. Existing files, unsafe paths, case-only collisions, and demonstrably conflicting literal route declarations block apply. Route files are scanned statically without executing their PHP; dynamic and legacy route declarations cannot all be proven conflict-free in preview. Check `php squehub route:list` after apply. Stale plans are rejected if an inspected file or Package ownership changes before application. A real interactive terminal may confirm the plan; use `--yes` for explicit non-interactive application. There is no broad overwrite mode. Inspect the generated source and run its test after apply.

```text
Migration execution: NOT INCLUDED
Seeder execution: NOT INCLUDED
```

Run the normal `migrate` command separately only after reviewing the generated migration and confirming the target database. Add and run Seeders separately when needed. A file plan is not a universal filesystem transaction: if an I/O failure occurs after some files have been published, inspect the reported result and resulting files before retrying.

## Testing and API contracts

The generated test extends `App\Plugins\TestCase` and belongs to the application's PHPUnit suite. It copies the generated migration and route into a disposable TestApplication, runs the migration **only inside that fixture**, calls the read endpoint through the real HTTP Kernel, and checks the generated Page envelope and validation contract. Feature generation itself never runs a migration. Package-targeted tests use the root suite as well, so normal PHPUnit discovery can run them without loading Package-local test directories. The application must include its test directory in PHPUnit configuration and keep those files under version control according to its own ignore policy. See [Testing](Testing.md).

The generated API Resource uses the existing `App\Plugins\ApiResource` contract. It is not automatically an [SqueHub Contract](ApplicationContract.md) declaration or an OpenAPI operation. Declare request and response schemas explicitly if you want contract export and verification; do not treat a generated route as contract-complete.

See [Code generators](Generators.md), [Packages](Packages.md), [Routing](Routing.md), [Validation](Validation.md), [API resources](ApiResources.md), and [Reviewable changes](ReviewableChanges.md) for the underlying APIs.
