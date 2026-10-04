<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises proxy authority through the real Kernel, Router, and CORS probe. */
final class TrustedProxyHttpTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->projects as $project) {
                $project->remove();
            }
        } finally {
            \App\Routing\Route::setResolver(null);
            parent::tearDown();
        }
    }

    public function testTrustedForwardedHostMatchesRestrictedRouteButDirectSpoofCannot(): void
    {
        $app = $this->application(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded']);
        $app->container()->make(RouteRegistry::class)->get('/admin',
            static fn (): string => 'private', 'admin.example.test');
        $headers = ['X-Forwarded-Host' => 'admin.example.test',
            'X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'];

        $trusted = $this->handle($app, $this->request('10.0.0.8', $headers,
            'internal.example.test:8080', 'GET', '/admin'));
        self::assertSame(200, $trusted->status());
        self::assertSame('private', $trusted->content());

        $untrusted = $this->handle($app, $this->request('198.51.100.8', $headers,
            'internal.example.test:8080', 'GET', '/admin'));
        self::assertSame(404, $untrusted->status());
        self::assertStringNotContainsString('private', $untrusted->content());

        $direct = $this->application();
        $direct->container()->make(RouteRegistry::class)->get('/admin',
            static fn (): string => 'private', 'admin.example.test');
        self::assertSame(404, $this->handle($direct, $this->request('10.0.0.8', $headers,
            'internal.example.test:8080', 'GET', '/admin'))->status());
    }

    public function testAllowedHostsRejectDirectAndTrustedForwardedHostsOutsidePolicy(): void
    {
        $app = $this->application([
            'proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded',
            'allowed_hosts' => ['app.example.test', '*.example.com'],
        ]);
        $app->container()->make(RouteRegistry::class)->get('/ready', static fn (): string => 'ready');

        self::assertSame(200, $this->handle($app, $this->request('198.51.100.8', [],
            'app.example.test:8080', 'GET', '/ready'))->status());
        self::assertSame(200, $this->handle($app, $this->request('198.51.100.8', [],
            'tenant.example.com', 'GET', '/ready'))->status());
        foreach (['example.com', 'evil-example.com', 'app.example.test.evil'] as $host) {
            self::assertSame(400, $this->handle($app,
                $this->request('198.51.100.8', [], $host, 'GET', '/ready'))->status(), $host);
        }

        $allowed = ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'app.example.test'];
        self::assertSame(200, $this->handle($app, $this->request('10.0.0.8', $allowed,
            'internal.example.test', 'GET', '/ready'))->status());
        self::assertSame(400, $this->handle($app, $this->request('10.0.0.8',
            [...$allowed, 'X-Forwarded-Host' => 'evil.example.test'], 'internal.example.test',
            'GET', '/ready'))->status());
    }

    public function testCorsPreflightProbesTheTrustedEffectiveHost(): void
    {
        $app = $this->application(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded'], true);
        $runs = 0;
        $app->container()->make(RouteRegistry::class)->get('/api/hosted',
            static function () use (&$runs): string {
                $runs++;
                return 'hosted';
            }, 'api.example.test');
        $headers = [
            'Origin' => 'https://frontend.example.test',
            'Access-Control-Request-Method' => 'GET',
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'api.example.test',
        ];

        $trusted = $this->handle($app, $this->request('10.0.0.8', $headers,
            'internal.example.test', 'OPTIONS', '/api/hosted'));
        self::assertSame(204, $trusted->status());
        self::assertSame('https://frontend.example.test',
            $trusted->header('Access-Control-Allow-Origin'));
        self::assertSame(0, $runs);

        $spoofed = $this->handle($app, $this->request('198.51.100.8', $headers,
            'internal.example.test', 'OPTIONS', '/api/hosted'));
        self::assertSame(403, $spoofed->status());
        self::assertNull($spoofed->header('Access-Control-Allow-Origin'));
        self::assertSame(0, $runs);
    }

    public function testApplicationAndRequestPoliciesDoNotLeakBetweenContexts(): void
    {
        $trusted = $this->application(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded']);
        $direct = $this->application();
        foreach ([$trusted, $direct] as $app) {
            $app->container()->make(RouteRegistry::class)->get('/metadata',
                static fn (Request $request): string => implode('|', [
                    $request->ip(), $request->scheme(), $request->host(), $request->port(),
                ]));
        }
        $headers = ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example.test:8443'];

        self::assertSame('203.0.113.7|https|public.example.test|8443',
            $this->handle($trusted, $this->request('10.0.0.8', $headers))->content());
        self::assertSame('10.0.0.8|http|internal.example.test|8080',
            $this->handle($direct, $this->request('10.0.0.8', $headers))->content());
        self::assertSame('198.51.100.8|http|internal.example.test|8080',
            $this->handle($trusted, $this->request('198.51.100.8', $headers))->content());
    }

    /** @param array<string, mixed> $proxy */
    private function application(array $proxy = [], bool $cors = false): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $config = [
            'App' => ['env' => 'testing', 'debug' => false],
            'TrustedProxies' => $proxy,
        ];
        if ($cors) {
            $config['Api'] = [
                'enabled' => true, 'paths' => ['/api'],
                'cors' => [
                    'enabled' => true, 'paths' => ['/api'],
                    'allowed_origins' => ['https://frontend.example.test'],
                    'allowed_methods' => ['GET'],
                    'allowed_headers' => [],
                    'exposed_headers' => [],
                    'allow_credentials' => false,
                    'max_age' => 600,
                ],
            ];
        }
        foreach ($config as $name => $values) {
            $project->write('Config/' . $name . '.php', '<?php return ' . var_export($values, true) . ';');
        }
        $app = new Application($project->path());
        foreach ([HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }

    /** @param array<string, string> $headers */
    private function request(string $peer, array $headers = [], string $host = 'internal.example.test:8080',
        string $method = 'GET', string $uri = '/metadata'): Request
    {
        return new Request($method, $uri, headers: $headers, server: [
            'REMOTE_ADDR' => $peer, 'HTTP_HOST' => $host, 'SERVER_PORT' => '8080', 'HTTPS' => 'off',
        ]);
    }

    private function handle(Application $app, Request $request): Response
    {
        return $app->container()->make(Kernel::class)->handle($request);
    }
}
