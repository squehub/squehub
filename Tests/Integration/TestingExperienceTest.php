<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\Request;
use App\Http\Response;
use App\Packages\PackageManager;
use App\Plugins\TestCase;
use PDO;

/** A route middleware fixture resolved by the ordinary SqueHub pipeline. */
final class TestingExperienceMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request)->withHeader('X-Test-Pipeline', 'visited');
    }
}

/** A persisted identity fixture for real session-guard testing. */
final class TestingExperienceUser extends Model implements Authenticatable
{
    protected string $table = 'testing_users';
    protected array $fillable = ['email', 'password'];
    protected array $guarded = [];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/** Dogfoods the public testing API against real providers and route loading. */
final class TestingExperienceTest extends TestCase
{
    public function testGetFormJsonMiddlewareRedirectAndSafeErrors(): void
    {
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/welcome')->get(static fn (): string => 'Welcome')
    ->through(new \SqueHub\Tests\Integration\TestingExperienceMiddleware());
\App\Routing\Route::path('/echo')->post(static function (\App\Http\Request $request): array {
    return ['data' => ['name' => $request->input('name'), 'page' => $request->query('page')]];
});
\App\Routing\Route::path('/go')->get(static fn (): \App\Http\Response =>
    new \App\Http\RedirectResponse('/welcome', 303));
\App\Routing\Route::path('/client-context')->get(static function (\App\Http\Request $request): array {
    return ['data' => [
        'header' => $request->header('X-Fixture'),
        'cookie' => $request->cookie('fixture'),
        'host' => $request->host(),
        'scheme' => $request->scheme(),
    ]];
});
\App\Routing\Route::path('/explode')->get(static function (): never {
    throw new \RuntimeException('PRIVATE_TEST_EXCEPTION_MARKER');
});
PHP);

        $this->get('/welcome')->assertOk()->assertContains('Welcome')
            ->assertHeader('X-Test-Pipeline', 'visited');
        $this->get('/go')->assertRedirect('/welcome');
        $this->getJson('/absent')->assertStatus(404)->assertJson();
        $this->getJson('/echo')->assertStatus(405)->assertJson();
        $this->getJson('/explode')->assertStatus(500)->assertJson()
            ->assertNotContains('PRIVATE_TEST_EXCEPTION_MARKER');

        $this->withCsrfToken();
        $this->post('/echo?page=2', ['name' => 'Ada'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.name', 'Ada')
            ->assertJsonPath('data.page', '2');
        $this->postJson('/echo?page=3', ['name' => 'Bea'])
            ->assertOk()->assertJsonPath('data.name', 'Bea')
            ->assertJsonPath('data.page', '3');
        $this->client()->withHeader('X-Fixture', 'custom')->withCookie('fixture', 'remembered')
            ->withHost('example.test:8443')->withScheme('https');
        $this->getJson('/client-context')->assertOk()
            ->assertJsonPath('data.header', 'custom')
            ->assertJsonPath('data.cookie', 'remembered')
            ->assertJsonPath('data.host', 'example.test')
            ->assertJsonPath('data.scheme', 'https');
    }

    public function testSessionPersistsAndCsrfRejectsBeforeRouteExecution(): void
    {
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/counter')->get(static function (): string {
    $store = \App\Session\Session::manager()->store();
    $count = (int) $store->get('count', 0) + 1;
    $store->put('count', $count);
    return (string) $count;
});
\App\Routing\Route::path('/submit')->post(static function (): string {
    \App\Session\Session::manager()->store()->put('submitted', true);
    return 'saved';
});
PHP);

