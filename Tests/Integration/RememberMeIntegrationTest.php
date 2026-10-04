<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\AccountSecurity\AccountSecurity;
use App\AccountSecurity\AccountSecurityManager;
use App\AccountSecurity\AccountSecurityServiceProvider;
use App\Auth\Auth;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
use App\Auth\Remember\RememberTokenRecord;
use App\Auth\Remember\Repositories\DatabaseRememberTokenRepository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Http\Cookie;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real HTTP, Session, and SQLite lifecycle for optional remembered browsers. */
final class RememberMeIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private AccountSecurityManager $security;
    private DatabaseManager $database;
    private SessionManager $sessions;
    private Kernel $kernel;
    private string $password = 'correct';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default"=>"main","connections"=>["main"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver"=>"array","path"=>"/"];');
        $this->project->write('Config/Http.php', '<?php return ["base_path"=>"/mount"];');
        $this->project->write('Config/AccountSecurity.php', '<?php return ["tokens"=>["driver"=>"database","table"=>"account_security_tokens"]];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export(self::authConfig('database'), true) . ';');
        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class, AuthServiceProvider::class,
            AccountSecurityServiceProvider::class] as $provider) $this->app->register($provider);
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->auth = $container->make(AuthManager::class);
        $this->security = $container->make(AccountSecurityManager::class);
        $this->database = $container->make(DatabaseManager::class);
        $this->sessions = $container->make(SessionManager::class);
        $this->kernel = $container->make(Kernel::class);
        $this->database->schema()->create('remember_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();
            $table->datetime('deleted_at')->nullable();
        });
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_remember_tokens.php';
        (new \CreateRememberTokens())->up($this->database->connection()->pdo(), $this->database->schema());
        $this->database->schema()->create('account_security_tokens', static function (Table $table): void {
            $table->string('token_hash', 64);
            $table->string('purpose', 32);
            $table->string('guard', 64);
            $table->string('identity_identifier', 255);
            $table->string('context_hash', 64);
            $table->datetime('expires_at');
            $table->datetime('created_at');
            $table->unique('token_hash');
        });
        $routes = $container->make(RouteRegistry::class);
        $routes->get('/normal-login', fn (): string => $this->auth->attempt([
            'email' => 'ada@example.test', 'password' => $this->password]) ? 'ok' : 'no');
        $routes->get('/remember-login', fn (): string => $this->auth->attempt([
            'email' => 'ada@example.test', 'password' => $this->password], true) ? 'ok' : 'no');
        $routes->get('/secret', static fn (): string => 'secret')->through('auth');
        $routes->get('/admin-check', fn (): string => $this->auth->guard('admin')->check() ? 'yes' : 'no');
        $routes->get('/logout', function (): string { $this->auth->logout(); return 'out'; });
        $routes->get('/recall-logout', function (): string {
            $this->auth->check();
            $this->auth->logout();
            return 'out';
        });
        $routes->get('/issue-then-fail', function (): string {
            $this->auth->attempt(['email' => 'ada@example.test', 'password' => $this->password], true);
            $this->auth->attempt(['email' => 'ada@example.test', 'password' => 'wrong']);
            return 'done';
        });
    }

    protected function tearDown(): void
    {
        AccountSecurity::setResolver(null);
        Auth::setResolver(null);
        Database::setResolver(null);
        Session::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    private static function authConfig(string $driver): array
    {
        return [
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users'],
                'admin' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => RememberTestUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => false, 'max_bytes' => 4096],
            'browser' => ['login_path' => '/login'],
            'remember' => ['enabled' => true, 'driver' => $driver, 'table' => 'remember_tokens',
                'ttl' => 86400],
        ];
    }

    private function user(): RememberTestUser
    {
        return RememberTestUser::create(['email' => 'ada@example.test',
            'password' => $this->app->container()->make(PasswordHasher::class)->hash('correct')]);
    }

    /** @param array<string,mixed> $cookies */
    private function request(string $path, array $cookies = []): Response
    {
        return $this->kernel->handle(new Request('GET', '/mount' . $path, [], [], $cookies,
            [], [], ['HTTPS' => 'on']));
    }

    private static function rememberCookie(Response $response, string $guard = 'web'): Cookie
    {
        foreach ($response->cookies() as $cookie) {
            if ($cookie->name() === 'squehub_remember_' . $guard) return $cookie;
        }
        self::fail('Remember cookie was not attached to the response.');
    }

    private function loseSession(): void { $this->sessions->store()->invalidate(); }

    public function testOptInRotationSessionFixationAndLogout(): void
    {
        $this->user();
        $normal = $this->request('/normal-login');
        self::assertSame('ok', $normal->content());
        self::assertCount(0, $normal->cookies());
        self::assertCount(0, $this->database->table('remember_tokens')->all());
        $this->loseSession();

        $login = $this->request('/remember-login');
        self::assertSame('ok', $login->content());
        $first = self::rememberCookie($login);
        self::assertSame('/mount', $first->path());
        self::assertTrue($first->secure());
        self::assertTrue($first->httpOnly());
        self::assertSame('Lax', $first->sameSite());
        self::assertSame(86400, $first->maxAge());
        [$firstSelector, $validator] = explode('.', $first->value());
        $row = $this->database->table('remember_tokens')->first();
        self::assertSame($firstSelector, $row['selector']);
        self::assertSame(hash('sha256', $validator), $row['validator_hash']);
        self::assertFalse(str_contains(json_encode($row, JSON_THROW_ON_ERROR), $validator));
        $this->loseSession();
        $anonymousId = $this->sessions->store()->id();

        $recalled = $this->request('/secret', [$first->name() => $first->value()]);
        self::assertSame(200, $recalled->status());
        self::assertSame('secret', $recalled->content());
        self::assertNotSame($anonymousId, $this->sessions->store()->id());
        $second = self::rememberCookie($recalled);
        self::assertFalse(hash_equals($first->value(), $second->value()), 'Remember credential must rotate.');
        self::assertNull($this->database->table('remember_tokens')->filter('selector', $firstSelector)->first());
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$first->name() => $first->value()])->status());

        $recalledAgain = $this->request('/secret', [$second->name() => $second->value()]);
        self::assertSame(200, $recalledAgain->status());
        $third = self::rememberCookie($recalledAgain);
        $logout = $this->request('/logout', [$third->name() => $third->value()]);
        self::assertSame('out', $logout->content());
        self::assertSame(0, self::rememberCookie($logout)->maxAge());
        self::assertCount(0, $this->database->table('remember_tokens')->all());
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$third->name() => $third->value()])->status());
    }

    public function testTrustedHttpsProxyKeepsMountedRememberCookieSecureWithBrowserPolicy(): void
    {
        $this->app->config()->set('trustedProxies', [
            'proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded',
        ]);
        $this->app->config()->set('security.browser', [
            'enabled' => true, 'hsts' => ['enabled' => true, 'max_age' => 60],
        ]);
        $this->user();
        $response = $this->kernel->handle(new Request('GET', '/mount/remember-login',
            headers: ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'],
            server: ['REMOTE_ADDR' => '10.0.0.8', 'HTTP_HOST' => 'internal.example.test',
                'HTTPS' => 'off']));

        self::assertSame('ok', $response->content());
        self::assertSame('max-age=60', $response->header('Strict-Transport-Security'));
        $cookie = self::rememberCookie($response);
        self::assertSame('/mount', $cookie->path());
        self::assertTrue($cookie->secure());
        self::assertTrue($cookie->httpOnly());
        self::assertSame('Lax', $cookie->sameSite());
    }

    public function testMalformedUnknownWrongValidatorAndExpiredCookiesFailClosed(): void
    {
        $this->user();
        $issued = self::rememberCookie($this->request('/remember-login'));
        [$selector] = explode('.', $issued->value());
        $this->loseSession();
        $bad = $selector . '.' . str_repeat('A', 43);
        self::assertSame(303, $this->request('/secret', [$issued->name() => $bad])->status());
        self::assertCount(0, $this->database->table('remember_tokens')->all());

        $issued = self::rememberCookie($this->request('/remember-login'));
        [$selector] = explode('.', $issued->value());
        $this->database->table('remember_tokens')->filter('selector', $selector)
            ->update(['created_at' => '1999-01-01 00:00:00', 'expires_at' => '2000-01-01 00:00:00']);
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$issued->name() => $issued->value()])->status());
        self::assertCount(0, $this->database->table('remember_tokens')->all());
        $unknown = str_repeat('B', 22) . '.' . str_repeat('C', 43);
        self::assertSame(303, $this->request('/secret', [$issued->name() => $unknown])->status());
        $malformed = $this->request('/secret', [$issued->name() => str_repeat('x', 5000)]);
        self::assertSame(303, $malformed->status());
        self::assertSame(0, self::rememberCookie($malformed)->maxAge());
    }

    public function testExplicitReissueReplacesPresentedBrowserToken(): void
    {
        $this->user();
        $first = self::rememberCookie($this->request('/remember-login'));
        [$oldSelector] = explode('.', $first->value());
        $second = self::rememberCookie($this->request('/remember-login',
            [$first->name() => $first->value()]));
        self::assertFalse(hash_equals($first->value(), $second->value()), 'Remember credential must rotate.');
        self::assertNull($this->database->table('remember_tokens')->filter('selector', $oldSelector)->first());
        self::assertCount(1, $this->database->table('remember_tokens')->all());
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$first->name() => $first->value()])->status());
        self::assertSame(200, $this->request('/secret', [$second->name() => $second->value()])->status());
    }

    public function testSameRequestRecallLogoutAndLaterFailedLoginClearPendingCredentials(): void
    {
        $this->user();
        $first = self::rememberCookie($this->request('/remember-login'));
        $this->loseSession();
        $logout = $this->request('/recall-logout', [$first->name() => $first->value()]);
        self::assertSame(200, $logout->status());
        self::assertSame(0, self::rememberCookie($logout)->maxAge());
        self::assertCount(0, $this->database->table('remember_tokens')->all());

        $later = $this->request('/issue-then-fail');
        self::assertSame('done', $later->content());
        self::assertSame(0, self::rememberCookie($later)->maxAge());
        self::assertCount(0, $this->database->table('remember_tokens')->all());
    }

    public function testRememberFingerprintUsesPersistedRehashState(): void
    {
        $this->user();
        $config = $this->app->config();
        $config->set('auth.passwords.rehash_on_login', true);
        $config->set('auth.passwords.options', ['cost' => 5]);
        $manager = new AuthManager($config, $this->sessions, new PasswordHasher($config),
            null, $this->app->container());
        $loginRequest = new Request('GET', '/mount/remember-login', [], [], [], [], [], ['HTTPS' => 'on']);
        self::assertTrue($loginRequest->applyUrlBasePath(new UrlBasePath('/mount')));
        $manager->beginRequest($loginRequest);
        self::assertTrue($manager->attempt(['email' => 'ada@example.test', 'password' => 'correct'], true));
        $issued = self::rememberCookie($manager->decorateResponse(new Response()));
        $stored = $this->database->table('remember_users')->first();
        $record = $this->database->table('remember_tokens')->first();
        self::assertSame(hash('sha256', $stored['password']), $record['credential_fingerprint']);

        $this->loseSession();
        $recallRequest = new Request('GET', '/mount/secret', [], [],
            [$issued->name() => $issued->value()], [], [], ['HTTPS' => 'on']);
        self::assertTrue($recallRequest->applyUrlBasePath(new UrlBasePath('/mount')));
        $manager->beginRequest($recallRequest);
        self::assertTrue($manager->check());
    }

    public function testDatabaseRotationHasOneWinnerAfterTwoReads(): void
    {
        $this->user();
        $cookie = self::rememberCookie($this->request('/remember-login'));
        [$selector] = explode('.', $cookie->value());
        $repo = new DatabaseRememberTokenRepository($this->database, 'remember_tokens');
        $readerOne = $repo->find($selector);
        $readerTwo = $repo->find($selector);
        self::assertNotNull($readerOne);
        self::assertNotNull($readerTwo);
        $newSelector = str_repeat('Q', 22);
        $replacement = new RememberTokenRecord($newSelector, hash('sha256', 'replacement'),
            $readerOne->guard, $readerOne->identityKey, $readerOne->credentialFingerprint,
            new DateTimeImmutable('now'), new DateTimeImmutable('+1 day'));
        self::assertTrue($repo->rotate($readerOne->selector, $readerOne->validatorHash, $replacement));
        self::assertFalse($repo->rotate($readerTwo->selector, $readerTwo->validatorHash, $replacement));
        self::assertNull($repo->find($selector));
        self::assertNotNull($repo->find($newSelector));
        self::assertCount(1, $this->database->table('remember_tokens')->all());
        self::assertSame('[]', json_encode($replacement, JSON_THROW_ON_ERROR));
    }

    public function testCredentialChangesResetAndDeletedIdentityInvalidateRemembering(): void
    {
        $user = $this->user();
        $first = self::rememberCookie($this->request('/remember-login'));
        self::assertTrue($this->security->changePassword('correct', 'new-secret'));
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$first->name() => $first->value()])->status());

        $this->password = 'new-secret';
        $second = self::rememberCookie($this->request('/remember-login'));
        $reset = $this->security->issuePasswordReset(['email' => 'ada@example.test']);
        self::assertNotNull($reset);
        self::assertTrue($this->security->resetPassword($reset->token(), 'reset-secret'));
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$second->name() => $second->value()])->status());

        $this->password = 'reset-secret';
        $third = self::rememberCookie($this->request('/remember-login'));
        $fresh = RememberTestUser::query()->filter('id', $user->id)->first();
        self::assertNotNull($fresh);
        self::assertTrue($fresh->delete());
        $this->loseSession();
        self::assertSame(303, $this->request('/secret', [$third->name() => $third->value()])->status());
    }

    public function testNamedGuardAndSequentialRequestIsolation(): void
    {
        $this->user();
        $web = self::rememberCookie($this->request('/remember-login'));
        $this->loseSession();
        self::assertSame('no', $this->request('/admin-check',
            ['squehub_remember_admin' => $web->value()])->content());
        self::assertCount(1, $this->database->table('remember_tokens')->all());
        self::assertSame(200, $this->request('/secret', [$web->name() => $web->value()])->status());
        $this->loseSession();
        self::assertSame(303, $this->request('/secret')->status());
    }

    public function testEarlyMountRejectionDoesNotReusePreviousResponseCookie(): void
    {
        $this->user();
        self::rememberCookie($this->request('/remember-login'));
        $rejected = $this->kernel->handle(new Request('GET', '/outside/secret'));
        self::assertSame(404, $rejected->status());
        self::assertCount(0, $rejected->cookies());
    }

    public function testArrayRepositoryIsIsolatedFromAnotherApplication(): void
    {
        $this->user();
        $cookie = self::rememberCookie($this->request('/remember-login'));
        $otherProject = new TemporaryProject();
        try {
            $otherProject->write('Config/Database.php', '<?php return ["default"=>"main","connections"=>["main"=>["driver"=>"sqlite","database"=>":memory:"]]];');
            $otherProject->write('Config/Session.php', '<?php return ["driver"=>"array"];');
            $otherProject->write('Config/Http.php', '<?php return ["base_path"=>"/mount"];');
            $otherProject->write('Config/Auth.php', '<?php return ' . var_export(self::authConfig('array'), true) . ';');
            $other = new Application($otherProject->path());
            foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
                HttpServiceProvider::class, RoutingServiceProvider::class, AuthServiceProvider::class]
                as $provider) $other->register($provider);
            $other->bootstrap();
            $other->container()->make(RouteRegistry::class)->get('/secret',
                static fn (): string => 'secret')->through('auth');
            $response = $other->container()->make(Kernel::class)->handle(new Request('GET', '/mount/secret',
                [], [], [$cookie->name() => $cookie->value()], [], [], ['HTTPS' => 'on']));
            self::assertSame(303, $response->status());
        } finally {
            $otherProject->remove();
        }
    }
}

final class RememberTestUser extends Model implements Authenticatable
{
    protected string $table = 'remember_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];
    protected bool $softDeletes = true;
    protected bool $timestamps = true;

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
