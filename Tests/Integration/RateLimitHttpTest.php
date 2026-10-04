<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\LoggingServiceProvider;
use App\RateLimit\Middleware\RateLimitRequests;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitRule;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\SessionServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** HTTP composition without Auth, Database, Cache, Events, or Storage providers. */
final class RateLimitHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private RateLimiter $limiter;
    private Diagnostics $diagnostics;
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/RateLimit.php', '<?php return ["store" => "array", "prefix" => "http-test", "path" => null];');
        $this->project->write('Config/Logging.php', '<?php return ["driver" => "array", "level" => "debug"];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Csrf.php', '<?php return ["enabled" => true, "field" => "_csrf", "header" => "X-CSRF-Token", "except" => []];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class,
            SessionServiceProvider::class, CsrfServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class,
            \App\RateLimit\RateLimitServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->kernel = $container->make(Kernel::class);
        $this->limiter = $container->make(RateLimiter::class);
        $this->diagnostics = $container->make(Diagnostics::class);
        $this->limiter->define('login', static fn (Request $request): RateLimitRule =>
            RateLimitRule::fixed((string) $request->input('email', 'anonymous'), 1, 60));
        $container->make(RouteRegistry::class)->add('GET', '/limited', function (): Response {
            ++$this->runs;
            return new Response('ok', 200, ['X-RateLimit-Limit' => '999']);
        })->through(RateLimitRequests::named('login'));
        $container->make(RouteRegistry::class)->add('POST', '/submit', function (): Response {
            ++$this->runs;
            return new Response('ok');
        })->through(RateLimitRequests::named('login'));
        $container->make(RouteRegistry::class)->add('GET', '/missing-limiter',
            static fn (): Response => new Response('should not run'))
            ->through(RateLimitRequests::named('undefined'));
    }

    protected function tearDown(): void
    {
        RateLimit::setResolver(null);
        Route::setResolver(null);
        \App\Logging\Log::setResolver(null);
        \App\Session\Session::setResolver(null);
        \App\Security\Csrf\Csrf::setResolver(null);
        $this->project->remove();
    }

    public function testAllowedDeniedHeadersJsonPrivacyAndDiagnosticsReset(): void
    {
        $first = $this->kernel->handle(new Request('GET', '/limited', headers: ['Accept' => 'application/json']));
        self::assertSame(200, $first->status());
        self::assertSame('1', $first->header('X-RateLimit-Limit'));
        self::assertSame('0', $first->header('X-RateLimit-Remaining'));
        self::assertNotNull($first->header('X-RateLimit-Reset'));
        self::assertNull($first->header('Retry-After'));
        self::assertSame(1, $this->runs);
        $second = $this->kernel->handle(new Request('GET', '/limited', headers: ['Accept' => 'application/json']));
        self::assertSame(429, $second->status());
        self::assertSame(['message' => 'Too many requests.'], json_decode($second->content(), true));
        self::assertSame('1', $second->header('X-RateLimit-Limit'));
        self::assertSame('0', $second->header('X-RateLimit-Remaining'));
        self::assertGreaterThan(0, (int) $second->header('Retry-After'));
        self::assertNotNull($second->header('X-Request-ID'));
        self::assertSame(1, $this->runs);
        self::assertSame(1, $this->diagnostics->snapshot()['rate_limit']['checks']);
        self::assertSame(1, $this->diagnostics->snapshot()['rate_limit']['denied']);
        self::assertSame(0, $this->diagnostics->snapshot()['rate_limit']['errors']);
        self::assertGreaterThanOrEqual(0.0, $this->diagnostics->snapshot()['rate_limit']['time_ms']);
        self::assertStringNotContainsString('anonymous', json_encode($this->diagnostics->snapshot()['rate_limit']));
        $logs = $this->app->container()->make(ArrayLogger::class)->records();
        self::assertCount(0, $logs);
        $third = $this->kernel->handle(new Request('GET', '/unknown'));
        self::assertSame(0, $this->diagnostics->snapshot()['rate_limit']['checks']);
    }

    public function testHtmlDenialAndMissingLimiterInfrastructureError(): void
    {
        $this->kernel->handle(new Request('GET', '/limited'));
        $denied = $this->kernel->handle(new Request('GET', '/limited'));
        self::assertSame(429, $denied->status());
        self::assertStringContainsString('429 Too Many Requests', $denied->content());
        self::assertStringNotContainsString('login', $denied->content());
        $missing = $this->kernel->handle(new Request('GET', '/missing-limiter'));
        self::assertSame(500, $missing->status());
        self::assertStringNotContainsString('undefined', $missing->content());
        self::assertSame(1, $this->diagnostics->snapshot()['rate_limit']['errors']);
        self::assertCount(1, $this->app->container()->make(ArrayLogger::class)->records());
    }

    public function testGlobalCsrfRejectsBeforeRouteLimiter(): void
    {
        $response = $this->kernel->handle(new Request('POST', '/submit'));
        self::assertSame(403, $response->status());
        self::assertSame(0, $this->diagnostics->snapshot()['rate_limit']['checks']);
        self::assertSame(0, $this->runs);
    }

    public function testValidCsrfCanReachLimiterAndRouteOrderIsPreserved(): void
    {
        $token = \csrf_token();
        $request = new Request('POST', '/submit', form: ['_csrf' => $token]);
        self::assertSame(200, $this->kernel->handle($request)->status());
        self::assertSame(429, $this->kernel->handle($request)->status());
        self::assertSame(1, $this->runs);

        $guard = new class {
            public function handle(Request $request, \Closure $next): Response { return new Response('guest rejected', 403); }
        };
        $routes = $this->app->container()->make(RouteRegistry::class);
        $routes->add('GET', '/guard-first', static fn (): Response => new Response('bad'))
            ->through([$guard, RateLimitRequests::named('login')]);
        $routes->add('GET', '/limit-first', static fn (): Response => new Response('bad'))
            ->through([RateLimitRequests::named('login'), $guard]);
        self::assertSame(403, $this->kernel->handle(new Request('GET', '/guard-first', form: ['email' => 'new']))->status());
        self::assertSame(0, $this->diagnostics->snapshot()['rate_limit']['checks']);
        self::assertSame(403, $this->kernel->handle(new Request('GET', '/limit-first', form: ['email' => 'new']))->status());
        self::assertSame(1, $this->diagnostics->snapshot()['rate_limit']['checks']);
    }

    public function testHelperDirectUseAndClear(): void
    {
        self::assertSame($this->limiter, \rateLimiter());
        self::assertTrue(\rateLimiter()->consume('reports.export', 'account-123', 1, 60)->allowed());
        self::assertTrue(\rateLimiter()->consume('reports.export', 'account-123', 1, 60)->denied());
        self::assertTrue(\rateLimiter()->clear('reports.export', 'account-123'));
        self::assertTrue(\rateLimiter()->consume('reports.export', 'account-123', 1, 60)->allowed());
    }
}
