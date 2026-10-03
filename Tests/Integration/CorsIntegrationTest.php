<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Cors;

use App\Api\ApiResource;
use App\Auth\Auth;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Database\Model;
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
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use App\Validation\ValidationServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Browser CORS behavior through the real provider, Kernel, Router, and error boundary. */
final class CorsIntegrationTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ([Auth::class, Csrf::class, RateLimit::class, Route::class, Session::class] as $gateway) {
            $gateway::setResolver(null);
        }
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    public function testDisabledCorsLeavesApiAndBrowserRoutesWorkingWithoutCorsHeaders(): void
    {
        $app = $this->application(['enabled' => false]);
        $this->routes($app)->get('/api/ping', static fn (): string => 'api');
        $this->routes($app)->get('/page', static fn (): string => 'browser');
        $origin = ['Origin' => 'https://app.example.com'];
        $api = $this->handle($app, new Request('GET', '/api/ping', headers: $origin));
        self::assertSame(200, $api->status());
        self::assertSame('api', $api->content());
        self::assertNull($api->header('Access-Control-Allow-Origin'));
        self::assertNull($api->header('Vary'));
        $page = $this->handle($app, new Request('GET', '/page', headers: $origin));
        self::assertSame('browser', $page->content());
        self::assertNull($page->header('Access-Control-Allow-Origin'));

        $preflight = $this->handle($app, new Request('OPTIONS', '/api/ping', headers: [
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET',
        ]));
        self::assertSame(405, $preflight->status());
        self::assertNull($preflight->header('Access-Control-Allow-Origin'));
    }

    public function testOptInResourceSuccessAndAllowedOriginErrorsKeepPhase12BEnvelope(): void
    {
        $app = $this->application();
        $routes = $this->routes($app);
        $routes->get('/api/resource', static fn (): Response => CorsUserResource::make([
            'id' => 7, 'name' => 'Ada', 'password' => 'private',
        ])->response());
        $routes->get('/api/invalid', static fn (Request $request): array =>
            $request->validate(['name' => 'required']));
        $routes->get('/api/private', static fn (): string => 'must not run')->through('auth');
        $routes->get('/api/forbidden', static function (): never {
            throw new \App\Authorization\AuthorizationException();
        });
        $routes->post('/api/post-only', static fn (): string => 'must not run');
        $routes->get('/api/boom', static function (): never {
            throw new RuntimeException('secret exception data');
        });
        $limiter = $app->container()->make(RateLimiter::class);
        $limiter->define('cors.read', static fn (): RateLimitRule => RateLimitRule::fixed('cors-test', 1, 60));
        $routes->get('/api/limited', static fn (): string => 'first')
            ->through(RateLimitRequests::named('cors.read'));

        $origin = ['Origin' => 'https://app.example.com'];
        $resource = $this->handle($app, new Request('GET', '/api/resource', headers: $origin));
        self::assertSame(200, $resource->status());
        self::assertSame(['id' => 7, 'name' => 'Ada'], $this->decode($resource));
        $this->assertCors($resource, 'https://app.example.com');

        foreach ([
            ['/api/invalid', 422, 'validation_failed'],
            ['/api/private', 401, 'unauthenticated'],
            ['/api/forbidden', 403, 'forbidden'],
            ['/api/missing', 404, 'not_found'],
            ['/api/post-only', 405, 'method_not_allowed'],
            ['/api/boom', 500, 'internal_error'],
        ] as [$path, $status, $code]) {
            $response = $this->handle($app, new Request('GET', $path, headers: $origin));
            $this->assertManagedCorsError($response, $status, $code, 'https://app.example.com');
        }
        self::assertSame(200, $this->handle($app, new Request('GET', '/api/limited', headers: $origin))->status());
        $limited = $this->handle($app, new Request('GET', '/api/limited', headers: $origin));
        $this->assertManagedCorsError($limited, 429, 'rate_limited', 'https://app.example.com');
        self::assertNotNull($limited->header('Retry-After'));
    }

    public function testRealPreflightStopsBeforeAuthCsrfAndHandlerButOrdinaryOptionsRuns(): void
    {
        $app = $this->application();
        $runs = 0;
        $this->routes($app)->post('/api/submit', static function () use (&$runs): string {
            ++$runs;
            return 'unexpected';
        })->through('auth');
        $this->routes($app)->options('/api/submit', static function () use (&$runs): string {
            ++$runs;
            return 'ordinary';
        });
        $preflight = new Request('OPTIONS', '/api/submit', headers: [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type, x-csrf-token',
        ]);
        $response = $this->handle($app, $preflight);
        self::assertSame(204, $response->status());
        self::assertSame('', $response->content());
        self::assertSame(0, $runs);
        self::assertSame('POST', $response->header('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-CSRF-Token', $response->header('Access-Control-Allow-Headers'));
        self::assertSame('true', $response->header('Access-Control-Allow-Credentials'));
        $this->assertCors($response, 'https://app.example.com');
        ob_start();
        $response->send();
        self::assertSame('', ob_get_clean());

        $ordinary = $this->handle($app, new Request('OPTIONS', '/api/submit', headers: [
            'Origin' => 'https://app.example.com',
        ]));
        self::assertSame(200, $ordinary->status());
        self::assertSame('ordinary', $ordinary->content());
        self::assertSame(1, $runs);

        $denied = $this->handle($app, new Request('OPTIONS', '/api/submit', headers: [
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'DELETE',
        ]));
        self::assertSame(403, $denied->status());
        self::assertSame('cors_preflight_denied', $this->decode($denied)['error']['code']);
        self::assertNull($denied->header('Access-Control-Allow-Origin'));
        self::assertSame(1, $runs);

        // GET is permitted by CORS configuration, but the Router has no GET
        // route for this path. Do not advertise a method the app cannot serve.
        $unroutable = $this->handle($app, new Request('OPTIONS', '/api/submit', headers: [
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET',
        ]));
        self::assertSame(403, $unroutable->status());
        self::assertSame('cors_preflight_denied', $this->decode($unroutable)['error']['code']);
        self::assertNull($unroutable->header('Access-Control-Allow-Origin'));
        self::assertSame(1, $runs);
    }

    public function testPreflightUsesCapturedHostAndDoesNotTreatFallbackAsAnEndpoint(): void
    {
        $app = $this->application();
        $runs = 0;
        Route::path('/api/host')->host('api.example.test')->get(static function () use (&$runs): string {
            ++$runs;
            return 'hosted';
        });
        Route::path('/api')->fallback(static fn (): string => 'fallback');

        $headers = [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'GET',
        ];
        $authorized = $this->handle($app, new Request('OPTIONS', '/api/host',
            headers: $headers, server: ['HTTP_HOST' => 'API.EXAMPLE.TEST:8443']));
        self::assertSame(204, $authorized->status());
        self::assertSame(0, $runs);

        $wrongHost = $this->handle($app, new Request('OPTIONS', '/api/host',
            headers: [...$headers, 'X-Forwarded-Host' => 'api.example.test'],
            server: ['HTTP_HOST' => 'other.example.test:8443']));
        self::assertSame(403, $wrongHost->status());
        self::assertNull($wrongHost->header('Access-Control-Allow-Origin'));

        $missingPath = $this->handle($app, new Request('OPTIONS', '/api/missing',
            headers: $headers, server: ['HTTP_HOST' => 'api.example.test']));
        self::assertSame(403, $missingPath->status());
        self::assertSame(0, $runs);
    }

    public function testCredentialedCorsNeverExemptsAnUnsafeRequestFromCsrf(): void
    {
        $app = $this->application();
        $runs = 0;
        $this->routes($app)->post('/api/change', static function () use (&$runs): string {
            ++$runs;
            return 'changed';
        });
        $denied = $this->handle($app, new Request('POST', '/api/change',
            cookies: ['squehub_session' => 'session-cookie'],
            headers: ['Origin' => 'https://app.example.com']));
        $this->assertManagedCorsError($denied, 403, 'forbidden', 'https://app.example.com');
        self::assertSame('true', $denied->header('Access-Control-Allow-Credentials'));
        self::assertSame(0, $runs);
    }

    public function testAllowedThenDeniedOriginsDoNotLeakAcrossRequestsOrApplications(): void
    {
        $first = $this->application();
        $second = $this->application(['allowed_origins' => ['https://other.example']]);
        $this->routes($first)->get('/api/ping', static fn (): string => 'one');
        $this->routes($second)->get('/api/ping', static fn (): string => 'two');
        $allowed = $this->handle($first, new Request('GET', '/api/ping', headers: [
            'Origin' => 'https://app.example.com',
        ]));
        $denied = $this->handle($first, new Request('GET', '/api/ping', headers: [
            'Origin' => 'https://other.example',
        ]));
        $other = $this->handle($second, new Request('GET', '/api/ping', headers: [
            'Origin' => 'https://other.example',
        ]));
        self::assertSame('one', $allowed->content());
        self::assertSame('one', $denied->content());
        self::assertSame('two', $other->content());
        $this->assertCors($allowed, 'https://app.example.com');
        self::assertNull($denied->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $denied->header('Vary'));
        $this->assertCors($other, 'https://other.example');
        self::assertNull($this->handle($second, new Request('GET', '/api/ping', headers: [
            'Origin' => 'https://app.example.com',
        ]))->header('Access-Control-Allow-Origin'));
    }

    public function testConflictingOriginHeadersNeverReceiveACorsGrant(): void
    {
        $app = $this->application();
        $this->routes($app)->get('/api/ping', static fn (): string => 'ok');
        $actual = $this->handle($app, new Request('GET', '/api/ping', headers: [
            'Origin' => 'https://evil.example', 'origin' => 'https://app.example.com',
        ]));
        self::assertSame(200, $actual->status());
        self::assertNull($actual->header('Access-Control-Allow-Origin'));
        $preflight = $this->handle($app, new Request('OPTIONS', '/api/ping', headers: [
            'Origin' => 'https://evil.example', 'origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'GET',
        ]));
        self::assertSame(403, $preflight->status());
        self::assertNull($preflight->header('Access-Control-Allow-Origin'));
    }

    public function testInvalidCorsConfigurationFailsDuringApplicationBoot(): void
    {
        foreach ([
            ['allowed_origins' => ['*'], 'allow_credentials' => true],
            ['allowed_origins' => ['https://app.example.com/path']],
            ['allowed_methods' => ['TRACE']],
            ['max_age' => -1],
        ] as $invalid) {
            try {
                $this->application($invalid);
                self::fail('Invalid CORS configuration reached request handling.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('CORS', $exception->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $cors */
    private function application(array $cors = []): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $config = [
            'App' => ['env' => 'testing', 'debug' => false],
            'Api' => ['enabled' => true, 'paths' => ['/api'], 'cors' => [
                'enabled' => true, 'paths' => ['/api'],
                'allowed_origins' => ['https://app.example.com'],
                'allowed_methods' => ['GET', 'HEAD', 'POST', 'OPTIONS'],
                'allowed_headers' => ['Content-Type', 'X-CSRF-Token'],
                'exposed_headers' => ['X-Request-ID'],
                'allow_credentials' => true, 'max_age' => 600,
                ...$cors,
            ]],
            'Session' => ['driver' => 'array'],
            'Csrf' => ['enabled' => true, 'field' => '_csrf', 'header' => 'X-CSRF-Token', 'except' => []],
            'Auth' => ['default' => 'web', 'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
                'identities' => ['users' => ['driver' => 'model', 'model' => CorsIdentity::class,
                    'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4], 'rehash_on_login' => false],
                'browser' => ['login_path' => '/login', 'authenticated_path' => '/dashboard']],
            'RateLimit' => ['store' => 'array', 'prefix' => 'cors-tests', 'path' => null],
        ];
        foreach ($config as $name => $values) {
            $project->write('Config/' . $name . '.php', '<?php return ' . var_export($values, true) . ';');
        }
        $app = new Application($project->path());
        foreach ([SessionServiceProvider::class, ValidationServiceProvider::class,
            CsrfServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, AuthServiceProvider::class,
            RateLimitServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }

    private function routes(Application $app): RouteRegistry
    {
        return $app->container()->make(RouteRegistry::class);
    }

    private function handle(Application $app, Request $request): Response
    {
        return $app->container()->make(Kernel::class)->handle($request);
    }

    private function decode(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertCors(Response $response, string $origin): void
    {
        self::assertSame($origin, $response->header('Access-Control-Allow-Origin'));
        self::assertContains('Origin', array_map('trim', explode(',', (string) $response->header('Vary'))));
    }

    private function assertManagedCorsError(Response $response, int $status, string $code, string $origin): void
    {
        self::assertSame($status, $response->status(), $response->content());
        self::assertSame($code, $this->decode($response)['error']['code']);
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame($response->header('X-Request-ID'), $this->decode($response)['request_id']);
        $this->assertCors($response, $origin);
    }
}

final class CorsIdentity extends Model implements Authenticatable
{
    protected string $table = 'cors_identities';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}

final class CorsUserResource extends ApiResource
{
    public function toArray(): array
    {
        return ['id' => $this->resource['id'], 'name' => $this->resource['name']];
    }
}
