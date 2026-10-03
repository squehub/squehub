<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ApiErrors;

use App\Api\ApiError;
use App\Api\ApiResource;
use App\Auth\Auth;
use App\Auth\AuthManager;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Authorization\Authorization;
use App\Authorization\AuthorizationDecision;
use App\Authorization\AuthorizationManager;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Middleware\RequireAbility;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\Pagination\Page;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Health\Health;
use App\Health\HealthServiceProvider;
use App\Http\BrowserFormsServiceProvider;
use App\Http\Exception\HttpException;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\Http\Response;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log;
use App\Logging\LoggingServiceProvider;
use App\RateLimit\Middleware\RateLimitRequests;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitRule;
use App\RateLimit\RateLimitServiceProvider;
use App\RateLimit\RateLimitStore;
use App\RateLimit\RateLimitResult;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Validation\ValidationException;
use App\Validation\ValidationServiceProvider;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Real Kernel/Router coverage for opt-in error formatting and existing HTTP contracts. */
final class ApiErrorIntegrationTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];
    private Application $app;

    protected function setUp(): void
    {
        $this->app = $this->application();
    }

    protected function tearDown(): void
    {
        foreach ([Auth::class, Authorization::class, Database::class, Health::class,
            Log::class, RateLimit::class, Route::class, Csrf::class, Session::class] as $gateway) {
            $gateway::setResolver(null);
        }
        foreach ($this->projects as $project) $project->remove();
    }

    public function testMissingConfigurationLeavesBrowserAndLegacyJsonErrorsUnchanged(): void
    {
        $app = $this->application(api: null);
        $html = $this->handle(new Request('GET', '/api/missing'), $app);
        self::assertSame(404, $html->status());
        self::assertStringContainsString('<h1>', $html->content());
        self::assertNull($html->header('X-Request-ID'));
        $json = $this->handle(new Request('GET', '/api/missing', headers: ['Accept' => 'application/json']), $app);
        self::assertSame(404, $json->status());
        self::assertArrayNotHasKey('request_id', $this->decode($json));
    }

    /** @dataProvider scopeRequests */
    public function testScopeIsDecidedBeforeRoutingAndUsesPathSegmentBoundaries(string $uri, bool $api): void
    {
        $response = $this->handle(new Request('GET', $uri));
        if ($api) {
            $this->assertError($response, 404, 'not_found', 'The requested resource was not found.');
        } else {
            self::assertSame(404, $response->status());
            self::assertStringContainsString('<h1>', $response->content());
            self::assertNull($response->header('X-Request-ID'));
        }
    }

    public static function scopeRequests(): array
    {
        return [
            'root' => ['/api', true], 'child' => ['/api/users', true],
            'nested' => ['/api/orders/123', true], 'api query' => ['/api?next=/web', true],
            'other prefix' => ['/apiary', false], 'web query' => ['/web?next=/api', false],
            'query only' => ['/?path=/api/users', false], 'case sensitive' => ['/API/users', false],
        ];
    }

    public function testExplicitDisableAndConfiguredPathsAreApplicationSpecific(): void
    {
        $disabled = $this->application(api: ['enabled' => false, 'paths' => ['/api']]);
        self::assertStringContainsString('<h1>', $this->handle(new Request('GET', '/api/users'), $disabled)->content());
        $custom = $this->application(api: ['enabled' => true, 'paths' => ['/service', '/partner/api']]);
        foreach (['/service', '/service/orders', '/partner/api/users'] as $path) {
            $this->assertError($this->handle(new Request('GET', $path), $custom), 404, 'not_found');
        }
        foreach (['/api', '/services', '/partner/apiary'] as $path) {
            self::assertStringContainsString('<h1>', $this->handle(new Request('GET', $path), $custom)->content());
        }
        $this->assertError($this->handle(new Request('GET', '/api/again'), $this->app), 404, 'not_found');
    }

    /** @dataProvider catalogue */
    public function testKnownHttpFailuresUseStableSafeCatalogue(int $status, string $code, string $message): void
    {
        $this->routes()->get('/api/failure', static function () use ($status): never {
            throw new HttpException($status, 'APP_KEY=fake-secret mysql-password /private/source.php');
        });
        $this->assertError($this->handle(new Request('GET', '/api/failure')), $status, $code, $message);
    }

    public static function catalogue(): array
    {
        return [
            [400, 'bad_request', 'The request could not be processed.'],
            [401, 'unauthenticated', 'Authentication is required.'],
            [403, 'forbidden', 'This action is not allowed.'],
            [404, 'not_found', 'The requested resource was not found.'],
            [405, 'method_not_allowed', 'The request method is not allowed.'],
            [409, 'conflict', 'The request conflicts with the current state.'],
            [422, 'validation_failed', 'The submitted data is invalid.'],
            [429, 'rate_limited', 'Too many requests.'],
            [500, 'internal_error', 'An unexpected error occurred.'],
            [503, 'service_unavailable', 'The service is temporarily unavailable.'],
        ];
    }

    public function testOtherExplicitHttpErrorsKeepTheirStatusWithoutExposingMessages(): void
    {
        foreach ([418, 451, 502] as $status) {
            $this->routes()->get('/api/status/' . $status, static function () use ($status): never {
                throw new HttpException($status, 'private HTTP exception message');
            });
            $response = $this->handle(new Request('GET', '/api/status/' . $status));
            $this->assertError($response, $status, 'http_error');
            self::assertStringNotContainsString('private', $response->content());
        }
    }

    public function testRealRouterMethodMismatchPreservesAllow(): void
    {
        $this->routes()->get('/api/users', static fn (): array => ['users' => []]);
        $this->routes()->post('/api/users', static fn (): array => ['created' => true]);
        $response = $this->handle(new Request('DELETE', '/api/users', headers: ['X-CSRF-Token' => \csrf_token()]));
        $this->assertError($response, 405, 'method_not_allowed');
        $methods = array_map('trim', explode(',', (string) $response->header('Allow')));
        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);
        self::assertNotContains('DELETE', $methods);
    }

    public function testValidationUsesExistingMessagesOnceAndDoesNotFlashOrRedirect(): void
    {
        $runs = 0;
        $this->routes()->get('/form', static fn (): string => '<form></form>');
        $this->handle(new Request('GET', '/form'));
        $this->routes()->post('/api/users', static function (Request $request) use (&$runs): array {
            ++$runs;
            return $request->validate(['email' => 'required|email', 'name' => 'required']);
        });
        $response = $this->handle(new Request('POST', '/api/users', form: [
            '_csrf' => \csrf_token(), 'email' => 'mysql-password', 'password' => 'APP_KEY=fake-secret',
        ], headers: ['Authorization' => 'Bearer fake-token', 'Cookie' => 'session-id=cookie-value']));
        $this->assertError($response, 422, 'validation_failed', 'The submitted data is invalid.', [
            'email' => ['The email field must be a valid email address.'],
            'name' => ['The name field is required.'],
        ]);
        self::assertSame(1, $runs);
        self::assertNull($response->header('Location'));
        self::assertSame([], $this->sessions()->store()->old());
        self::assertNull($this->sessions()->store()->get('_validation_errors'));
        self::assertSame('/form', $this->sessions()->store()->previousPath());
    }

    public function testBrowserValidationStillRedirectsAndFlashesFilteredInput(): void
    {
        $this->routes()->get('/form', static fn (): string => '<form></form>');
        $this->routes()->post('/submit', static fn (Request $request): array => $request->validate(['email' => 'required|email']));
        $this->handle(new Request('GET', '/form'));
        $response = $this->handle(new Request('POST', '/submit', form: [
            '_csrf' => \csrf_token(), 'email' => 'invalid', 'password' => 'private-password',
        ]));
        self::assertSame(303, $response->status());
        self::assertSame('/form', $response->header('Location'));
        self::assertNull($response->header('X-Request-ID'));
        $this->sessions()->store()->close();
        self::assertSame(['email' => 'invalid'], $this->sessions()->store()->old());
        self::assertSame(['email' => ['The email field must be a valid email address.']],
            $this->sessions()->store()->get('_validation_errors'));
    }

    public function testAuthenticationMiddlewareFormatsApiFailureAndPreservesBrowserRedirect(): void
    {
        foreach (['/api/private', '/private'] as $path) {
            $this->routes()->get($path, static fn (): string => 'must not run')->through('auth');
        }
        $response = $this->handle(new Request('GET', '/api/private'));
        $this->assertError($response, 401, 'unauthenticated', 'Authentication is required.');
        self::assertNull($response->header('WWW-Authenticate'));
        self::assertNull($response->header('Location'));
        $browser = $this->handle(new Request('GET', '/private'));
        self::assertSame(303, $browser->status());
        self::assertSame('/login', $browser->header('Location'));
        $legacy = $this->handle(new Request('GET', '/private', headers: ['Accept' => 'application/json']));
        self::assertSame(['message' => 'Authentication required.'], $this->decode($legacy));
    }

    public function testAuthenticatedGuestMiddlewareUses403AndKeepsBrowserRedirect(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('PDO SQLite is required.');
        $database = $this->app->container()->make(DatabaseManager::class);
        $database->connection()->pdo()->exec('CREATE TABLE api_error_users (id INTEGER PRIMARY KEY, email TEXT, password TEXT)');
        $database->table('api_error_users')->insert(['id' => 1, 'email' => 'ada@example.test',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
        $identity = ApiErrorUser::find(1);
        self::assertInstanceOf(ApiErrorUser::class, $identity);
        $this->app->container()->make(AuthManager::class)->login($identity);
        foreach (['/api/login', '/login'] as $path) {
            $this->routes()->get($path, static fn (): string => 'must not run')->through('guest');
        }
        $this->assertError($this->handle(new Request('GET', '/api/login')), 403, 'forbidden');
        $browser = $this->handle(new Request('GET', '/login'));
        self::assertSame(303, $browser->status());
        self::assertSame('/dashboard', $browser->header('Location'));
    }

    public function testAuthorizationUsesExistingDecisionsWithoutPublishingPolicyMessages(): void
    {
        $authorization = $this->app->container()->make(AuthorizationManager::class);
        $runs = 0;
        $authorization->define('orders.update', static function (object $identity) use (&$runs): AuthorizationDecision {
            ++$runs;
            return AuthorizationDecision::deny('PolicyClass /private/source.php APP_KEY=fake-secret');
        });
        $this->routes()->get('/api/order', static function () use ($authorization): never {
            $authorization->forIdentity(new \stdClass())->require('orders.update');
            throw new RuntimeException('must not run');
        });
        $this->assertError($this->handle(new Request('GET', '/api/order')), 403, 'forbidden');
        self::assertSame(1, $runs);
        $this->routes()->get('/api/guarded', static fn (): string => 'must not run')
            ->through(RequireAbility::named('orders.update'));
        $this->assertError($this->handle(new Request('GET', '/api/guarded')), 403, 'forbidden');
        self::assertSame(1, $runs);
    }

    public function testCsrfStillRejectsBeforeRouteAuthenticationAndValidation(): void
    {
        $runs = 0;
        foreach (['/api/submit', '/submit'] as $path) {
            $this->routes()->post($path, static function (Request $request) use (&$runs): array {
                ++$runs;
                return $request->validate(['email' => 'required']);
            })->through('auth');
        }
        $token = \csrf_token();
        $response = $this->handle(new Request('POST', '/api/submit', form: ['_csrf' => 'fake-token']));
        $this->assertError($response, 403, 'forbidden');
        self::assertStringNotContainsString($token, $response->content());
        $authenticatedNext = $this->handle(new Request('POST', '/api/submit', form: ['_csrf' => $token]));
        $this->assertError($authenticatedNext, 401, 'unauthenticated');
        $browser = $this->handle(new Request('POST', '/submit'));
        self::assertSame(403, $browser->status());
        self::assertStringContainsString('CSRF verification failed.', $browser->content());
        self::assertSame(0, $runs);
        self::assertSame([], $this->sessions()->store()->old());
    }

    public function testMalformedJsonUses400WithoutExposingRawBody(): void
    {
        $this->routes()->post('/api/json', static fn (Request $request): array => $request->validate(['name' => 'required']));
        $response = $this->handle(new Request('POST', '/api/json', headers: [
            'Content-Type' => 'application/json', 'X-CSRF-Token' => \csrf_token(),
        ], rawBody: '{"APP_KEY":"fake-secret","password":"mysql-password",'));
        $this->assertError($response, 400, 'bad_request');
        self::assertStringNotContainsString('fake-secret', $response->content());
        self::assertStringNotContainsString('mysql-password', $response->content());
    }

    public function testRateLimitRejectionKeepsWindowAndRequiredHeaders(): void
    {
        $runs = 0;
        $limiter = $this->app->container()->make(RateLimiter::class);
        $limiter->define('api.read', static fn (): RateLimitRule => RateLimitRule::fixed('private-account-key', 1, 60));
        $this->routes()->get('/api/limited', static function () use (&$runs): JsonResponse {
            ++$runs;
            return new JsonResponse(['ok' => true]);
        })->through(RateLimitRequests::named('api.read'));
        $first = $this->handle(new Request('GET', '/api/limited'));
        self::assertSame(['ok' => true], $this->decode($first));
        foreach ([1, 2] as $attempt) {
            $denied = $this->handle(new Request('GET', '/api/limited'));
            $this->assertError($denied, 429, 'rate_limited');
            self::assertSame('1', $denied->header('X-RateLimit-Limit'));
            self::assertSame('0', $denied->header('X-RateLimit-Remaining'));
            self::assertSame($first->header('X-RateLimit-Reset'), $denied->header('X-RateLimit-Reset'));
            self::assertGreaterThan(0, (int) $denied->header('Retry-After'));
            self::assertStringNotContainsString('private-account-key', $denied->content());
        }
        self::assertSame(1, $runs);
        self::assertCount(0, $this->logs()->records());
    }

    public function testRateLimitBackendFailureNeverGrantsRequest(): void
    {
        $store = new class implements RateLimitStore {
            public function consume(string $key, int $maxAttempts, int $windowSeconds, DateTimeImmutable $now): RateLimitResult
            {
                throw new RuntimeException('redis://user:password@host');
            }
            public function clear(string $key): bool { return false; }
        };
        $limiter = new RateLimiter($store, 'api-failure');
        $limiter->define('api.failed', static fn (): RateLimitRule => RateLimitRule::fixed('key', 1, 60));
        RateLimit::setResolver(static fn (): RateLimiter => $limiter);
        $ran = false;
        $this->routes()->get('/api/backend', static function () use (&$ran): string { $ran = true; return 'bad'; })
            ->through(RateLimitRequests::named('api.failed'));
        $this->assertError($this->handle(new Request('GET', '/api/backend')), 500, 'internal_error');
        self::assertFalse($ran);
        self::assertCount(1, $this->logs()->records());
    }

    public function testApplicationApiErrorWorksOutsideScopeAndPreservesPublicFields(): void
    {
        $this->routes()->get('/order', static function (): never {
            throw \App\Plugins\ApiError::make('order_conflict', 'The order cannot be modified.', 409,
                ['order' => 7, 'allowed' => ['cancel']], ['Retry-After' => '30', 'Content-Length' => '999',
                    'Cache-Control' => 'public', 'X-Request-ID' => 'application-supplied']);
        });
        $response = $this->handle(new Request('GET', '/order'));
        $this->assertError($response, 409, 'order_conflict', 'The order cannot be modified.',
            ['order' => 7, 'allowed' => ['cancel']]);
        self::assertSame('30', $response->header('Retry-After'));
        self::assertNull($response->header('Content-Length'));
        self::assertNotSame('application-supplied', $response->header('X-Request-ID'));
        self::assertCount(1, $this->logs()->records());
        self::assertStringNotContainsString('allowed', json_encode($this->logs()->records()[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testUnexpectedFailuresArePrivateInDebugAndProductionAndLoggedOnce(): void
    {
        $secrets = ['APP_KEY=fake-secret', 'Bearer fake-token', 'mysql-password',
            'redis://user:password@host', 'SMTP-password', 'cookie-value', 'session-id',
            'SELECT * FROM users WHERE password = private-binding', 'D:/private/source.php'];
        $this->routes()->get('/api/boom', static function () use ($secrets): never {
            echo 'private-controller-output';
            throw new RuntimeException(implode(' ', $secrets), 0, new RuntimeException('previous-exception-secret'));
        });
        foreach ([false, true] as $index => $debug) {
            $this->app->config()->set('app.debug', $debug);
            $response = $this->handle(new Request('GET', '/api/boom?password=mysql-password',
                query: ['APP_KEY' => 'fake-secret'], form: ['password' => 'SMTP-password'],
                cookies: ['session' => 'cookie-value'], headers: ['Authorization' => 'Bearer fake-token']));
            $this->assertError($response, 500, 'internal_error', 'An unexpected error occurred.');
            foreach ([...$secrets, 'RuntimeException', 'trace', 'previous-exception-secret', 'private-controller-output'] as $secret) {
                self::assertStringNotContainsString($secret, $response->content());
            }
            $records = $this->logs()->records();
            self::assertCount($index + 1, $records);
            self::assertSame($response->header('X-Request-ID'), $records[$index]->context['data']['request_id']);
            self::assertArrayNotHasKey('body', $records[$index]->context['data']);
            self::assertArrayNotHasKey('headers', $records[$index]->context['data']);
        }
    }

    public function testArbitraryExceptionClassesDoNotBecomeClientErrors(): void
    {
        foreach ([new InvalidArgumentException('private invalid input'), new PDOException('private SQL')] as $index => $failure) {
            $this->routes()->get('/api/arbitrary/' . $index, static function () use ($failure): never { throw $failure; });
            $this->assertError($this->handle(new Request('GET', '/api/arbitrary/' . $index)), 500, 'internal_error');
        }
    }

    public function testUnsafeApplicationDetailsAlwaysFallBackToSafe500(): void
    {
        $stream = fopen('php://memory', 'r+');
        $nested = ['secret' => 'private-nested-data'];
        for ($depth = 0; $depth < 520; ++$depth) $nested = ['nested' => $nested];
        $circular = [];
        $circular['self'] = &$circular;
        $unsafe = [
            ['object' => (object) ['password' => 'mysql-password']],
            ['model' => new ApiErrorUser(['password' => 'mysql-password'])],
            ['stream' => $stream], ['invalid_utf8' => "\xB1\x31"],
            ['infinite' => INF], ['not_number' => NAN], $nested, $circular,
        ];
        try {
            foreach ($unsafe as $index => $details) {
                $this->routes()->get('/api/unsafe/' . $index, static function () use ($details): never {
                    throw ApiError::make('application_failure', 'Public message', 409, $details);
                });
                $this->assertError($this->handle(new Request('GET', '/api/unsafe/' . $index)),
                    500, 'internal_error', 'An unexpected error occurred.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function testMalformedValidationDetailsCannotBypassSafeRendering(): void
    {
        $this->routes()->get('/api/invalid-validation', static function (): never {
            throw new ValidationException(['email' => [(object) ['password' => 'mysql-password']]]);
        });
        $this->assertError($this->handle(new Request('GET', '/api/invalid-validation')),
            500, 'internal_error', 'An unexpected error occurred.');
    }

    public function testRequestIdsAreFreshForReusedRequestsAndIgnoreUntrustedSources(): void
    {
        $request = new Request('GET', '/api/missing', headers: ['X-Request-ID' => 'caller-controlled']);
        $request->setAttribute('request_id', 'attribute-controlled');
        $request->setAttribute('api.request_id', 'attribute-controlled');
        $ids = [];
        foreach ([1, 2, 3] as $iteration) {
            $response = $this->handle($request);
            $this->assertError($response, 404, 'not_found');
            $ids[] = $response->header('X-Request-ID');
            self::assertSame($request->requestId(), $response->header('X-Request-ID'));
        }
        self::assertCount(3, array_unique($ids));
        self::assertNotContains('caller-controlled', $ids);
        self::assertNotContains('attribute-controlled', $ids);
    }

    public function testDiagnosticsUsesSameIdAndDoesNotRetainIdsInSubsystemAggregates(): void
    {
        $app = $this->application(diagnostics: true);
        $app->config()->set('diagnostics.response_header', false);
        $request = new Request('GET', '/api/missing');
        $response = $this->handle($request, $app);
        $this->assertError($response, 404, 'not_found');
        $diagnostics = $app->container()->make(Diagnostics::class);
        self::assertSame($request->requestId(), $diagnostics->requestId());
        foreach (['auth', 'authorization', 'rate_limit', 'cache', 'events', 'redis'] as $subsystem) {
            self::assertStringNotContainsString($request->requestId(), json_encode($diagnostics->snapshot()[$subsystem], JSON_THROW_ON_ERROR));
        }
    }

    public function testScopeIsCapturedBeforeControllerMutationsAndRenewedOnNextHandle(): void
    {
        $config = $this->app->config();
        $this->routes()->get('/api/mutate', static function () use ($config): never {
            $config->set('api.enabled', false);
            throw new RuntimeException('private failure after changing configuration');
        });
        $request = new Request('GET', '/api/mutate');
        $request->setAttribute('_squehub.api_scope', false);
        $first = $this->handle($request);
        $this->assertError($first, 500, 'internal_error', 'An unexpected error occurred.');
        $second = $this->handle($request);
        self::assertSame(500, $second->status());
        self::assertStringContainsString('<h1>', $second->content());
        self::assertNull($second->header('X-Request-ID'));
        $browser = new Request('GET', '/web');
        $browser->setAttribute('_squehub.api_scope', true);
        self::assertStringContainsString('<h1>', $this->handle($browser)->content());
    }

    public function testFailureThenSuccessThenBrowserRequestsHaveIndependentState(): void
    {
        $this->routes()->get('/api/success', static fn (): JsonResponse => new JsonResponse(['ok' => true], 200,
            ['Cache-Control' => 'max-age=60', 'X-Request-ID' => 'controller-value']));
        $this->routes()->get('/browser', static fn (): string => '<h1>Browser</h1>');
        $failed = $this->handle(new Request('GET', '/api/missing'));
        $success = $this->handle(new Request('GET', '/api/success'));
        self::assertSame(['ok' => true], $this->decode($success));
        self::assertSame('max-age=60', $success->header('Cache-Control'));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $success->header('X-Request-ID'));
        self::assertNotSame($failed->header('X-Request-ID'), $success->header('X-Request-ID'));
        $browser = $this->handle(new Request('GET', '/browser'));
        self::assertSame('<h1>Browser</h1>', $browser->content());
        self::assertNull($browser->header('X-Request-ID'));
        self::assertNull($browser->header('Cache-Control'));
    }

    public function testSuccessfulResourceShapesAndCustomErrorResponsesRemainUntouched(): void
    {
        $row = ['id' => 1, 'name' => 'Ada', 'password' => 'private'];
        $single = PublicErrorResource::make($row);
        $collection = PublicErrorResource::collection([$row]);
        $metadata = PublicErrorResource::make($row)->withMeta(['source' => 'application']);
        $page = PublicErrorResource::collection(new Page([$row], 1, 1, 2));
        $responses = [
            'single' => $single->response(), 'collection' => $collection->response(),
            'metadata' => $metadata->response(), 'page' => $page->response(),
            'json' => new JsonResponse(['custom' => true], 201),
            'custom-error' => new JsonResponse(['application' => 'custom error'], 422, ['Cache-Control' => 'private']),
            'redirect' => new RedirectResponse('/destination', 307),
        ];
        self::assertSame(['id' => 1, 'name' => 'Ada'], $single->resolve());
        self::assertSame([['id' => 1, 'name' => 'Ada']], $collection->resolve());
        self::assertSame(['data' => ['id' => 1, 'name' => 'Ada'], 'meta' => ['source' => 'application']], $metadata->resolve());
        self::assertSame(['data' => [['id' => 1, 'name' => 'Ada']], 'meta' => [
            'page' => 1, 'per_page' => 1, 'total' => 2, 'pages' => 2, 'from' => 1, 'to' => 1,
            'has_next' => true, 'has_previous' => false,
        ]], $page->resolve());
        foreach ($responses as $name => $expected) {
            $this->routes()->get('/api/' . $name, static fn (): Response => $expected);
            $actual = $this->handle(new Request('GET', '/api/' . $name));
            self::assertSame($expected->content(), $actual->content());
            self::assertSame($expected->status(), $actual->status());
            self::assertSame($expected->header('Cache-Control'), $actual->header('Cache-Control'));
            self::assertSame($expected->header('Location'), $actual->header('Location'));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $actual->header('X-Request-ID'));
        }
    }

    public function testHeadAndBodylessStatusesNeverEmitJsonBodies(): void
    {
        $head = $this->handle(new Request('HEAD', '/api/missing'));
        self::assertSame(404, $head->status());
        ob_start();
        $head->send(true);
        self::assertSame('', ob_get_clean());
        foreach ([204, 205, 304] as $status) {
            $this->routes()->get('/api/empty/' . $status, static fn (): Response => new Response('must not emit', $status));
            $response = $this->handle(new Request('GET', '/api/empty/' . $status));
            self::assertSame($status, $response->status());
            ob_start();
            $response->send();
            self::assertSame('', ob_get_clean());
        }
    }

    public function testHealthRetainsItsMinimalContractEvenUnderGlobalApiScope(): void
    {
        $app = $this->application(api: ['enabled' => true, 'paths' => ['/']], health: true);
        foreach (['/health/live' => [200, ['status' => 'ok']], '/health/ready' => [503, ['status' => 'unavailable']]] as $path => [$status, $body]) {
            $response = $this->handle(new Request('GET', $path), $app);
            self::assertSame($status, $response->status());
            self::assertSame($body, $this->decode($response));
            self::assertSame('no-store, max-age=0', $response->header('Cache-Control'));
            self::assertNull($response->header('X-Request-ID'));
        }
        $method = $this->handle(new Request('DELETE', '/health/live', headers: ['X-CSRF-Token' => \csrf_token()]), $app);
        self::assertSame(405, $method->status());
        self::assertStringContainsString('<h1>', $method->content());
        self::assertNull($method->header('X-Request-ID'));
    }

    private function application(?array $api = ['enabled' => true, 'paths' => ['/api']], bool $diagnostics = false, bool $health = false): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $configs = [
            'App' => ['env' => 'testing', 'debug' => false],
            'Session' => ['driver' => 'array'], 'Logging' => ['driver' => 'array', 'level' => 'debug'],
            'Csrf' => ['enabled' => true, 'field' => '_csrf', 'header' => 'X-CSRF-Token', 'except' => []],
            'RateLimit' => ['store' => 'array', 'prefix' => 'api-errors', 'path' => null],
            'Database' => ['default' => 'main', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'Authorization' => ['abilities' => [], 'policies' => []],
            'Health' => ['endpoints_enabled' => $health, 'middleware' => []],
            'Auth' => ['default' => 'web', 'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
                'identities' => ['users' => ['driver' => 'model', 'model' => ApiErrorUser::class,
                    'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4], 'rehash_on_login' => false],
                'browser' => ['login_path' => '/login', 'authenticated_path' => '/dashboard']],
        ];
        if ($api !== null) $configs['Api'] = $api;
        foreach ($configs as $name => $config) $project->write('Config/' . $name . '.php', '<?php return ' . var_export($config, true) . ';');
        $app = new Application($project->path());
        if ($diagnostics) $app->register(DiagnosticsServiceProvider::class);
        foreach ([LoggingServiceProvider::class, DatabaseServiceProvider::class,
            \App\Session\SessionServiceProvider::class, ValidationServiceProvider::class, CsrfServiceProvider::class,
            HttpServiceProvider::class, BrowserFormsServiceProvider::class, RoutingServiceProvider::class,
            AuthServiceProvider::class, AuthorizationServiceProvider::class, RateLimitServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        if ($health) $app->register(HealthServiceProvider::class);
        $app->bootstrap();
        return $app;
    }

    private function routes(): RouteRegistry { return $this->app->container()->make(RouteRegistry::class); }
    private function sessions(): SessionManager { return $this->app->container()->make(SessionManager::class); }
    private function logs(): ArrayLogger { return $this->app->container()->make(ArrayLogger::class); }
    private function handle(Request $request, ?Application $app = null): Response
    {
        return ($app ?? $this->app)->container()->make(Kernel::class)->handle($request);
    }
    private function decode(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }
    private function assertError(Response $response, int $status, string $code, ?string $message = null, ?array $details = null): void
    {
        self::assertSame($status, $response->status(), $response->content());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        $id = $response->header('X-Request-ID');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $id);
        $payload = $this->decode($response);
        $error = ['code' => $code, 'message' => $message ?? ($payload['error']['message'] ?? null)];
        self::assertIsString($error['message']);
        self::assertNotSame('', $error['message']);
        if ($details !== null) $error['details'] = $details;
        self::assertSame(['error' => $error, 'request_id' => $id], $payload);
    }
}

/** Identity fixture opens SQLite only in the authenticated guest test. */
final class ApiErrorUser extends Model implements Authenticatable
{
    protected string $table = 'api_error_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];
    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}

/** Uses Phase 12A's existing field projection, metadata and pagination APIs. */
final class PublicErrorResource extends ApiResource
{
    public function toArray(): array { return ['id' => $this->resource['id'], 'name' => $this->resource['name']]; }
}
