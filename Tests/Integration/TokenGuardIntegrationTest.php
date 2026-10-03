<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth as AuthGateway;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Guards\TokenGuard;
use App\Authorization\Authorization as AuthorizationGateway;
use App\Authorization\AuthorizationServiceProvider;
use App\Database\Database as DatabaseGateway;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Plugins\Auth;
use App\Plugins\RequireAbility;
use App\Plugins\RequireToken;
use App\Plugins\RequireTokenAbility;
use App\Routing\Route as RouteGateway;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\Session as SessionGateway;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Named token guards share Auth identity APIs while preserving session routes. */
final class TokenGuardIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private Kernel $kernel;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default"=>"main","connections"=>["main"=>["driver"=>"sqlite","database"=>":memory:"]]];');
        $this->project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
        $this->project->write('Config/Api.php', '<?php return ["enabled"=>true,"paths"=>["/api"]];');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'web',
            'guards' => [
                'web' => ['driver' => 'session', 'identity' => 'users'],
                'api' => ['driver' => 'token', 'identity' => 'users', 'repository' => 'array'],
            ],
            'identities' => ['users' => ['driver' => 'model', 'model' => TokenGuardUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'tokens' => ['driver' => 'array', 'default_ttl' => 3600],
        ], true) . ';');
        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class,
            AuthServiceProvider::class, AuthorizationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->auth = $container->make(AuthManager::class);
        $this->kernel = $container->make(Kernel::class);
        $container->make(DatabaseManager::class)->schema()->create('token_guard_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
    }

    protected function tearDown(): void
    {
        AuthGateway::setResolver(null);
        AuthorizationGateway::setResolver(null);
        SessionGateway::setResolver(null);
        DatabaseGateway::setResolver(null);
        RouteGateway::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    private function user(): TokenGuardUser
    {
        return TokenGuardUser::create(['email' => 'ada@example.test', 'password' => 'unused-hash']);
    }

    public function testNamedBearerGuardSelectsIdentityAndResetsBetweenRequests(): void
    {
        $user = $this->user();
        self::assertInstanceOf(\App\Plugins\TokenManager::class, $this->auth->tokens('api'));
        $issued = $this->auth->tokens('api')->issue($user, 'test', ['orders.read']);
        self::assertInstanceOf(\App\Plugins\IssuedToken::class, $issued);
        self::assertInstanceOf(\App\Plugins\TokenMetadata::class, $issued->metadata());
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/api/me', static fn (): array => [
            'id' => Auth::id(), 'token_id' => Auth::token()?->identifier(),
            'read' => Auth::tokenAllows('orders.read'), 'write' => Auth::tokenAllows('orders.write'),
        ])->through(RequireToken::guard('api'));

        $valid = $this->kernel->handle(new Request('GET', '/api/me', headers: [
            'Authorization' => 'Bearer ' . $issued->token(),
        ]));
        self::assertSame(200, $valid->status());
        $data = json_decode($valid->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((int) $user->getAttribute('id'), (int) $data['id']);
        self::assertSame($issued->metadata()->identifier(), $data['token_id']);
        self::assertTrue($data['read']);
        self::assertFalse($data['write']);

        // One Application may handle another request; no previous identity or
        // token selection can survive the Kernel's next beginRequest call.
        $missing = $this->kernel->handle(new Request('GET', '/api/me'));
        self::assertSame(401, $missing->status());
        self::assertSame('Bearer', $missing->header('WWW-Authenticate'));
        self::assertSame('unauthenticated', json_decode($missing->content(), true)['error']['code']);
        self::assertNull($this->auth->token());
    }

    public function testTokenAndPolicyAbilitiesAreSeparateNarrowingDecisions(): void
    {
        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'limited', ['orders.read']);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/api/read', static fn (): string => 'allowed')->through([
            RequireToken::guard('api'), RequireTokenAbility::named('orders.read'),
        ]);
        $routes->get('/api/write', static fn (): string => 'should-not-run')->through([
            RequireToken::guard('api'), RequireTokenAbility::named('orders.write'),
        ]);
        AuthorizationGateway::manager()->define('orders.read', static fn (TokenGuardUser $identity): bool => false);
        $routes->get('/api/policy', static fn (): string => 'should-not-run')->through([
            RequireToken::guard('api'), RequireTokenAbility::named('orders.read'), RequireAbility::named('orders.read'),
        ]);

        $headers = ['Authorization' => 'Bearer ' . $issued->token()];
        self::assertSame(200, $this->kernel->handle(new Request('GET', '/api/read', headers: $headers))->status());
        $write = $this->kernel->handle(new Request('GET', '/api/write', headers: $headers));
        self::assertSame(403, $write->status());
        self::assertSame('forbidden', json_decode($write->content(), true)['error']['code']);
        self::assertSame(403, $this->kernel->handle(new Request('GET', '/api/policy', headers: $headers))->status());
    }

    public function testBrowserSessionCannotSatisfyNamedTokenGuard(): void
    {
        $user = $this->user();
        $this->auth->login($user);
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->get('/web/me', static fn (): string => (string) Auth::id())->through('auth');
        $routes->get('/api/me', static fn (): string => (string) Auth::id())->through(RequireToken::guard('api'));

        self::assertSame((string) $user->getAttribute('id'),
            $this->kernel->handle(new Request('GET', '/web/me'))->content());
        $api = $this->kernel->handle(new Request('GET', '/api/me'));
        self::assertSame(401, $api->status());
        self::assertSame('Bearer', $api->header('WWW-Authenticate'));
    }

    public function testMalformedOrRepeatedAuthorizationHasOneGenericChallenge(): void
    {
        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'test');
        $called = 0;
        $this->app->container()->make(RouteRegistry::class)->get('/api/private',
            static function () use (&$called): string {
                $called++;
                return 'secret';
            })->through(RequireToken::guard('api'));

        $badHeaders = [
            ['Authorization' => 'Bearer  ' . $issued->token()],
            ['Authorization' => 'Bearer ' . $issued->token(),
                'authorization' => 'Bearer ' . $issued->token()],
            ['Authorization' => 'Bearer unknown-value'],
        ];
        foreach ($badHeaders as $headers) {
            $response = $this->kernel->handle(new Request('GET', '/api/private', headers: $headers));
            self::assertSame(401, $response->status());
            self::assertSame('Bearer', $response->header('WWW-Authenticate'));
            self::assertSame('unauthenticated', json_decode($response->content(), true)['error']['code']);
            self::assertStringNotContainsString($issued->token(), $response->content());
        }
        self::assertSame(0, $called);
    }

    public function testTokenOnlyApplicationBootsWithoutSessionProviderOrTokenTable(): void
    {
        $separate = new TemporaryProject();
        $separate->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'api',
            'guards' => ['api' => ['driver' => 'token', 'identity' => 'users', 'repository' => 'array']],
            'identities' => ['users' => ['driver' => 'model', 'model' => TokenGuardUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'tokens' => ['driver' => 'array'],
        ], true) . ';');
        try {
            $app = new Application($separate->path());
            $app->register(AuthServiceProvider::class);
            $app->bootstrap();
            $guard = $app->container()->make(AuthManager::class)->guard('api');
            self::assertInstanceOf(TokenGuard::class, $guard);
            self::assertSame(PHP_SESSION_NONE, session_status());
            self::assertFalse($guard->check());
        } finally {
            AuthGateway::setResolver(null);
            $separate->remove();
            // The outer test Application is still active for tearDown.
            AuthGateway::setResolver(fn (): AuthManager => $this->auth);
        }
    }
}

/** Modern identity fixture shared by the session and API token guards. */
final class TokenGuardUser extends Model implements Authenticatable
{
    protected string $table = 'token_guard_users';
    protected array $fillable = ['email', 'password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
