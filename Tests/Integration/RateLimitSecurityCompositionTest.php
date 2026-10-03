<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\AccountSecurity\AccountSecurity;
use App\AccountSecurity\AccountSecurityServiceProvider;
use App\Auth\Auth;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
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
use App\Http\Response;
use App\RateLimit\Middleware\RateLimitRequests;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitRule;
use App\RateLimit\RateLimitServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Generic named middleware protects existing Auth and account-token operations. */
final class RateLimitSecurityCompositionTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private DatabaseManager $database;
    private int $loginActions = 0;
    private int $resetActions = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/RateLimit.php', '<?php return ["store" => "array", "prefix" => "security-test"];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'web', 'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => RateLimitedUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                'rehash_on_login' => false, 'max_bytes' => 4096],
        ], true) . ';');
        $this->project->write('Config/AccountSecurity.php', '<?php return ["tokens" => ["driver" => "database", "table" => "account_security_tokens"]];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            SessionServiceProvider::class, HttpServiceProvider::class, RoutingServiceProvider::class,
            AuthServiceProvider::class, AccountSecurityServiceProvider::class,
            RateLimitServiceProvider::class] as $provider) $this->app->register($provider);
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->database = $container->make(DatabaseManager::class);
        $this->kernel = $container->make(Kernel::class);
        $this->database->schema()->create('rate_limit_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        $this->database->schema()->create('account_security_tokens', static function (Table $table): void {
            $table->string('token_hash', 64);
            $table->string('purpose', 32);
            $table->string('guard', 64);
            $table->string('identity_identifier', 255);
            $table->string('context_hash', 64);
            $table->datetime('expires_at');
            $table->datetime('created_at');
        });
        RateLimitedUser::create(['email' => 'ada@example.test',
            'password' => $container->make(PasswordHasher::class)->hash('secret')]);
        $limiter = $container->make(RateLimiter::class);
        $limiter->define('login', static fn (Request $request): RateLimitRule =>
            RateLimitRule::fixed((string) $request->input('email', 'anonymous'), 1, 60));
        $limiter->define('reset', static fn (Request $request): RateLimitRule =>
            RateLimitRule::fixed((string) $request->input('email', 'anonymous'), 1, 60));
        $routes = $container->make(RouteRegistry::class);
        $routes->add('POST', '/login', function (Request $request): Response {
            ++$this->loginActions;
            $success = \auth()->attempt(['email' => $request->input('email'),
                'password' => $request->input('password')]);
            return new Response($success ? 'signed in' : 'rejected');
        })->through(RateLimitRequests::named('login'));
        $routes->add('POST', '/reset', function (Request $request): Response {
            ++$this->resetActions;
            \accountSecurity()->issuePasswordReset(['email' => $request->input('email')]);
            return new Response('If an account matches, instructions can be sent.');
        })->through(RateLimitRequests::named('reset'));
    }

    protected function tearDown(): void
    {
        RateLimit::setResolver(null);
        AccountSecurity::setResolver(null);
        Auth::setResolver(null);
        Session::setResolver(null);
        Database::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    public function testLimiterBlocksSecondLoginBeforeCredentialAttempt(): void
    {
        $request = new Request('POST', '/login', form: ['email' => 'ada@example.test', 'password' => 'wrong']);
        self::assertSame('rejected', $this->kernel->handle($request)->content());
        self::assertSame(1, $this->app->container()->make(Diagnostics::class)->snapshot()['auth']['attempts']);
        self::assertSame(429, $this->kernel->handle($request)->status());
        self::assertSame(1, $this->loginActions);
        self::assertSame(0, $this->app->container()->make(Diagnostics::class)->snapshot()['auth']['attempts']);
    }

    public function testLimiterComposesWithPasswordResetWithoutChangingManager(): void
    {
        $known = new Request('POST', '/reset', form: ['email' => 'ada@example.test']);
        self::assertSame('If an account matches, instructions can be sent.', $this->kernel->handle($known)->content());
        self::assertCount(1, $this->database->table('account_security_tokens')->all());
        self::assertSame(429, $this->kernel->handle($known)->status());
        self::assertSame(1, $this->resetActions);
        $unknown = new Request('POST', '/reset', form: ['email' => 'missing@example.test']);
        self::assertSame('If an account matches, instructions can be sent.', $this->kernel->handle($unknown)->content());
        self::assertCount(1, $this->database->table('account_security_tokens')->all());
    }
}

/** Minimal persisted identity shared by real Auth and account-security providers. */
final class RateLimitedUser extends Model implements Authenticatable
{
    protected string $table = 'rate_limit_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
