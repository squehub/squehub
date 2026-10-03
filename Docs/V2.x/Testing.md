# Testing SqueHub applications

SqueHub uses **PHPUnit** as its test runner. The application-facing testing symbols are available under `App\Plugins`; their canonical implementations live in `App\Testing`. They create a disposable Application and send requests through the real HTTP Kernel. They remove routine setup; routing, middleware, controllers, Session, CSRF, exception rendering, and Response handling still use the normal framework code.

Phase 19 includes isolated tests for [Observability](Observability.md), [correlation](Correlation.md), [development profiling](Profiler.md), [framework cache](PerformanceCaching.md), and [Studio](Studio.md). Opt-in profiler and Studio tests use disposable Applications; an ordinary test run needs no external telemetry collector or Node.js.

Phase 20 adds isolated [translation](Internationalization.md), [S3-compatible Storage](ProviderStorage.md), [HTTP Mail provider](ProviderMail.md), and [Memcached Cache](InfrastructureAdapters.md#memcached-cache) tests. The normal suite uses fake clients or loopback HTTP; guarded external S3, Mail, and Memcached tests skip unless test-specific opt-in variables are supplied. A skip is not live-provider evidence. PHP `intl` is optional for basic translation lookups, but the plural and locale-formatting paths require a runtime with `intl` enabled. Run those paths separately if the default PHP executable lacks it.

This guide describes the v2.0.0 **development working tree**, not a published release. Install development dependencies before running tests:

```bash
composer install
composer test
php vendor/bin/phpunit Tests/Integration/UsersTest.php
```

The core repository's `phpunit.xml.dist` runs `Tests/Unit/` and `Tests/Integration/`. An application may use ordinary PHPUnit directly. Its own PHPUnit configuration should bootstrap `vendor/autoload.php` and point at its test directories; SqueHub does not replace PHPUnit.

Application tests use PHPUnit and, when needed, the disposable SqueHub TestCase below. They do not need a long-running `php squehub dev` session or live Queue worker; [SqueHub Dev](Dev.md) is a separate local workflow for manually running an application.

A [SqueHub Kit](Kits.md) publishes normal application files; it does not add a second HTTP test runtime. Extend `App\Plugins\TestCase` to test the resulting routes and controllers through the real Kernel in a disposable root. Prepare the Kit definition, reviewed lifecycle state, and any required Package fixtures before the first `$this->app()` or HTTP request. Keep Kit lifecycle tests separate from tests of the application behavior its files produce. Neither test style should use the developer's configured database or run Kit Migrations or Seeders implicitly.

A small application-side `phpunit.xml.dist` may look like:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>Tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>Tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Install the project's Composer development dependencies before invoking `vendor/bin/phpunit`. This configuration does not run a real application bootstrap or contact its configured database; an individual test chooses when to create a disposable TestCase fixture.

Phase 13E does not add `make:test`. Create an ordinary PHPUnit class in your test directory and extend the SqueHub base when the test needs an Application. A generator can be evaluated later if it removes real setup work.

`make:feature` is a different, feature-level command. Its [SqueHub Feature Blueprint](FeatureBlueprints.md) includes a runnable structural integration test using `App\Plugins\TestCase`; it does not add a standalone `make:test` command or depend on this framework repository's own test helpers. The generated test copies its Migration and route into a disposable fixture, calls `migrate()` there, then requests the fixed first-Page GET endpoint. It asserts empty `data`, `meta.page = 1`, `meta.per_page = 20`, `meta.total = 0`, and the empty Validation rules contract. Feature generation never runs a Migration in the developer database. Application and Package-targeted Blueprint tests use root `Tests/Integration/`, which the established PHPUnit configuration can discover. Keep application test files under version control according to that application's ignore policy; this development repository currently ignores its own `Tests/` tree.

For application integration tests, extend `App\Plugins\TestCase`. It extends the canonical `App\Testing\TestCase`, creates a separate disposable `TestApplication` in `setUp()`, and removes its owned root in `tearDown()`. Route, service, and global resolver state from one test does not become another test's state. Keep small unit tests on plain `PHPUnit\Framework\TestCase` when no Application is needed.

```php
<?php

declare(strict_types=1);

namespace Project\Tests\Integration;

use App\Plugins\TestCase;

final class WelcomeTest extends TestCase
{
    public function test_welcome_route_uses_the_real_kernel(): void
    {
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php

use App\Plugins\Route;

Route::path('/welcome')->get(static fn (): string => 'Welcome');
PHP);

        $this->get('/welcome')
            ->assertOk()
            ->assertContains('Welcome');
    }
}
```

Override `testingConfig(): array` to supply safe configuration before providers boot. The protected `testApplication()` returns the owned fixture, `app()` returns its Application, and `client()` returns one stateful `TestClient` for requests within that test. The TestCase also forwards `get`, `post`, `put`, `patch`, `delete`, `getJson`, and `postJson` to that client. A useful application layout is `Tests/Unit/`, `Tests/Integration/`, and an optional project test base class; no directory layout is forced at runtime.

```php
protected function testingConfig(): array
{
    return ['app' => ['name' => 'Users test']];
}
```

The fixture defaults to the `testing` environment and enforces its local-driver boundaries. To change a fixture after `setUp()` but before boot, call `$this->testApplication()->configure([...])` before `$this->app()`, `$this->client()->request(...)`, or a request helper.

## Test levels and environment proof

| Level | What it proves | Typical setup |
| --- | --- | --- |
| Unit | A focused class or value contract | Ordinary PHPUnit; no full Application unless needed |
| Integration | Composition of real SqueHub services or Request through Kernel | Disposable `TestApplication`, local drivers, SQLite |
| Qualification | A specific live platform or external backend | Explicitly authorized disposable MySQL/Redis/SMTP or host profile |

An SQLite integration test is not evidence that the same path works on MySQL. Windows execution is not Linux or case-sensitive-filesystem execution. Record the environment with the result. [V2 status](Status.md) distinguishes implemented APIs from qualified deployments.

## Disposable application

`TestApplication::temporary()` owns a fresh directory under the system temporary path. It creates `Config/`, `Project/Routes/`, `Project/Packages/`, `Project/Views/`, and `Storage/`, and boots real Application providers only when needed. Call `cleanup()` in `finally` when using the fixture directly:

```php
use App\Plugins\{TestApplication, TestClient};

$testing = TestApplication::temporary();

try {
    $testing->write('Project/Routes/Web.php', <<<'PHP'
<?php

use App\Plugins\Route;

Route::path('/welcome')->get(static fn (): string => 'Welcome');
PHP);

    (new TestClient($testing))
        ->get('/welcome')
        ->assertOk()
        ->assertContains('Welcome');
} finally {
    $testing->cleanup();
}
```

`write()` accepts canonical, relative paths inside the owned root. It rejects traversal, linked ancestors, and `.env`. `path('Storage/example.txt')` returns a contained path for local fixtures; `root()` returns the owned root. `loadRoutes()` loads application and enabled Package route files once. `application()` returns the real booted Application. Configuration and Package fixtures should be prepared **before** the first boot.

The default configuration sets the Application environment to `testing` with debug off, an in-memory SQLite database, array Session, Cache, Storage and Logging drivers, and **CSRF enabled**. The fixture does not read the developer's `.env`. It rejects non-SQLite database connections and state paths outside its own root. These guards are for isolated framework tests; they are not an opt-in live database qualification mechanism.

Use `configure()` before boot to adjust safe settings:

```php
$testing = TestApplication::temporary([
    'app' => ['name' => 'Fixture'],
]);

$testing->configure([
    'database' => [
        'connections' => [
            'testing' => [
                'database' => $testing->path('Storage/testing.sqlite'),
            ],
        ],
    ],
]);
```

Configuration values must be exportable scalar or array data. An explicit bounded `app.env` override can exercise environment-dependent behavior, including production-style error rendering, but the SQLite and local-state boundaries remain enforced. A second `configure()` call after `application()` has booted is rejected rather than silently changing provider-captured configuration.

## Request client and real pipeline

`TestClient` provides `get()`, `post()`, `put()`, `patch()`, `delete()`, `getJson()`, `postJson()`, and `request()`. Requests reach `Request → Kernel → Router → Middleware → Controller → ExceptionHandler → Response`. A test-only dispatcher does not bypass route middleware or CSRF.

```php
$client = new TestClient($testing);

$client->get('/users?page=2')->assertOk();
$client->postJson('/users', ['name' => 'Amina'])
    ->assertCreated()
    ->assertJsonPath('data.name', 'Amina');

$client->withHeader('X-Feature', 'fixture-request')
    ->withCookie('preference', 'compact')
    ->withHost('example.test')
    ->withScheme('https');
```

`getJson()` requests JSON responses with an `Accept` header and no GET body. `postJson()` encodes supplied data as JSON and sets both `Accept` and `Content-Type`. Ordinary `post()`, `put()`, `patch()`, and `delete()` place supplied data in the form collection. `request()` accepts method, URI, data, headers, and an optional JSON-body flag. Query parameters can be placed in the URI. The client holds its headers and cookies for later requests **within that client**; `withHeader()` replaces the same header name without regard to case, and `withoutHeader()` removes a persisted header. A new TestCase fixture starts clean. Another client built for the same fixture has separate client headers/cookies but shares that fixture's Application and array Session. The just-handled request's session remains available for immediate flash/old-input assertions; the client closes it before the **next** request so flash data ages once at that boundary. The client retains a response `Set-Cookie` value for its next request, and `withoutCookie()` removes an explicitly stored cookie.

There is no automatic login, CSRF bypass, redirect following, or network request. Middleware order is the route's actual order. Test an unsafe request both with and without the expected CSRF token when its behavior matters.

## Response assertions

`TestClient` returns `TestResponse`, a wrapper around the actual `App\Http\Response`. Assertions are fluent and use PHPUnit. Use `response()`, `status()`, `content()`, or `header($name)` when a focused assertion is clearer.

Phase 15C typed response cookies are available through `$testResponse->response()->cookies()`. The test client's cookie jar applies them in wire order for later requests, including `Cookie::forget()` deletion. The jar is scoped by cookie name for isolated Kernel testing and does not simulate a browser's Domain, Path, SameSite, or Secure matching. Inspect individual `Cookie` values and attributes directly instead of expecting a comma-joined `Set-Cookie` header.

For `response()->download()`, `file()`, and `stream()`, `TestResponse::content()` remains empty because the actual Response has a deferred body. Status and headers can be inspected without opening a file or executing a producer. A focused emission test can capture `$testResponse->response()->send()` with a controlled output buffer and a disposable source; use a real HTTP request when server header behavior matters. Do not assert a large file by loading its entire contents into a test string. See [HTTP Responses](Responses.md).

```php
$response = $client->getJson('/users/7');

$response
    ->assertStatus(200)
    ->assertHeader('Content-Type')
    ->assertJson()
    ->assertJsonPath('data.id', 7)
    ->assertJsonHas('data.email')
    ->assertJsonMissing('data.password');
```

| Assertion | Meaning |
| --- | --- |
| `assertStatus(200)`, `assertOk()`, `assertCreated()`, `assertNoContent()` | Exact status (`200`, `201`, `204` for the short forms) |
| `assertRedirect()` / `assertRedirect('/target')` | Redirect status and nonempty `Location`; optional exact destination |
| `assertHeader('Name')` / `assertHeader('Name', 'value')` | Header exists; optional strict value comparison |
| `assertJson()` | JSON media type and valid JSON body |
| `assertJsonPath('data.id', 7)` | Path exists and value matches with strict PHP identity |
| `assertJsonHas('data.email')` / `assertJsonMissing('data.password')` | Exact path presence/absence; a present `null` is still present |
| `assertContains('Welcome')` / `assertNotContains('secret')` | Literal substring presence/absence in response content |

JSON paths use only dot-separated object keys and numeric list indices (`items.0.id`). There are no wildcards, escaping rules for keys containing dots, or full JSONPath expressions. Assertion failures omit response bodies, expected values, and header contents so routine PHPUnit output does not disclose tokens or credentials. A developer can still explicitly inspect `content()` and should avoid dumping sensitive values into CI logs.

When testing request correlation, assert that the real Kernel emitted `X-Request-ID` with `assertHeader('X-Request-ID')`. Avoid matching a random ID to a hard-coded value unless the test deliberately controls that input through a supported contract.

## Direct View and Fragment assertions

Use `$this->view($name, $data)` to test full rendered HTML and `$this->fragment($rootView, $fragmentName, $data)` to test one selected Fragment. Both use the fixture's real Application and View pipeline and return `ViewTestResult` with `html()`, finalized `stack($name)`/`stacks()`, and fluent output, escaping, ordering, and stack assertions. For example:

The same helpers accept an active Package namespace: `$this->view('Commerce::Orders.Index')` and `$this->fragment('Commerce::Orders.Index', 'orders.list')`. Prepare and enable the Package before the fixture's first Application boot. See [Package and Namespaced Views](PackageViews.md).

```php
$this->view('Orders.Index', ['orders' => ['A', 'B']])
    ->assertSee('Orders')
    ->assertSeeInOrder(['A', 'B'])
    ->assertStackContains('scripts', '/assets/orders.js');

$this->fragment('Orders.Index', 'orders.list', ['orders' => ['A']])
    ->assertSee('A')
    ->assertDontSee('Page footer');
```

The same test fixture's `actingAs()`, `guest()`, `session()`, `withErrors()`, and `withOldInput()` set real Auth, Session, and browser-form state for direct renders. `assertHasCsrfField()` validates the current session-bound field without printing the token; `assertHasMethodField('PATCH')` validates method output. `assertSeeEscaped($value)` uses the real `{{ }}` escape routine. These checks test presentation; the HTTP client remains the way to test route middleware, CSRF enforcement, status, and headers. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md) for a complete fixture and exact assertion semantics.

To test `View::response()`, register a route or controller that returns it and send a request through `TestClient`. `TestResponse` then exposes the actual status, headers, and complete HTML body after middleware. Direct `$this->view(...)` remains appropriate for renderer and asset assertions but does not test the HTTP response. See [Returnable View Responses](ViewResponses.md).

## Sessions, forms, authentication, and CSRF

The test application uses real Session, browser-form, Auth, Authorization, and CSRF providers. Default CSRF protection stays on. The TestCase's `withCsrfToken()` gets a real token from the session-bound `CsrfTokenManager` and places it in the configured CSRF header (`X-CSRF-Token` by default). `withoutCsrf()` changes fixture configuration and therefore **must be called before the first Application boot**. Test CSRF enforcement without that explicit opt-out.

```php
$this->post('/profile', ['name' => 'Amina'])->assertStatus(403);

$this->withCsrfToken()
    ->post('/profile', ['name' => 'Amina'])
    ->assertRedirect('/profile');
```

For browser-form validation, assert the actual response status or `303` redirect, then inspect flash state. `assertSessionHas($key, $expected)` compares strictly when the expected value is supplied; `assertSessionMissing($key)`, `assertSessionHasErrors(['email'])`, `assertSessionHasNoErrors()`, and `assertOldInput($key, $expected)` express common form outcomes. The session helpers use the real array `SessionStore` and `ErrorBag` contracts. Sensitive old input should remain absent; do not assert or print its raw value in a test failure.

```php
$this->withCsrfToken()->post('/register', [
    'email' => 'not-an-email',
    'password' => 'fake-test-only-secret',
])->assertRedirect();

$this->assertSessionHasErrors(['email']);
$this->assertOldInput('email', 'not-an-email');
self::assertNull($this->session()->old('password'));
```

`actingAs($identity, $guard = null)` calls the configured stateful Auth guard's normal `login()` path; `guest($guard = null)` calls its `logout()` path. The identity must implement the real `Authenticatable` contract. A token-only or other non-stateful guard is rejected rather than faking a login. These methods do not bypass Authorization middleware.

```php
$this->actingAs($user)->get('/dashboard')->assertOk();
$this->guest()->get('/dashboard')->assertRedirect('/login');
```

The exact guest response depends on the application's route, middleware, and response policy. A `403` assertion on a protected route proves the exercised policy result for that fixture; it is not a replacement for Authorization tests across relevant identities and abilities. Separate TestCase instances get fresh Applications, and teardown resets framework resolver bridges. Do not set a global “authorized” switch or disable Authorization middleware to make a test pass.

## Frontend integration checks

Keep `Config/Frontend.php` in the disposable fixture. For a native module entry, create a real file under that fixture's `public/assets/` and render a View containing `@frontend('app')`; assert the finalized `head`, `styles`, and `scripts` output and the mounted public URL. A selected Vite production entry needs a matching disposable manifest **and** referenced output files. Assert that production HTML has no Vite HMR client or localhost URL. The ordinary PHP/default adapter test does not need Node, npm, or an asset build. See [frontend assets](FrontendAssets.md) and [profiles](FrontendProfiles.md).

Phase 22's final Windows and user-run native Linux suites both passed **2,813 tests** with zero failures/errors. The native Linux qualification ran all 17 focused frontend/base-path/Dev files (**103 tests, 693 assertions**), separate real Vite/React/Vue builds, and a supervised HMR/proxy smoke. These are bounded platform results, not proof of every web server or deployment layout. See [release readiness](ReleaseReadiness.md) for versions, assertions, skips, and remaining gates.

For an enabled [SPA policy](SpaRouting.md), send requests through the real Kernel with `Accept: text/html`. Prove that an eligible deep link receives the dynamic View with `Cache-Control: private, no-store`, while a normal route and 405 retain precedence and missing `/api/*`, `/assets/*`, unsafe, JSON, and non-navigation requests do not receive that shell. Repeat with `APP_BASE_PATH` to confirm application-relative prefixes. A direct View render alone cannot prove these HTTP boundaries.

For [Session-backed frontend Auth](FrontendAuth.md), obtain the current token from the rendered shell, use it in `X-CSRF-Token` for login and logout, and assert that missing or invalid values stop before the controller. Check Session identity across requests, Session ID regeneration, and token invalidation on logout. A separate Bearer-only route must still require its named token guard even when a browser Session is active; its deliberate CSRF exclusion is path-specific. Do not disable global CSRF to make the frontend test pass.

## Typed application data checks

Test both supported entry points: keep an array-only `$request->validate([...])` case, then exercise `Request::validatedAs()` with explicit rules and with `ValidatedData::rules()`. Assert that the existing Validator runs first, extra request keys never reach the constructor, canonical scalar and backed-enum conversions are deliberate, nullable/default parameters work, and `#[NestedData]` is required for nested construction. Missing or malformed input belongs to the ordinary safe validation response; unsupported definitions and constructor invariants are application errors without submitted values in their public messages. Use separate Applications to check mapping and typed-payload registry isolation. See [Typed application data](ApplicationData.md).

For browser forms, send real Kernel GET/POST requests through Session and CSRF. Verify invalid CSRF returns 403 before construction, invalid fields return the established 303 with ErrorBag and filtered old input, and a valid request passes the typed object to the application service. For JSON/API paths, verify 422 `validation_failed` and generic 500 boundaries, named token Auth and CORS regressions, and an `ApiResource` that returns only selected fields from its typed source. Existing array and Model Resource tests remain required.

For selected asynchronous values, register an explicit alias/version on both sender and worker Applications, then verify `QueuePayloadData` round trips through the existing Queue job payload and 60,000-byte outer limit. Reject unknown alias/version, malformed envelopes, non-JSON objects, and secret-bearing undeclared fields; keep legacy scalar/array job payloads and ordinary synchronous Events working. Queue retries remain at least once. Do not treat an in-process round trip as proof that a separate worker process can reconstruct the value; use the existing process-boundary fixture where that contract is claimed.

**Phase 23A–23E is complete, implemented, tested, and cross-platform qualified** on the supported Windows and user-run native Linux qualification paths. The six Windows focused files passed **33 tests/286 assertions**; seven native Linux focused files, including QueueFoundationTest, passed **45 tests/361 assertions**. See [release readiness](ReleaseReadiness.md) for per-file and full-suite results.

## Database, Seeders, and local services

The fixture's default database is SQLite `:memory:`. For file-backed SQLite, configure the database path inside `Storage/` **before boot**, as shown above. Use real Schema/ORM/Migration APIs to build a test-specific schema; do not point the fixture at application `DB_*` values. TestCase's `migrate()` explicitly runs fixture Migrations; `seed('DatabaseSeeder')` explicitly runs a named fixture Seeder. Neither runs automatically in `setUp()`. Seeders are the v2 data setup API; the v1 Dumper API is retired.

Transaction rollback can isolate a single connection, but is not proof of isolation if code commits, changes connections, or tests `afterCommit` behavior. A separate disposable database and explicit cleanup may be required. Guarded live MySQL tests need their own confirmed disposable database and opt-in settings; this fixture does not grant that opt-in.

For relation queries, create several parents, children, and registered morph
targets in the isolated SQLite schema. Count actual SQL executions rather
than inferring batching from the number of Models returned. Nested
`has()`/`whereHas()` and `withCount()` each execute one root SELECT;
`hasManyThrough()` eager loading uses the parent read plus bounded
intermediate/final reads. Assert both populated and empty paths, deleted
intermediate/final rows, pivot filters, target-specific morph callbacks,
unknown stored aliases, and a second configured SQLite connection. A count
projection must be absent from `attributes()`, dirty/original state, and the
generated UPDATE after saving a real attribute. See
[Relationships](Relationships.md) and [Models](Models.md#relation-count-projections).

The guarded MySQL relation test uses only explicit
`SQUEHUB_TEST_MYSQL_ENABLED`, `SQUEHUB_TEST_MYSQL_HOST`,
`SQUEHUB_TEST_MYSQL_PORT`, `SQUEHUB_TEST_MYSQL_DATABASE`,
`SQUEHUB_TEST_MYSQL_USER`, `SQUEHUB_TEST_MYSQL_PASSWORD`, and
`SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE` values for a confirmed disposable
`squehub_test_*` database. A default skip proves no MySQL execution. Run the
focused guarded test directly and inspect its result before treating a full
suite as a live-MySQL qualification. Never point it at application DB settings.

Prefer real array Cache and Storage drivers in a test Application. Use the existing Sync Queue for immediate, deterministic Queue-path tests; use a database/Redis Queue and a separate worker only when testing persistence across processes. Use each subsystem's real array/recording driver where available for Mail or Notifications rather than creating a parallel fake framework. Keep expiration tests on existing injectable Clock contracts; do not weaken production randomness or rely on sleeps.

## Package routes and provenance

Place a fixture Package under `Project/Packages/<PackageName>/` in the disposable root, use the normal Package lifecycle to enable it before application boot, then request its route with `TestClient`. This exercises the normal enabled-Package loader rather than a testing-only router. Test the disabled case with a separate fixture so activation state cannot leak. **Use a distinct Package PHP class name for each fixture across the PHPUnit process.** PHP class definitions persist after a temporary project root is cleaned up; two fixtures with the same fully qualified class name can collide even when their files live in different roots. Inspect the structured [contribution registry](Contributions.md) for route, middleware, binding, View namespace, selected View, and Scheduler ownership; parsing `package:inspect` text is unnecessary. Kits have their own reviewed lifecycle; there is no separate Kit-specific HTTP test runtime.

For a larger application, create several fixture Packages with declared `requires` metadata, enable requirements before dependents, and exercise their routes through the same TestCase Application. Include a zero-Package case and a mixed root-route/Package-route case so the architecture stays optional. Assert one registration per shared dependency, deterministic activation order, disabled-route absence, and ownership through the contribution registry. See [Large applications](LargeApplications.md) for the dependency and public-API conventions. Package-local `Tests/` files are development source and do not load as runtime contributions.

For example, a TestCase can install its own Package-shaped fixture and enable it through the real manager before the first HTTP request:

```php
use App\Foundation\Application;
use App\Packages\PackageManager;

$testing = $this->testApplication();
$testing->write('Project/Packages/TestingGuideForecast/TestingGuideForecast.php', <<<'PHP'
<?php

namespace Packages\TestingGuideForecast;

final class TestingGuideForecast extends \App\Plugins\ServiceProvider
{
}
PHP);
$testing->write('Project/Packages/TestingGuideForecast/Routes/Web.php', <<<'PHP'
<?php

\App\Plugins\Route::path('/forecast-fixture')
    ->get(static fn (): string => 'forecast');
PHP);

$packages = new PackageManager(new Application($testing->root()));
$packages->apply($packages->planEnable('TestingGuideForecast'));

$this->get('/forecast-fixture')->assertOk()->assertContains('forecast');
```

The fixture uses a private temporary root. The Package manager still applies its normal activation checks and plan; the next boot loads the enabled Package. For production Package operations, review the plan and use the deliberate CLI flow described in [Packages](Packages.md).

Phase 13D's `ChangePlan` values are ordinary production objects: tests can inspect actions, risk, conflicts, preconditions, and fingerprint with PHPUnit. There is no need to put PHPUnit assertion methods on a production plan. [Reviewable changes](ReviewableChanges.md) describes the current plan boundary.

## Testing and API contract verification

Developer-authored PHPUnit tests exercise behavior and branches the developer chooses. [`Contract::verify()` and `contract:verify`](ApiVerification.md) compare the native API contract with route metadata and explicit verification cases. The contract gate does not replace integration tests, and a passing integration test does not prove every declared API operation matches its contract. Both can use the real Kernel, but they answer different questions.

Direct `$this->view(...)` and `$this->fragment(...)` assertions use the same [compiled View lifecycle](CompiledViews.md) as HTTP rendering. A changed source selects a new artifact, while a recoverable damaged artifact is rebuilt; the test helper does not substitute a fake renderer. Use isolated temporary View and Storage roots when testing `view:cache`, `view:clear`, corruption recovery, or concurrent compilation. Clearing compiled Views in a test must not point at the developer's application runtime directory. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md) for the assertion API.

## Safety and distribution boundary

Phase 18 Queue composition tests exercise Sync and real isolated SQLite state, reservation fencing, cancellation, retry, and coordinated worker subprocesses. Queued Event tests also use separate PHP enqueue and worker processes. The guarded `MySqlQueueCompositionOptInTest` and `RedisCompositionLiveTest` skip unless disposable backend settings are explicitly supplied; their default skip is not live-backend evidence. The user subsequently reported native Linux/WSL PHP 8.5.4, disposable MySQL 8.4.11/InnoDB composition (**1 test/66 assertions**), and selected live Redis client composition (**30 assertions**) passing. The alternate optional Redis client dataset skipped. Test-owned MySQL and Redis resources were removed and opt-in variables unset. One Scheduler CLI occurrence-boundary timing failure on a first post-cleanup run did not reproduce in the exact test, file, five repeats, or subsequent full clean Linux suite; keep the test's wall-clock determinism reviewable. See [release readiness](ReleaseReadiness.md), [Queue composition](QueueComposition.md), and [Redis Queue](RedisQueue.md). Codex did not execute these live/backend Linux runs.

Keep fixtures in owned temporary roots. Never use production database, Redis, Mail, or Queue settings as a test default. Plant fake secrets for privacy tests and assert they remain out of diagnostics and failure output. If a test needs live infrastructure, opt in through a separately confirmed disposable resource and report exactly what ran.

Phase 24 [Lock tests](Locks.md#verification) cover deterministic array/Redis-command behavior and actual child-process file and SQLite contention. `TestApplication` defaults to an array lock store and permits only array, file, or disposable SQLite database lock backends. Guarded Redis and MySQL tests skip without explicit live settings; a skip alone does not qualify a backend. The user later ran the guarded Lock and Idempotency tests against disposable Redis 8.0.5 and MySQL 8.4.11 services; all four passed. The user-run native Linux focused/regression set passed **96 tests and 1,817 assertions**, including file/SQLite process races on a confirmed case-sensitive native filesystem and a shared temporary SQLite file. The final Linux suite passed **2,924 tests, 22,122 assertions, 31 skips, zero failures, and zero errors**. Do not substitute SQLite `:memory:` or `/mnt/d` file locks for native process-coordination proof. See [Phase 24 release evidence](ReleaseReadiness.md#phase-24-reliability-qualification).

For optional [Agent/MCP integration](AgentAndAI.md), test the local manager and a real STDIO client separately. Assert that no grant can read `.env`, write source, run a migration, manage a Package, execute shell/network operations, or self-escalate. Exercise invalid scopes, malicious paths, oversize inputs, schema metadata without rows, plan-only responses, stdout protocol purity, stderr errors, clean shutdown, and repeated Application isolation. Native Linux should rerun actual STDIO and filesystem containment cases on a case-sensitive filesystem; Windows success alone is not that qualification. Use the exact focused files and results in [release readiness](ReleaseReadiness.md).

Phase 25A–25F has now passed that user-run native Linux gate: **22 focused Agent tests/219 assertions/0 skips** and **2,946 full-suite tests/22,364 assertions/31 skips**, with zero failures and errors. The official SDK client STDIO case executed and passed on Linux; the disposable qualification directory was removed. The Windows full suite passed **2,946/22,117/90 skips** with zero failures and errors. These are Phase 25 results, not Phase 26 or final release qualification.

At this development gate, the core repository's `Docs/` and `Tests/` directories are ignored by Git at the owner's request. A normal checkout of current `HEAD` cannot reproduce this local suite or documentation. The intended release artifact needs an explicit source/test distribution decision and clean-checkout proof before reproducibility can be claimed.
