<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ApiTokenHttp;

use App\Api\ApiResource;
use App\Auth\Auth as AuthGateway;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Middleware\RequireToken;
use App\Auth\Middleware\RequireTokenAbility;
use App\Authorization\AuthorizationManager;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Middleware\RequireAbility;
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
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log;
use App\Logging\LoggingServiceProvider;
use App\Plugins\Auth;
use App\RateLimit\Middleware\RateLimitRequests;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitRule;
use App\RateLimit\RateLimitServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises token Auth alongside the existing HTTP security and API response pipeline. */
final class ApiTokenHttpIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private AuthManager $auth;
    private DatabaseManager $database;
    private Kernel $kernel;
    private RouteRegistry $routes;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->project = new TemporaryProject();
        foreach ([
            'App' => ['env' => 'testing', 'debug' => false],
            'Database' => ['default' => 'main', 'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'Session' => ['driver' => 'array'],
            'Csrf' => ['enabled' => true, 'field' => '_csrf', 'header' => 'X-CSRF-Token',
                'except' => ['/api/token-only']],
            'Logging' => ['driver' => 'array', 'level' => 'debug'],
            'RateLimit' => ['store' => 'array', 'prefix' => 'api-token-http', 'path' => null],
            'Api' => ['enabled' => true, 'paths' => ['/api'], 'cors' => [
                'enabled' => true, 'paths' => ['/api'],
                'allowed_origins' => ['https://client.example.test'],
                'allowed_methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'],
                'allowed_headers' => ['Authorization', 'Content-Type'],
                'exposed_headers' => ['X-Request-ID'],
                'allow_credentials' => false, 'max_age' => 600]],
            'Auth' => ['default' => 'web', 'guards' => [
                'web' => ['driver' => 'session', 'identity' => 'users'],
                'api' => ['driver' => 'token', 'identity' => 'users']],
                'identities' => ['users' => ['driver' => 'model', 'model' => TokenHttpUser::class,
                    'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
                'tokens' => ['driver' => 'database', 'table' => 'api_tokens'],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                    'rehash_on_login' => false],
                'browser' => ['login_path' => '/login', 'authenticated_path' => '/home']],
            'Authorization' => ['abilities' => [], 'policies' => []],
        ] as $name => $config) {
            $this->project->write('Config/' . $name . '.php',
                '<?php return ' . var_export($config, true) . ';');
        }
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class,
            DatabaseServiceProvider::class, SessionServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, AuthServiceProvider::class,
            AuthorizationServiceProvider::class, RateLimitServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->auth = $container->make(AuthManager::class);
        $this->database = $container->make(DatabaseManager::class);
        $this->kernel = $container->make(Kernel::class);
        $this->routes = $container->make(RouteRegistry::class);
        $this->database->schema()->create('token_http_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->string('password');
        });
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_26_create_api_tokens.php';
        (new \CreateApiTokens())->up($this->database->connection()->pdo(), $this->database->schema());
    }

    protected function tearDown(): void
    {
        foreach ([AuthGateway::class, Database::class, Log::class, RateLimit::class,
            Route::class, Csrf::class, Session::class] as $gateway) {
            $gateway::setResolver(null);
        }
        if (isset($this->project)) $this->project->remove();
    }

    private function user(string $name = 'Ada'): TokenHttpUser
    {
        return TokenHttpUser::create(['email' => strtolower($name) . '@example.test',
            'name' => $name, 'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    private function request(string $method, string $path, ?string $token = null,
        array $headers = []): Request
    {
        if ($token !== null) $headers['Authorization'] = 'Bearer ' . $token;
        return new Request($method, $path, headers: ['Accept' => 'application/json', ...$headers]);
    }

    private static function body(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSessionNeverSubstitutesForMissingTokenAndRequestIdentityResets(): void
    {
        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'CLI', ['profile.read']);
        $this->auth->login($user);
        $this->routes->get('/api/profile', static fn (): array => [
            'id' => Auth::id(), 'token' => Auth::token()?->name(),
        ])->through(RequireToken::guard('api'));

        foreach ([null, 'wrong', $issued->token() . 'invalid'] as $presented) {
            $response = $this->kernel->handle($this->request('GET', '/api/profile', $presented));
            self::assertSame(401, $response->status());
            self::assertSame('Bearer', $response->header('WWW-Authenticate'));
            self::assertSame('unauthenticated', self::body($response)['error']['code']);
            self::assertSame($response->header('X-Request-ID'), self::body($response)['request_id']);
            self::assertSame('no-store', $response->header('Cache-Control'));
        }

        $success = $this->kernel->handle($this->request('GET', '/api/profile', $issued->token()));
        self::assertSame(200, $success->status(), $success->content());
        self::assertSame(['id' => (int) $user->getAttribute('id'), 'token' => 'CLI'], self::body($success));
        self::assertSame(1, $this->app->container()->make(Diagnostics::class)
            ->snapshot()['token_auth']['successes']);
        $next = $this->kernel->handle($this->request('GET', '/api/profile'));
        self::assertSame(401, $next->status());
        self::assertSame([], $this->app->container()->make(ArrayLogger::class)->records());
    }

    public function testSpaAndSessionDoNotReplaceBearerTokenOrItsExplicitCsrfPolicy(): void
    {
        $this->project->write('Project/Views/Frontend/App.squehub.php',
            '<!doctype html><html><body>frontend shell</body></html>');
        $this->app->config()->set('frontend.spa', [
            'enabled' => true, 'prefix' => '/frontend',
            'view' => 'Frontend.App', 'except' => [],
        ]);
        self::assertSame(200, $this->kernel->handle(new Request('GET', '/frontend/dashboard',
            headers: ['Accept' => 'text/html']))->status());

        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'Frontend API');
        $this->auth->login($user);
        $this->routes->get('/api/frontend-token', static fn (): array => [
            'id' => Auth::id(), 'token' => Auth::token()?->name(),
        ])->through(RequireToken::guard('api'));
        $this->routes->post('/api/token-only', static fn (): array => [
            'id' => Auth::id(),
        ])->through(RequireToken::guard('api'));

        // A Session login cannot satisfy a Bearer-only route, even with an
        // active browser shell. The API reservation also keeps its 404 status.
        $missing = $this->kernel->handle($this->request('GET', '/api/frontend-token',
            headers: ['Accept' => 'text/html']));
        self::assertSame(401, $missing->status());
        self::assertSame('Bearer', $missing->header('WWW-Authenticate'));
        self::assertSame(404, $this->kernel->handle(new Request('GET', '/api/missing',
            headers: ['Accept' => 'text/html']))->status());

        $authenticated = $this->kernel->handle($this->request('GET', '/api/frontend-token',
            $issued->token()));
        self::assertSame(200, $authenticated->status(), $authenticated->content());
        self::assertSame(['id' => (int) $user->getAttribute('id'), 'token' => 'Frontend API'],
            self::body($authenticated));

        // Only the existing explicit CSRF exemption admits this unsafe API
        // request without a Session token. The SPA policy adds no exemption.
        $post = $this->kernel->handle($this->request('POST', '/api/token-only',
            $issued->token()));
        self::assertSame(200, $post->status(), $post->content());
        self::assertSame(['id' => (int) $user->getAttribute('id')], self::body($post));
    }

    public function testTokenAbilityAndApplicationAuthorizationBothNarrowAccess(): void
    {
        $user = $this->user();
        $read = $this->auth->tokens('api')->issue($user, 'Read', ['orders.read']);
        $wildcard = $this->auth->tokens('api')->issue($user, 'Full', ['*']);
        $authorization = $this->app->container()->make(AuthorizationManager::class);
        $authorization->define('orders.read', static fn (TokenHttpUser $identity): bool =>
            $identity->getAttribute('name') === 'Ada');
        $authorization->define('orders.write', static fn (TokenHttpUser $identity): bool => false);
        $this->routes->get('/api/orders', static fn (): string => 'orders')
            ->through([RequireToken::guard('api'), RequireTokenAbility::named('orders.read'),
                RequireAbility::named('orders.read')]);
        $this->routes->post('/api/token-only', static fn (): string => 'write')
            ->through([RequireToken::guard('api'), RequireTokenAbility::named('orders.write'),
                RequireAbility::named('orders.write')]);

        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/orders', $read->token()))->status());
        $bounded = $this->kernel->handle($this->request('POST', '/api/token-only', $read->token()));
        self::assertSame(403, $bounded->status());
        self::assertSame('forbidden', self::body($bounded)['error']['code']);
        $policy = $this->kernel->handle($this->request('POST', '/api/token-only', $wildcard->token()));
        self::assertSame(403, $policy->status());
        self::assertSame('forbidden', self::body($policy)['error']['code']);
    }

    public function testExplicitCsrfBoundaryCorsPreflightVersionResourceAndRateLimitCompose(): void
    {
        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'API', ['profile.read']);
        $this->routes->post('/api/browser-protected', static fn (): string => 'unexpected')
            ->through(RequireToken::guard('api'));
        $this->routes->post('/api/token-only', static fn (Request $request): Response =>
            TokenHttpResource::make(['id' => Auth::id(), 'version' => $request->apiVersion()])
                ->response())
            ->through(RequireToken::guard('api'))->apiVersion('1');
        $limiter = $this->app->container()->make(RateLimiter::class);
        $limiter->define('token.profile', static fn (): RateLimitRule =>
            RateLimitRule::fixed('fixture-subject', 1, 60));
        $this->routes->get('/api/limited', static fn (): string => 'allowed')
            ->through([RequireToken::guard('api'), RateLimitRequests::named('token.profile')]);

        $csrf = $this->kernel->handle($this->request('POST', '/api/browser-protected', $issued->token()));
        self::assertSame(403, $csrf->status());
        self::assertSame(0, $this->app->container()->make(Diagnostics::class)
            ->snapshot()['token_auth']['attempts']);

        $origin = ['Origin' => 'https://client.example.test'];
        $preflight = $this->kernel->handle(new Request('OPTIONS', '/api/token-only', headers: [
            ...$origin, 'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization',
        ]));
        self::assertSame(204, $preflight->status(), $preflight->content());
        self::assertSame(0, $this->app->container()->make(Diagnostics::class)
            ->snapshot()['token_auth']['attempts']);
        $response = $this->kernel->handle($this->request('POST', '/api/token-only',
            $issued->token(), $origin));
        self::assertSame(200, $response->status(), $response->content());
        self::assertSame(['id' => (int) $user->getAttribute('id'), 'version' => '1'], self::body($response));
        self::assertSame('https://client.example.test',
            $response->header('Access-Control-Allow-Origin'));
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/limited',
            $issued->token()))->status());
        $limited = $this->kernel->handle($this->request('GET', '/api/limited',
            $issued->token(), $origin));
        self::assertSame(429, $limited->status());
        self::assertSame('rate_limited', self::body($limited)['error']['code']);
        self::assertSame('https://client.example.test',
            $limited->header('Access-Control-Allow-Origin'));
    }

    public function testRevocationAndRotationTakeEffectOnTheNextHttpRequest(): void
    {
        $user = $this->user();
        $first = $this->auth->tokens('api')->issue($user, 'Device');
        $this->routes->get('/api/me', static fn (): string => (string) Auth::id())
            ->through(RequireToken::guard('api'));

        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/me',
            $first->token()))->status());
        $replacement = $this->auth->tokens('api')->rotate($first->metadata()->identifier());
        self::assertSame(401, $this->kernel->handle($this->request('GET', '/api/me',
            $first->token()))->status());
        self::assertSame(200, $this->kernel->handle($this->request('GET', '/api/me',
            $replacement->token()))->status());
        self::assertTrue($this->auth->tokens('api')->revoke($replacement->metadata()->identifier()));
        self::assertSame(401, $this->kernel->handle($this->request('GET', '/api/me',
            $replacement->token()))->status());
    }

    public function testBearerSecretIsAbsentFromApiErrorsLogsAndDiagnostics(): void
    {
        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'Secret fixture');
        $raw = $issued->token();
        $this->routes->get('/api/failure', static function () use ($raw): never {
            throw new RuntimeException('outer ' . $raw, 0,
                new RuntimeException('previous ' . $raw));
        })->through(RequireToken::guard('api'));

        $response = $this->kernel->handle($this->request('GET', '/api/failure', $raw));
        self::assertSame(500, $response->status());
        self::assertSame('internal_error', self::body($response)['error']['code']);
        self::assertSame($response->header('X-Request-ID'), self::body($response)['request_id']);
        $records = $this->app->container()->make(ArrayLogger::class)->records();
        self::assertCount(1, $records);
        $surface = $response->content() . json_encode(array_map(
            static fn ($record): array => $record->toArray(), $records), JSON_THROW_ON_ERROR)
            . json_encode($this->app->container()->make(Diagnostics::class)->snapshot(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($raw, $surface);
        self::assertStringNotContainsString(hash('sha256', substr($raw, -43)), $surface);

        // A broken repository is an infrastructure failure, not bad credentials.
        $this->routes->get('/api/storage', static fn (): string => 'unexpected')
            ->through(RequireToken::guard('api'));
        $this->database->schema()->dropIfExists('api_tokens');
        $unavailable = $this->kernel->handle($this->request('GET', '/api/storage', $raw));
        self::assertSame(500, $unavailable->status());
        self::assertCount(2, $this->app->container()->make(ArrayLogger::class)->records());
        self::assertStringNotContainsString($raw, $unavailable->content());
    }

    public function testSeparateApplicationConfigurationDoesNotShareTokenState(): void
    {
        $user = $this->user();
        $issued = $this->auth->tokens('api')->issue($user, 'First application');
        $otherProject = new TemporaryProject();
        $otherProject->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'api',
            'guards' => ['api' => ['driver' => 'token', 'identity' => 'users']],
            'identities' => ['users' => ['driver' => 'model', 'model' => TokenHttpUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
            'tokens' => ['driver' => 'array', 'default_ttl' => 60],
        ], true) . ';');
        try {
            $other = new Application($otherProject->path());
            $other->register(AuthServiceProvider::class);
            $other->bootstrap();
            $otherTokens = $other->container()->make(AuthManager::class)->tokens('api');
            self::assertNotSame($this->auth->tokens('api'), $otherTokens);
            self::assertNull($otherTokens->authenticate($issued->token()));
            self::assertSame([], $otherTokens->listFor($user));
        } finally {
            $otherProject->remove();
            AuthGateway::setResolver(fn (): AuthManager => $this->auth);
        }
    }
}

/** A fixture identity exercises the ordinary ModelIdentityProvider path. */
final class TokenHttpUser extends Model implements Authenticatable
{
    protected string $table = 'token_http_users';
    protected array $fillable = ['email', 'name', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}

/** API resources keep selecting public data after token authentication. */
final class TokenHttpResource extends ApiResource
{
    public function toArray(): array
    {
        return ['id' => $this->resource['id'], 'version' => $this->resource['version']];
    }
}