        $this->get('/counter')->assertOk()->assertContains('1');
        $this->get('/counter')->assertOk()->assertContains('2');
        $this->assertSessionHas('count', 2);
        $this->post('/submit')->assertStatus(403);
        $this->assertSessionMissing('submitted');
        $this->withCsrfToken();
        $this->post('/submit')->assertOk()->assertContains('saved');
        $this->assertSessionHas('submitted', true);
    }

    public function testBrowserValidationFlashesErrorsAndOldInputThroughRedirect(): void
    {
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/form')->get(static fn (): \App\Http\Response =>
    new \App\Http\Response('<h1>Form</h1>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
\App\Routing\Route::path('/submit')->post(static function (\App\Http\Request $request): string {
    $request->validate(['email' => 'required|email']);
    return 'stored';
});
PHP);

        $this->get('/form')->assertOk();
        $this->withCsrfToken();
        $this->post('/submit', ['email' => 'invalid'])->assertRedirect('/form');
        $this->get('/form')->assertOk();
        $this->assertSessionHasErrors(['email']);
        $this->assertOldInput('email', 'invalid');
        $this->get('/form')->assertOk();
        $this->assertSessionHasNoErrors();
    }

    public function testExplicitCsrfBypassMustPrecedeBoot(): void
    {
        $this->withoutCsrf();
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/submit')->post(static fn (): string => 'saved');
PHP);
        $this->post('/submit')->assertOk()->assertContains('saved');
    }

    public function testCsrfHelperUsesTheConfiguredHeader(): void
    {
        $this->testApplication()->configure(['csrf' => ['header' => 'X-Fixture-Csrf']]);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/submit')->post(static fn (): string => 'saved');
PHP);
        $this->post('/submit')->assertStatus(403);
        $this->withCsrfToken();
        $this->post('/submit')->assertOk()->assertContains('saved');
        $this->post('/submit', [], ['X-Fixture-Csrf' => 'wrong'])->assertStatus(403);
    }

    public function testExplicitSqliteMigrationAndSeederUseDisposableDatabase(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for application data integration.');
        }
        $this->testApplication()->write('Database/Migrations/2026_01_01_create_testing_notes.php', <<<'PHP'
<?php
final class CreateTestingNotes {
    public function up(\PDO $pdo): void {
        $pdo->exec('CREATE TABLE testing_notes (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
    }
    public function down(\PDO $pdo): void {
        $pdo->exec('DROP TABLE testing_notes');
    }
}
PHP);
        $this->testApplication()->write('Database/Seeders/TestingNotesSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class TestingNotesSeeder extends \App\Database\Seeding\Seeder {
    public function run(): void {
        \App\Database\Database::manager()->raw(
            'INSERT INTO testing_notes (label) VALUES (?)', ['seeded']);
    }
}
PHP);

        self::assertSame(['2026_01_01_create_testing_notes.php'], $this->migrate());
        self::assertSame(['Database\\Seeders\\TestingNotesSeeder'], $this->seed('TestingNotesSeeder'));
        $database = $this->app()->container()->make(DatabaseManager::class);
        self::assertSame('sqlite', $database->connection()->driver());
        self::assertSame('seeded', $database->table('testing_notes')->first()['label']);
        self::assertFileDoesNotExist($this->testApplication()->path('.env.example'));
    }

    public function testEnabledPackageRouteUsesSameDisposableApplication(): void
    {
        $this->testApplication()->write('Project/Packages/TestingDxWeather2026/TestingDxWeather2026.php',
            '<?php namespace Packages\\TestingDxWeather2026; '
            . 'final class TestingDxWeather2026 extends \\App\\Plugins\\ServiceProvider {}');
        $this->testApplication()->write('Project/Packages/TestingDxWeather2026/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path("/testing-dx-weather")'
            . '->get(static fn (): string => "forecast");');
        $planner = new PackageManager(new Application($this->testApplication()->root()));
        $planner->apply($planner->planEnable('TestingDxWeather2026'));

        $this->get('/testing-dx-weather')->assertOk()->assertContains('forecast');
        $packages = $this->app()->container()->make(PackageManager::class);
        self::assertTrue($packages->isEnabled('TestingDxWeather2026'));
        self::assertSame('package:TestingDxWeather2026',
            $this->app()->contributions()->ownerOf('route', 'GET /testing-dx-weather')?->key());
    }

    public function testInactivePackageRoutesAreNotLoaded(): void
    {
        $this->testApplication()->write('Project/Packages/TestingDxDormant2026/TestingDxDormant2026.php',
            '<?php namespace Packages\\TestingDxDormant2026; '
            . 'final class TestingDxDormant2026 extends \\App\\Plugins\\ServiceProvider {}');
        $this->testApplication()->write('Project/Packages/TestingDxDormant2026/Routes/Web.php',
            '<?php \\App\\Routing\\Route::path("/testing-dx-dormant")'
            . '->get(static fn (): string => "not available");');

        $this->getJson('/testing-dx-dormant')->assertStatus(404);
        self::assertFalse($this->app()->container()->make(PackageManager::class)->isEnabled('TestingDxDormant2026'));
        self::assertNull($this->app()->contributions()->ownerOf('route', 'GET /testing-dx-dormant'));
    }

    public function testActingAsGuestAndNamedGuardFollowRealAuthRules(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Auth identity integration.');
        }
        $this->testApplication()->configure(['auth' => [
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'identity' => 'users'],
                'admin' => ['driver' => 'session', 'identity' => 'users'],
            ],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => TestingExperienceUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
            ]],
        ]]);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/private')->get(static fn (): string => 'visible')->through('auth');
\App\Routing\Route::path('/forbidden')->get(static fn (): string => 'must not run')
    ->through(\App\Authorization\Middleware\RequireAbility::named('fixture.denied'));
PHP);
        $database = $this->app()->container()->make(DatabaseManager::class);
        $this->app()->container()->make(\App\Authorization\AuthorizationManager::class)
            ->define('fixture.denied', static fn (TestingExperienceUser $identity): bool => false);
        $database->schema()->create('testing_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        $user = TestingExperienceUser::create([
            'email' => 'ada@example.test',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        $this->getJson('/private')->assertStatus(401);
        $this->actingAs($user);
        $this->getJson('/private')->assertOk()->assertContains('visible');
        $this->getJson('/forbidden')->assertStatus(403)->assertNotContains('must not run');
        $this->guest();
        $this->getJson('/private')->assertStatus(401);
        $this->actingAs($user, 'admin');
        self::assertTrue($this->app()->container()->make(\App\Auth\AuthManager::class)
            ->guard('admin')->check());
        $this->getJson('/private')->assertStatus(401);
    }
}
