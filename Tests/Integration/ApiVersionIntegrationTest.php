<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ApiVersion;

use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class ApiVersionIntegrationTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        Route::setResolver(null);
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    public function testUriGroupsResolveSemanticMetadataWithoutDuplicatingRoutes(): void
    {
        $app = $this->application();
        Route::group()->prefix('/api/v1')->apiVersion('1')->routes(static function (): void {
            Route::group()->prefix('/users')->routes(static function (): void {
                Route::path('/')->get(static fn (Request $request): array => ['version' => $request->apiVersion()]);
            });
        });
        Route::group()->prefix('/api/v2')->apiVersion('2')->routes(static function (): void {
            Route::path('/users')->get(static fn (Request $request): array => ['version' => $request->apiVersion()]);
        });
        Route::path('/web')->get(static fn (Request $request): array => ['version' => $request->apiVersion()]);

        $one = new Request('GET', '/api/v1/users');
        $two = new Request('GET', '/api/v2/users');
        $web = new Request('GET', '/web');
        self::assertSame(['version' => '1'], $this->json($this->handle($app, $one)));
        self::assertSame(['version' => '2'], $this->json($this->handle($app, $two)));
        self::assertSame(['version' => null], $this->json($this->handle($app, $web)));
        self::assertSame('1', $one->apiVersion());
        self::assertSame('2', $two->apiVersion());
        self::assertNull($web->apiVersion());
        self::assertSame(404, $this->handle($app, new Request('GET', '/api/v3/users'))->status());
    }

    public function testVersionMetadataWorksOnOrdinaryPathsAndNormalizesTokens(): void
    {
        $app = $this->application();
        $route = Route::path('/api/current')->get(
            static fn (Request $request): array => ['version' => $request->apiVersion()]
        )->apiVersion('  BETA  ');
        self::assertSame('beta', $route->apiVersionValue());
        self::assertSame(['version' => 'beta'],
            $this->json($this->handle($app, new Request('GET', '/api/current'))));
    }

    public function testArrayGroupsInheritVersionAndConflictsFailAtDeclaration(): void
    {
        $app = $this->application();
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->group(['prefix' => '/api', 'api_version' => '2026-09'], static function (RouteRegistry $r): void {
            $r->group(['prefix' => '/nested'], static function (RouteRegistry $nested): void {
                $nested->get('/users', static fn (Request $request): array => ['version' => $request->apiVersion()]);
            });
        });
        self::assertSame(['version' => '2026-09'],
            $this->json($this->handle($app, new Request('GET', '/api/nested/users'))));

        try {
            Route::group()->apiVersion('1')->routes(static function (): void {
                Route::group()->apiVersion('2')->routes(static function (): void {});
            });
            self::fail('Conflicting nested group version was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('conflicting API versions', $exception->getMessage());
        }
        try {
            Route::group()->apiVersion('1')->routes(static function (): void {
                Route::path('/api/conflict')->get(static fn (): string => 'unexpected')->apiVersion('2');
            });
            self::fail('Conflicting route version was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('conflicting API versions', $exception->getMessage());
        }
    }

    /** @dataProvider invalidVersions */
    public function testInvalidDeclarationTokensFailEarly(string $version): void
    {
        $this->application();
        $this->expectException(InvalidArgumentException::class);
        Route::group()->apiVersion($version);
    }

    public static function invalidVersions(): array
    {
        return [[''], ['v 1'], ["v1\nmore"], ["v1\n"], [str_repeat('a', 33)], ['https://example.test']];
    }

    /** @dataProvider invalidVersioningOptions */
    public function testInvalidVersioningConfigurationFailsDuringBootstrap(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->applicationWithVersioning($options);
    }

    public static function invalidVersioningOptions(): array
    {
        return [
            [['header']],
            [['strategy' => 'automatic']],
            [['strategy' => 'header', 'header' => "X-Version\nInjected"]],
            [['strategy' => 'uri', 'extra' => true]],
        ];
    }

    public function testHeaderStrategyValidatesVersionBeforeMiddlewareAndUsesManagedErrors(): void
    {
        $app = $this->application('header');
        $runs = 0;
        Route::path('/api/users')->get(static function (Request $request) use (&$runs): array {
            ++$runs;
            return ['version' => $request->apiVersion()];
        })->apiVersion('Beta');

        $valid = $this->handle($app, new Request('GET', '/api/users', headers: ['x-api-version' => ' BETA ']));
        self::assertSame(['version' => 'beta'], $this->json($valid));
        self::assertSame('X-API-Version', $valid->header('Vary'));
        self::assertSame(1, $runs);

        foreach ([
            [[], 'api_version_required'],
            [['X-API-Version' => '2'], 'unsupported_api_version'],
            [['X-API-Version' => 'beta,beta'], 'api_version_conflict'],
            [['X-API-Version' => 'beta', 'x-api-version' => '2'], 'api_version_conflict'],
            [['X-API-Version' => 'beta value'], 'unsupported_api_version'],
            [['X-API-Version' => "beta\n"], 'unsupported_api_version'],
            [['X-API-Version' => str_repeat('b', 33)], 'unsupported_api_version'],
        ] as [$headers, $code]) {
            $response = $this->handle($app, new Request('GET', '/api/users', headers: $headers));
            self::assertSame(400, $response->status());
            self::assertSame($code, $this->json($response)['error']['code']);
            self::assertSame('X-API-Version', $response->header('Vary'));
            self::assertSame('no-store', $response->header('Cache-Control'));
            self::assertSame($response->header('X-Request-ID'), $this->json($response)['request_id']);
            self::assertStringNotContainsString('/api/users', $response->content());
        }
        self::assertSame(1, $runs);
    }

    public function testHeaderStrategyMergesVaryAndIgnoresUnversionedRoutes(): void
    {
        $app = $this->application('header');
        Route::path('/api/one')->get(static fn (): Response => new Response('one', 200,
            ['Vary' => 'Accept-Language']))->apiVersion('1');
        Route::path('/api/two')->get(static fn (): Response => new Response('two', 200,
            ['Vary' => 'Accept-Language, x-api-version']))->apiVersion('2');
        Route::path('/api/plain')->get(static fn (): Response => new Response('plain'));

        self::assertSame('Accept-Language, X-API-Version',
            $this->handle($app, new Request('GET', '/api/one', headers: ['X-API-Version' => '1']))->header('Vary'));
        self::assertSame('Accept-Language, x-api-version',
            $this->handle($app, new Request('GET', '/api/two', headers: ['X-API-Version' => '2']))->header('Vary'));
        $plain = $this->handle($app, new Request('GET', '/api/plain', headers: ['X-API-Version' => 'wrong']));
        self::assertSame(200, $plain->status());
        self::assertNull($plain->header('Vary'));
    }

    public function testHeaderStrategyUsesConfiguredHeaderName(): void
    {
        $app = $this->applicationWithVersioning(['strategy' => 'header', 'header' => 'X-Service-Version']);
        $app->container()->make(RouteRegistry::class)->get('/api/item',
            static fn (Request $request): array => ['version' => $request->apiVersion()])->apiVersion('2026-09');

        $response = $this->handle($app, new Request('GET', '/api/item',
            headers: ['X-Service-Version' => '2026-09']));
        self::assertSame(['version' => '2026-09'], $this->json($response));
        self::assertSame('X-Service-Version', $response->header('Vary'));
        $missing = $this->handle($app, new Request('GET', '/api/item',
            headers: ['X-API-Version' => '2026-09']));
        self::assertSame('api_version_required', $this->json($missing)['error']['code']);
        self::assertSame('X-Service-Version', $missing->header('Vary'));
    }

    public function testSeparateApplicationsKeepTheirOwnVersionStrategy(): void
    {
        $header = $this->application('header');
        $uri = $this->application('uri');
        $header->container()->make(RouteRegistry::class)->get('/api/item',
            static fn (Request $request): array => ['version' => $request->apiVersion()])->apiVersion('1');
        $uri->container()->make(RouteRegistry::class)->get('/api/item',
            static fn (Request $request): array => ['version' => $request->apiVersion()])->apiVersion('2');

        self::assertSame('api_version_required',
            $this->json($this->handle($header, new Request('GET', '/api/item')))['error']['code']);
        self::assertSame(['version' => '2'],
            $this->json($this->handle($uri, new Request('GET', '/api/item'))));
        self::assertSame(['version' => '1'],
            $this->json($this->handle($header, new Request('GET', '/api/item',
                headers: ['X-API-Version' => '1']))));
    }

    public function testApi404BypassesLegacyBrowserNotFoundHandler(): void
    {
        $app = $this->application();
        $app->container()->make(RouteRegistry::class)->setLegacyNotFoundHandler(
            static fn (): string => '<p>browser fallback</p>'
        );

        $api = $this->handle($app, new Request('GET', '/api/missing'));
        self::assertSame(404, $api->status());
        self::assertSame('not_found', $this->json($api)['error']['code']);
        self::assertStringNotContainsString('browser fallback', $api->content());
        $web = $this->handle($app, new Request('GET', '/web/missing'));
        self::assertSame(404, $web->status());
        self::assertStringContainsString('browser fallback', $web->content());
    }

    public function testHeaderVersionAndCorsPolicyComposeWithoutBroadeningEitherPermission(): void
    {
        $app = $this->applicationWithVersioning(
            ['strategy' => 'header', 'header' => 'X-API-Version'],
            [
                'enabled' => true,
                'paths' => ['/api'],
                'allowed_origins' => ['https://client.example'],
                'allowed_methods' => ['GET'],
                'allowed_headers' => ['X-API-Version'],
                'exposed_headers' => [],
                'allow_credentials' => false,
                'max_age' => 60,
            ]
        );
        $app->container()->make(RouteRegistry::class)->get('/api/users',
            static fn (Request $request): array => ['version' => $request->apiVersion()]
        )->apiVersion('1');

        $preflight = $this->handle($app, new Request('OPTIONS', '/api/users', headers: [
            'Origin' => 'https://client.example',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'X-API-Version',
        ]));
        self::assertSame(204, $preflight->status());
        self::assertSame('X-API-Version', $preflight->header('Access-Control-Allow-Headers'));

        $allowed = $this->handle($app, new Request('GET', '/api/users', headers: [
            'Origin' => 'https://client.example', 'X-API-Version' => '1',
        ]));
        self::assertSame(200, $allowed->status());
        self::assertSame('1', $this->json($allowed)['version']);
        self::assertSame('https://client.example', $allowed->header('Access-Control-Allow-Origin'));
        self::assertSame(['X-API-Version', 'Origin'], array_map('trim', explode(',', (string) $allowed->header('Vary'))));

        $missing = $this->handle($app, new Request('GET', '/api/users', headers: [
            'Origin' => 'https://client.example',
        ]));
        self::assertSame(400, $missing->status());
        self::assertSame('api_version_required', $this->json($missing)['error']['code']);
        self::assertSame('https://client.example', $missing->header('Access-Control-Allow-Origin'));

        $deniedOrigin = $this->handle($app, new Request('GET', '/api/users', headers: [
            'Origin' => 'https://other.example', 'X-API-Version' => '1',
        ]));
        self::assertSame(200, $deniedOrigin->status());
        self::assertNull($deniedOrigin->header('Access-Control-Allow-Origin'));
    }

    private function application(string $strategy = 'uri'): Application
    {
        return $this->applicationWithVersioning(['strategy' => $strategy, 'header' => 'X-API-Version']);
    }

    private function applicationWithVersioning(array $versioning, array $cors = []): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Config/Api.php', '<?php return ' . var_export([
            'enabled' => true, 'paths' => ['/api'],
            'versioning' => $versioning,
            'cors' => $cors,
        ], true) . ';');
        $app = new Application($project->path());
        $app->register(HttpServiceProvider::class);
        $app->register(RoutingServiceProvider::class);
        $app->bootstrap();
        return $app;
    }

    private function handle(Application $app, Request $request): Response
    {
        return $app->container()->make(Kernel::class)->handle($request);
    }

    private function json(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }
}
