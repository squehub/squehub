<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Auth\AuthException;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log;
use App\Logging\LoggingServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** End-to-end authentication against disposable SQLite and array sessions. */
final class AuthIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private SessionManager $sessions;
    private DatabaseManager $database;
    private Kernel $kernel;
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["enabled" => true, "field" => "_csrf", "header" => "X-CSRF-Token", "except" => []];');
        $this->project->write('Config/Logging.php', '<?php return ["driver" => "array", "level" => "debug"];');
        $this->project->write('Config/Auth.php', '<?php return '
            . var_export(self::authConfig(), true) . ';');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class, DatabaseServiceProvider::class,
            SessionServiceProvider::class, CsrfServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, AuthServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->auth = $container->make(AuthManager::class);
        $this->sessions = $container->make(SessionManager::class);
        $this->database = $container->make(DatabaseManager::class);
        $this->kernel = $container->make(Kernel::class);
        $this->diagnostics = $container->make(Diagnostics::class);
        $this->database->schema()->create('auth_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->string('name');
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();
            $table->datetime('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Auth::setResolver(null);
        Log::setResolver(null);
        Session::setResolver(null);
        Database::setResolver(null);
        Csrf::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    private static function authConfig(): array
    {
        return [
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users'],
                'admin' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => AuthTestUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => true, 'max_bytes' => 4096],
            'browser' => ['login_path' => '/login', 'authenticated_path' => '/home'],
        ];
    }

    private function user(string $email = 'ada@example.test', string $password = 'correct'): AuthTestUser
    {
        return AuthTestUser::create(['email' => $email, 'name' => 'Ada',
            'password' => $this->app->container()->make(PasswordHasher::class)->hash($password)]);
    }

    public function testAttemptSessionPrivacyRotationAndGlobalLogout(): void
    {
        $user = $this->user();
        $store = $this->sessions->store();
        $store->put('cart', 'kept');
        $store->setCsrfToken('test-token');
        $before = $store->id();
        self::assertFalse($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'wrong']));
        self::assertSame($before, $store->id());
        self::assertFalse($this->auth->check());
        self::assertTrue($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        self::assertNotSame($before, $store->id());
        self::assertSame((string) $user->id, (string) $this->auth->id());
        self::assertSame('kept', $store->get('cart'));
        self::assertSame('test-token', $store->csrfToken());
        self::assertSame(['cart' => 'kept'], $store->all());
        self::assertStringNotContainsString('correct', serialize($store->all()));
        $admin = $this->auth->guard('admin');
        self::assertSame($admin, $this->auth->guard('admin'));
        $admin->login($user);
        self::assertTrue($admin->check());
        $admin->logout();
        self::assertFalse($this->auth->check());
        self::assertFalse($admin->check());
        self::assertSame([], $store->all());
        self::assertNull($store->csrfToken());
    }

    public function testUnknownStaleAndSoftDeletedIdentities(): void
    {
        $user = $this->user();
        self::assertFalse($this->auth->attempt(['email' => 'absent@example.test', 'password' => 'correct']));
        self::assertTrue($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        $this->auth->resetRequestState();
        $user->delete();
        self::assertNull($this->auth->user());
        self::assertTrue($this->auth->guest());
        self::assertNull($this->sessions->store()->authIdentifier('web'));
        self::assertFalse($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        self::assertSame(1, $this->database->table('auth_users')->count());
        try {
            $this->auth->login($user);
            self::fail('A soft-deleted identity was accepted for manual login.');
        } catch (AuthException) {
            self::assertNull($this->sessions->store()->authIdentifier('web'));
        }
    }

    public function testRehashUpdatesOnlyPasswordAndInvalidCredentialsDoNotLogin(): void
    {
        $old = password_hash('correct', PASSWORD_BCRYPT, ['cost' => 4]);
        $user = AuthTestUser::create(['email' => 'ada@example.test', 'name' => 'Ada', 'password' => $old]);
        $beforeUpdatedAt = $this->database->table('auth_users')->filter('id', $user->id)->first()['updated_at'];
        self::assertFalse($this->auth->attempt(['email' => [], 'password' => 'correct']));
        self::assertFalse($this->auth->attempt(['email' => 'ada@example.test']));
        try {
            $this->auth->attempt(['emali' => 'ada@example.test', 'password' => 'correct']);
            self::fail('Unknown credential field was accepted.');
        } catch (AuthException) {
            self::assertFalse($this->auth->check());
        }
        $this->app->config()->set('auth.passwords.options', ['cost' => 5]);
        $stronger = new AuthManager($this->app->config(), $this->sessions,
            new PasswordHasher($this->app->config()), $this->diagnostics);
        $this->diagnostics->begin(new Request('POST', '/login'));
        self::assertTrue($stronger->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        self::assertSame(2, $this->diagnostics->queryCount());
        $record = $this->database->table('auth_users')->filter('id', $user->id)->first();
        self::assertSame('Ada', $record['name']);
        self::assertSame($beforeUpdatedAt, $record['updated_at']);
        self::assertNotSame($old, $record['password']);
        self::assertTrue(password_verify('correct', $record['password']));
        self::assertSame(1, $this->diagnostics->snapshot()['auth']['successes']);
        self::assertSame(1, $this->diagnostics->snapshot()['auth']['logins']);
        self::assertSame(0, $this->diagnostics->snapshot()['auth']['errors']);
        $snapshot = json_encode($this->diagnostics->snapshot(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ada@example.test', $snapshot);
        self::assertStringNotContainsString('correct', $snapshot);
        self::assertStringNotContainsString($record['password'], $snapshot);
    }

    public function testRequestCacheReloadsOneIdentityPerRequest(): void
    {
        $user = $this->user();
        $this->auth->login($user);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/identity', function (): string {
            $first = $this->auth->user();
            self::assertSame($first, $this->auth->user());
            self::assertTrue($this->auth->check());
            return (string) $this->auth->id();
        })->through('auth');
        $first = $this->kernel->handle(new Request('GET', '/identity'));
        self::assertSame((string) $user->id, $first->content());
        self::assertSame(1, $this->diagnostics->queryCount());
        $this->sessions->store()->close();
        $second = $this->kernel->handle(new Request('GET', '/identity'));
        self::assertSame((string) $user->id, $second->content());
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testCsrfPrecedesAuthAndNoPathFallsBackToSafeStatus(): void
    {
        $this->user();
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->post('/protected', static fn (): string => 'controller')->through('auth');
        $bad = $this->kernel->handle(new Request('POST', '/protected', [], ['password' => 'private']));
        self::assertSame(403, $bad->status());
        self::assertStringContainsString('CSRF', $bad->content());
        $token = $this->app->container()->make(\App\Security\Csrf\CsrfTokenManager::class)->token();
        $this->sessions->store()->close();
        $goodToken = $this->kernel->handle(new Request('POST', '/protected', [], ['_csrf' => $token]));
        self::assertSame(303, $goodToken->status());
        $this->app->config()->set('auth.browser.login_path', null);
        $this->sessions->store()->close();
        $routes->get('/protected', static fn (): string => 'controller')->through('auth');
        $fallback = $this->kernel->handle(new Request('GET', '/protected'));
        self::assertSame(401, $fallback->status());
    }

    public function testHttpAuthGuestMiddlewareAndRequestDiagnostics(): void
    {
        $user = $this->user();
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/secret', static fn (): string => 'secret')->through('auth');
        $routes->get('/login', static fn (): string => 'login')->through('guest');
        $guestJson = $this->kernel->handle(new Request('GET', '/secret', [], [], [], [], ['Accept' => 'application/json']));
        self::assertSame(401, $guestJson->status());
        self::assertSame('{"message":"Authentication required."}', $guestJson->content());
        self::assertSame(0, $this->diagnostics->queryCount());
        $guestHtml = $this->kernel->handle(new Request('GET', '/secret'));
        self::assertSame(303, $guestHtml->status());
        self::assertSame('/login', $guestHtml->header('Location'));
        $this->auth->login($user);
        $good = $this->kernel->handle(new Request('GET', '/secret'));
        self::assertSame(200, $good->status());
        self::assertSame('secret', $good->content());
        $guestOnly = $this->kernel->handle(new Request('GET', '/login', [], [], [], [], ['Accept' => 'application/json']));
        self::assertSame(403, $guestOnly->status());
        self::assertSame('{"message":"Guest access required."}', $guestOnly->content());
        $guestBrowser = $this->kernel->handle(new Request('GET', '/login'));
        self::assertSame(303, $guestBrowser->status());
        self::assertSame('/home', $guestBrowser->header('Location'));
        $this->app->config()->set('auth.browser.authenticated_path', null);
        $guestFallback = $this->kernel->handle(new Request('GET', '/login'));
        self::assertSame(403, $guestFallback->status());
        $this->kernel->handle(new Request('GET', '/missing'));
        self::assertSame(0, $this->diagnostics->snapshot()['auth']['attempts']);
        self::assertSame(0, $this->diagnostics->snapshot()['auth']['logins']);
    }

    public function testProviderConfigurationAndHelper(): void
    {
        self::assertSame($this->auth, auth());
        self::assertInstanceOf(AuthTestUser::class, $this->auth->guard('web')->user() ?? $this->user());
        self::assertInstanceOf(PasswordHasher::class, $this->app->container()->make(PasswordHasher::class));
        foreach ([
            ['default' => 'missing'],
            ['guards' => ['web' => ['driver' => 'unknown', 'identity' => 'users']]],
            ['identities' => ['users' => ['driver' => 'unknown']]],
            ['browser' => ['login_path' => '//evil.test', 'authenticated_path' => null]],
            ['browser' => ['login_path' => "\r\nLocation: /evil", 'authenticated_path' => null]],
            ['identities' => ['users' => ['identifier' => 'id;DROP']]],
            ['identities' => ['users' => ['credentials' => ['password']]]],
        ] as $replace) {
            $config = new Repository(['auth' => array_replace_recursive(self::authConfig(), $replace)]);
            $this->expectInvalidConfig($config);
        }
        $empty = new AuthManager(new Repository(['auth' => ['default' => null, 'guards' => [], 'identities' => []]]),
            $this->sessions, new PasswordHasher(new Repository()));
        $this->expectException(AuthException::class);
        $empty->check();
    }

    public function testNativeSessionRotatesPersistsAndInvalidates(): void
    {
        $directory = $this->project->path('native-sessions');
        mkdir($directory);
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root . '/Tests/Fixtures/AuthNativeSessionProbe.php', $directory], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($result['before'], $result['afterLogin']);
        self::assertSame(17, $result['restoredId']);
        self::assertSame('kept', $result['cart']);
        self::assertNotSame($result['afterLogin'], $result['afterLogout']);
        self::assertTrue($result['guest']);
        self::assertSame([], $result['all']);
    }

    public function testRehashCanBeDisabledWithoutBlockingValidCredentials(): void
    {
        $old = password_hash('correct', PASSWORD_BCRYPT, ['cost' => 4]);
        $user = AuthTestUser::create(['email' => 'ada@example.test', 'name' => 'Ada', 'password' => $old]);
        $this->app->config()->set('auth.passwords.options', ['cost' => 5]);
        $this->app->config()->set('auth.passwords.rehash_on_login', false);
        $manager = new AuthManager($this->app->config(), $this->sessions,
            new PasswordHasher($this->app->config()));
        self::assertTrue($manager->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        $record = $this->database->table('auth_users')->filter('id', $user->id)->first();
        self::assertSame($old, $record['password']);
    }

    public function testSuccessfulAttemptWithoutRehashUsesOneIdentityQuery(): void
    {
        $this->user();
        $this->diagnostics->begin(new Request('POST', '/login'));
        self::assertTrue($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        self::assertSame(1, $this->diagnostics->queryCount());
        self::assertTrue($this->auth->check());
        self::assertSame(1, $this->diagnostics->queryCount());
    }

    public function testDiagnosticsCountsWithoutCredentialMetadata(): void
    {
        $user = $this->user();
        $this->diagnostics->begin(new Request('POST', '/login'));
        self::assertFalse($this->auth->attempt(['email' => 'unknown@example.test', 'password' => 'secret-marker']));
        self::assertFalse($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'secret-marker']));
        self::assertTrue($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        $this->auth->login($user);
        $this->auth->logout();
        try {
            $this->auth->attempt(['typo' => 'sensitive', 'password' => 'secret-marker']);
            self::fail('Unknown credential key was accepted.');
        } catch (AuthException) {
            self::assertTrue(true);
        }
        self::assertSame(['attempts' => 4, 'successes' => 1, 'failures' => 2,
            'logins' => 2, 'logouts' => 1, 'errors' => 1], $this->diagnostics->snapshot()['auth']);
        $serialized = json_encode($this->diagnostics->snapshot(), JSON_THROW_ON_ERROR);
        foreach (['unknown@example.test', 'ada@example.test', 'secret-marker', 'sensitive'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testIdentityDatabaseFailureDoesNotBecomeBadCredentials(): void
    {
        $store = $this->sessions->store();
        $before = $store->id();
        $this->database->connection()->pdo()->exec('DROP TABLE auth_users');
        try {
            $this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']);
            self::fail('Database failure was treated as incorrect credentials.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertSame($before, $store->id());
            self::assertNull($store->authIdentifier('web'));
        }
    }

    public function testModelProviderLookupAndPasswordUpdateBoundaries(): void
    {
        $user = $this->user();
        $provider = new \App\Auth\Identity\ModelIdentityProvider(AuthTestUser::class,
            'id', 'password', ['email']);
        self::assertSame((string) $user->id, (string) $provider->retrieveById($user->id)?->authIdentifier());
        self::assertSame((string) $user->id, (string) $provider->retrieveByCredentials([
            'email' => 'ada@example.test',
        ])?->authIdentifier());
        self::assertNull($provider->retrieveByCredentials(['email' => 'missing@example.test']));
        self::assertTrue($provider->supports($user));
        try {
            $provider->retrieveByCredentials(['role' => 'admin']);
            self::fail('Unknown lookup field was accepted.');
        } catch (AuthException) {
            self::assertTrue(true);
        }
        $new = password_hash('changed', PASSWORD_BCRYPT, ['cost' => 4]);
        $beforeUpdatedAt = $this->database->table('auth_users')->filter('id', $user->id)->first()['updated_at'];
        $user->name = 'Pending edit';
        $provider->updatePassword($user, $new);
        $row = $this->database->table('auth_users')->filter('id', $user->id)->first();
        self::assertSame($new, $row['password']);
        self::assertSame('Ada', $row['name']);
        self::assertSame($beforeUpdatedAt, $row['updated_at']);
        self::assertSame('Pending edit', $user->name);
        self::assertSame('Ada', $user->original('name'));
        self::assertTrue($user->changed('name'));
        self::assertFalse($user->changed('password'));
        $user->delete();
        self::assertNull($provider->retrieveById($user->id));
        self::assertFalse($provider->supports($user));
    }

    public function testPasswordUpdateFailurePreservesModelState(): void
    {
        $user = $this->user();
        $old = $user->authPasswordHash();
        $provider = new \App\Auth\Identity\ModelIdentityProvider(AuthTestUser::class,
            'id', 'password', ['email']);
        $this->database->table('auth_users')->filter('id', $user->id)->delete();
        try {
            $provider->updatePassword($user, password_hash('new', PASSWORD_BCRYPT, ['cost' => 4]));
            self::fail('Missing row was treated as a successful hash update.');
        } catch (AuthException) {
            self::assertSame($old, $user->authPasswordHash());
            self::assertSame($old, $user->original('password'));
            self::assertTrue($user->exists());
        }
    }

    public function testAuthProducesNoRoutineLogsAndInfrastructureErrorLogsOnce(): void
    {
        $this->user();
        $logs = $this->app->container()->make(ArrayLogger::class);
        self::assertFalse($this->auth->attempt(['email' => 'private@example.test', 'password' => 'private-pass']));
        self::assertFalse($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'private-pass']));
        self::assertTrue($this->auth->attempt(['email' => 'ada@example.test', 'password' => 'correct']));
        self::assertSame([], $logs->records());
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/auth-failure', function (): bool {
            return $this->auth->attempt(['email' => 'private@example.test', 'password' => 'private-pass']);
        });
        $this->database->connection()->pdo()->exec('DROP TABLE auth_users');
        $response = $this->kernel->handle(new Request('GET', '/auth-failure', [], [], [], [], ['Accept' => 'application/json']));
        self::assertSame(500, $response->status());
        self::assertCount(1, $logs->records());
        $text = $response->content() . json_encode($logs->records(), JSON_THROW_ON_ERROR);
        foreach (['private@example.test', 'private-pass', 'correct'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    private function expectInvalidConfig(Repository $config): void
    {
        try {
            new AuthManager($config, $this->sessions, new PasswordHasher($config));
            self::fail('Invalid authentication configuration was accepted.');
        } catch (AuthException) {
            self::assertTrue(true);
        }
    }
}

/** Fixture identity follows the ordinary modern Model and hidden-attribute contracts. */
final class AuthTestUser extends Model implements Authenticatable
{
    protected string $table = 'auth_users';
    protected array $fillable = ['email', 'password', 'name'];
    protected array $hidden = ['password'];
    protected bool $softDeletes = true;
    protected bool $timestamps = true;

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
