# Upgrade from SqueHub v1.x

SqueHub v2 is a new generation. Migrate an application in a separate branch or copy, keep its data backup, and test each boundary before switching traffic. The [public v1.x guide](https://squehub.com/docs/v1.x) remains the reference for deployed v1 applications. There is no automatic whole-application upgrade command and v2.0.0 is not yet a published release.

## API and project changes

| v1.x pattern | v2 direction | Compatibility |
| --- | --- | --- |
| `$router->add('GET', '/users', 'UserController@index', 'users.index')` | `Route::path('/users')->get([UserController::class, 'index'])->named('users.index')` | The legacy registration and `Controller@method` form remain bridged into the web registry. |
| `$router->group(...)` | `Route::group()->prefix('/admin')->through('auth')->routes(...)` | Keep old groups when required; modern nested prefixes compose. |
| `use App\Core\View` and root `views/` | `use App\Plugins\View` and `Project/Views/` | Core View import remains; root views no longer resolve. |
| Raw-looking `{{ $html }}` | Escaped `{{ $value }}`; trusted raw `{!! $html !!}` | Template output is intentionally safer and may need review. |
| `App\Core\Model` | `App\Plugins\Model` | Legacy Model remains, but return types and mass-assignment rules differ; migrate class by class. |
| Direct `$_POST` / `php://input` | Inject `App\Plugins\Request`; use `input()`, `json()`, `validate()` | Globals remain PHP globals but new app code should use Request. |
| Manual JSON or header/exit responses | Return `App\Plugins\Response`, `JsonResponse`, or `response()` results | Immediate legacy `redirect()` remains but is not a returnable Response. |
| Global/bootstrap service initialization | Application, providers, and the container | Legacy adapters remain where practical; new services are Application-owned. |
| `Project/Schedule.php` | `Project/Scheduler/*.php` | Old single file is not loaded. |
| Session flash Notification helpers | `App\Plugins\Notification` for delivery; Session flash helpers for browser messages | These are separate purposes. |
| Dumper classes and `make:dumper` | `Seeder` for deliberate data setup; `php squehub make:seeder UserSeeder` creates a new Seeder. | V1 Dumper base/commands are retired in v2. Port `run()` logic and, only when a safe inverse exists, implement `ReversibleSeeder::rollback()` for explicit `seed:rollback Class`. Review `--force` behavior. |
| Namespace/case variations | Capitalized `App`, `Project`, `Config`, `Database`, `Docs`, `Tests` | First-letter fallback accepts documented legacy paths; new source should use canonical casing. |

## Global helper migration

The v1 helper page lists the functions below. In v2 they remain global functions after normal SqueHub bootstrap; `use App\Core\Helper` does not import them because there is no Helper class. The [Global helpers reference](Helpers.md) lists the complete supported v2 surface and its context requirements.

| v1 helper | v2 decision and replacement | Why review it |
| --- | --- | --- |
| `startSessionIfNotStarted()` | Compatibility only; use `session()` and let store operations start it. | V2 Session state belongs to the selected Application. |
| `route($name, $params)` | Keep; use it for named **public paths**. | The modern registry validates and encodes path values, applies `APP_BASE_PATH`, and throws for an unknown name; the old router returned `#` for a missing name. |
| `csrf_token()` | Keep for the current Session-bound token; prefer `@csrf` or `csrf_field()` in forms. | The v1 raw `$_SESSION['_token']` implementation is no longer the CSRF state owner. |
| `validateCsrfToken()` | Compatibility only; use Kernel CSRF middleware. | The old helper terminated on failure; v2's bridge throws a CSRF exception. |
| `CsrfTokenValidator()` | Compatibility only; use Kernel CSRF middleware. | The boolean helper reads legacy POST globals; normal v2 requests use the Request/Session lifecycle. |
| `is_post()` | Compatibility only; use the injected Request's `method()`. | Raw `$_SERVER` is not the Request abstraction. |
| `maskEmail()` | Compatibility utility; move new presentation policy into application code. | Its fixed mask is not general privacy protection. |
| `maskPhoneNumber()` | Compatibility utility; move new presentation policy into application code. | Its fixed last-two-character mask is application-specific. |
| `slugify()` | Compatibility utility; keep only where its output is already relied on. | Slug policy and transliteration can vary by application. |
| `flash()` | Compatibility message map; prefer `session()->flash()` for new state. | The bridge retains named consume-on-read behavior, while v2 Session flash has a request lifecycle. |
| `priceFormatter()` | Compatibility utility; put new currency/locale presentation in application code. | It formats numbers but does not define a locale or currency policy. |
| `url(...$segments)` | Compatibility only; use `route()` for named public paths and a reviewed canonical origin for absolute links. | It still reads raw Host/scheme and ignores the URL mount. |
| `redirect()` | Zero-argument immediate `to()`/`back()` remains compatibility only. Use `redirect('/path')` for a returnable local redirect, `response()->redirect(route('name'))` for a named route, and `redirectBack($request, '/fallback')` for safe browser back behavior. | The v1 form sends headers and exits; its `back()` trusts raw Referer. V2 returnable forms use the existing Response and browser navigation services. |

The v1 page described both project and Package `Utils` files as globally loaded. V2's root legacy `Bootstrap.php` eagerly requires PHP files in the first matching `Project/Utils` casing variant during normal application startup. Package utility files load only for enabled Packages through activation; a disabled Package's functions are unavailable. Composer autoload alone does not run these loaders. Prefer namespaced utility classes where possible and avoid PHP/SqueHub global function name collisions.

## A safe migration sequence

1. Inventory v1 routes, controllers, middleware, models, views, migrations, packages, CLI commands, and helpers in the actual application. Also record project assets, configuration, Composer dependencies, Seeders, persistent uploads, and live database data. Do not rely only on old filenames.
2. Copy application code into the v2 `Project/` layout and bring over the assets and package resources it needs. Move old application route definitions into `Project/Routes/`; v2 does not load `App/Routes/`. `Project/` makes application code easier to review separately from framework internals, but it is not the complete application backup. Keep existing route syntax running through the legacy bridge first. Point the web server to `public/`.
3. Copy `.example.env` to a **new private** `.env`, generate a new `APP_KEY` when appropriate, and map database/session/mail configuration. Do not blindly replace production secrets or rotate a key used for existing encrypted data without a migration plan.
4. Move view templates to `Project/Views` or package `Views`. Review every `{{ }}` expression that previously relied on raw HTML. Use `{!! !!}` only for trusted markup. Test layouts, includes, forms, and notification flash views.
5. Convert models one at a time. Legacy static reads can return arrays or `false`; modern `Model::find()` returns a Model or `null`, and `all()` returns a `ModelCollection`. Declare `$fillable` before mass assignment. Verify table names, keys, casts, timestamps, relations, and soft-delete columns explicitly.
6. Keep existing migrations where supported, then adopt typed `Schema` migrations for new changes. Convert v1 Dumper data setup to modern `Database/Seeders` classes. `seed:status` shows matching Seeder filenames, not class validity or past executions; write an explicit `ReversibleSeeder` only where rollback is safe. Run database commands only on the intended database and back up data first.
7. Add explicit modern middleware, validation, authentication, authorization, and rate limits according to application policy. SqueHub does not automatically throttle login or create user/auth tables.
8. Move Package resources and Scheduler definitions to their documented locations. A Package copied into `Project/Packages/<PackageName>/` is discovered but disabled in v2. Add its package-named entry class, check `php squehub package:list`, then explicitly run `php squehub package:enable <PackageName>` after reviewing the source and its dependencies. Previously auto-loaded Package routes, views, Utils, and Scheduler definitions do not activate merely because their files are present. Confirm enabled contributions through the current application bootstrap.
9. Run `composer test`, `php squehub route:list`, `php squehub doctor`, and representative HTTP requests in a disposable environment. Test both success and error pages, CSRF, login/logout, database writes, and Queue workers before switching traffic.

## Breaking changes and limits

The repository-root `views/` fallback is gone. Model result contracts change when moving from `App\Core\Model` to `App\Plugins\Model`. Modern route registration rejects duplicate method/path pairs and duplicate names, unlike some legacy registration behavior. Modern groups compose nested prefixes; old groups retain their historical inner-prefix replacement. New `{{ }}` escaping can change visible output. Browser forms now require CSRF by default. The v2 template and route syntax are documented in [Views](Views.md), [Routing](Routing.md), [Models](Models.md), and [CSRF](Csrf.md).

The five [v2 code generators](Generators.md) create Controllers, Middleware, Migrations, Models, and Seeders. They do not convert existing v1 files or run database changes. Their `--preview` mode writes nothing; controller, model, and middleware generation can target an existing Package explicitly. No generic codemod, schema auto-upgrade, Package migration wizard, or automatic root-view compatibility fallback is provided.

The Package lifecycle now separates installation from activation. Imported Packages start disabled; `package:enable` is an explicit code-execution decision. Package-local Migration and Seeder generation remains unavailable, and enable/install never runs those files. Managed Package removal and upgrade protect modified owned files; manually copied Packages have no ownership record and must be upgraded or removed manually. See [Packages](Packages.md).

Portable application-source transfer is available through `bundle:export`, `bundle:inspect`, and reviewed `bundle:import`. The [project bundle](ProjectBundles.md) includes selected `Project/`, `Assets/`, configuration, dependency, Migration, and Seeder source, but excludes `.env`, runtime uploads, and database records. The separate `backup:dev` command omits `Assets/` and live database records. Neither is a replacement for an explicit database and uploads backup during an upgrade. Review [Backup and portability](BackupAndPortability.md) before planning a restore.
