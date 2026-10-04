<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises mounted paths through the real Application, Kernel, and Router. */
final class BasePathHttpTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        try {
            Route::setResolver(null);
            Database::setResolver(null);
            foreach ($this->projects as $project) {
                $project->remove();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testRootAndRequiredRoutesUseApplicationPathWhileKeepingRawUri(): void
    {
        $app = $this->application('/app');
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/', static fn (): string => 'home');
        $routes->get('/users/{id}', static fn (Request $request, string $id): array => [
            'id' => $id,
            'path' => $request->path(),
            'raw' => $request->rawPath(),
            'base' => $request->basePath(),
            'uri' => $request->uri(),
            'query' => $request->query('tab'),
        ]);

        self::assertSame('home', $this->handle($app, new Request('GET', '/app'))->content());
        self::assertSame('home', $this->handle($app, new Request('GET', '/app/'))->content());
        $request = new Request('GET', '/app/users/42?tab=profile', ['tab' => 'profile']);
        $response = $this->handle($app, $request);
        self::assertSame(200, $response->status(), $response->content());
        self::assertSame([
            'id' => '42', 'path' => '/users/42', 'raw' => '/app/users/42',
            'base' => '/app', 'uri' => '/app/users/42?tab=profile', 'query' => 'profile',
        ], $this->json($response));
        self::assertSame(['id' => '42'], $request->route());
    }

    public function testOutsideMountDoesNotReachFallbackOrController(): void
    {
        $app = $this->application('/app');
        $routes = $app->container()->make(RouteRegistry::class);
        $runs = 0;
        $routes->get('/users', static function () use (&$runs): string {
            ++$runs;
            return 'users';
        });
        Route::path('/')->fallback(static function () use (&$runs): string {
            ++$runs;
            return 'inside fallback';
        });

        self::assertSame('users', $this->handle($app, new Request('GET', '/app/users'))->content());
        self::assertSame('inside fallback',
            $this->handle($app, new Request('GET', '/app/not-found'))->content());
        self::assertSame(2, $runs);
        foreach (['/users', '/application', '/apple', '/app2', '/other/app/users'] as $outside) {
            self::assertSame(404, $this->handle($app, new Request('GET', $outside))->status(), $outside);
            self::assertSame(2, $runs, $outside);
        }
    }

    public function testOptionalConstraintsGroupsHostAndMethodErrorsRemainApplicationRelative(): void
    {
        $app = $this->application('/app');
        $routes = $app->container()->make(RouteRegistry::class);
        Route::group()->prefix('/admin')->routes(static function (): void {
            Route::path('/reports/{year?}')->get(static fn (?string $year = null): string => $year ?? 'all')
                ->where('year', 'integer');
        });
        $routes->get('/dashboard', static fn (): string => 'admin', 'admin.example.test');
        $routes->get('/users', static fn (): string => 'users');

        self::assertSame('all', $this->handle($app, new Request('GET', '/app/admin/reports'))->content());
        self::assertSame('2026', $this->handle($app, new Request('GET', '/app/admin/reports/2026'))->content());
        self::assertSame(404, $this->handle($app, new Request('GET', '/app/admin/reports/nope'))->status());
        self::assertSame('admin', $this->handle($app,
            new Request('GET', '/app/dashboard', server: ['HTTP_HOST' => 'admin.example.test']))->content());
        self::assertSame(404, $this->handle($app,
            new Request('GET', '/app/dashboard', server: ['HTTP_HOST' => 'public.example.test']))->status());
        $wrongMethod = $this->handle($app, new Request('POST', '/app/users'));
        self::assertSame(405, $wrongMethod->status());
        self::assertSame('GET', $wrongMethod->header('Allow'));
    }

    public function testNamedRoutesApplyMountAtGenerationTimeWithoutChangingDeclarations(): void
    {
        $app = $this->application('/app');
        Route::path('/')->get(static fn (): string => 'home')->named('home');
        Route::path('/users/{id}')->get(static fn (): string => 'user')->named('users.show');
        Route::path('/app/raw/{id}')->get(static fn (): string => 'literal')->named('literal.show');
        $routes = $app->container()->make(RouteRegistry::class);

        self::assertSame('/app/', $routes->url('home'));
        self::assertSame('/app/users/42', $routes->url('users.show', ['id' => 42]));
        self::assertSame('/app/users/42', \route('users.show', ['id' => 42]));
        // The declaration is intentionally application-relative even when its
        // first segment happens to equal the configured deployment prefix.
        self::assertSame('/app/app/raw/7', $routes->url('literal.show', ['id' => 7]));
        self::assertSame('/users/42', $this->rootRouteUrl());
    }

    public function testEncodedAttacksNeverReachFallbackOrControllerButUnicodeParameterDoes(): void
    {
        $app = $this->application('/app');
        $routes = $app->container()->make(RouteRegistry::class);
        $runs = 0;
        $routes->get('/admin', static function () use (&$runs): string {
            ++$runs;
            return 'private';
        });
        $routes->get('/files/{name}', static function (string $name) use (&$runs): string {
            ++$runs;
            return $name;
        });
        Route::path('/')->fallback(static function () use (&$runs): string {
            ++$runs;
            return 'fallback';
        });

        foreach (['/app/%2e%2e/admin', '/app/%2Fadmin', '/app/%5cadmin', '/app/%GG'] as $unsafe) {
            self::assertSame(404, $this->handle($app, new Request('GET', $unsafe))->status(), $unsafe);
            self::assertSame(0, $runs, $unsafe);
        }
        self::assertSame('álîçé', $this->handle($app,
            new Request('GET', '/app/files/' . rawurlencode('álîçé')))->content());
        self::assertSame(1, $runs);
    }

    public function testApiAndCorsPreflightUseMountedPathsWithoutRewritingRouteMetadata(): void
    {
        $app = $this->application('/app', cors: true);
        $routes = $app->container()->make(RouteRegistry::class);
        $runs = 0;
        $routes->get('/api/v1/users', static function () use (&$runs): array {
            ++$runs;
            return ['items' => []];
        });
        $headers = [
            'Origin' => 'https://client.example.test',
            'Access-Control-Request-Method' => 'GET',
        ];
        $preflight = $this->handle($app, new Request('OPTIONS', '/app/api/v1/users', headers: $headers));
        self::assertSame(204, $preflight->status());
        self::assertSame('https://client.example.test', $preflight->header('Access-Control-Allow-Origin'));
        self::assertSame(0, $runs);

        $response = $this->handle($app, new Request('GET', '/app/api/v1/users',
            headers: ['Accept' => 'application/json']));
        self::assertSame(200, $response->status());
        self::assertSame(['items' => []], $this->json($response));
        self::assertSame(1, $runs);
        $missing = $this->handle($app, new Request('GET', '/app/api/v1/missing',
            headers: ['Accept' => 'application/json']));
        self::assertSame(404, $missing->status());
        self::assertSame('not_found', $this->json($missing)['error']['code']);
        self::assertSame('/api/v1/users', $routes->all()[0]->uri());
    }

    public function testTrustedForwardedAuthorityComposesWithExplicitMount(): void
    {
        $app = $this->application('/app', proxy: [
            'proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded',
        ]);
        $app->container()->make(RouteRegistry::class)->get('/dashboard',
            static fn (Request $request): array => [
                'host' => $request->host(), 'scheme' => $request->scheme(),
                'path' => $request->path(), 'base' => $request->basePath(),
            ], 'admin.example.test');
        $forwarded = [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Host' => 'admin.example.test',
            'X-Forwarded-Proto' => 'https',
        ];
        $server = ['REMOTE_ADDR' => '10.0.0.8', 'HTTP_HOST' => 'backend.internal:8080'];
        self::assertSame([
            'host' => 'admin.example.test', 'scheme' => 'https',
            'path' => '/dashboard', 'base' => '/app',
        ], $this->json($this->handle($app,
            new Request('GET', '/app/dashboard', headers: $forwarded, server: $server))));
        $server['REMOTE_ADDR'] = '198.51.100.8';
        self::assertSame(404, $this->handle($app,
            new Request('GET', '/app/dashboard', headers: $forwarded, server: $server))->status());
    }

    public function testApplicationAndRequestMountStateNeverLeaks(): void
    {
        $alpha = $this->application('/alpha');
        $beta = $this->application('/beta');
        foreach ([$alpha, $beta] as $app) {
            $app->container()->make(RouteRegistry::class)->get('/same',
                static fn (Request $request): string => $request->basePath() . '|' . $request->path());
        }
        $request = new Request('GET', '/alpha/same');
        self::assertSame('/alpha|/same', $this->handle($alpha, $request)->content());
        self::assertSame(404, $this->handle($beta, $request)->status());
        self::assertSame('/alpha|/same', $this->handle($alpha, $request)->content());
        self::assertSame('/beta|/same', $this->handle($beta, new Request('GET', '/beta/same'))->content());
    }

    public function testExplicitModelBindingUsesStrippedParameter(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for mounted Model binding.');
        }
        $app = $this->application('/app', database: true);
        $database = $app->container()->make(DatabaseManager::class);
        $database->connection()->pdo()->exec('CREATE TABLE base_path_users (id INTEGER PRIMARY KEY, name TEXT)');
        $database->connection()->pdo()->exec("INSERT INTO base_path_users (id, name) VALUES (42, 'Ada')");
        $app->container()->make(RouteRegistry::class)->get('/users/{user}',
            static fn (BasePathBoundUser $user): string => $user->name)
            ->where('user', 'integer')->bind('user', BasePathBoundUser::class);

        $request = new Request('GET', '/app/users/42');
        self::assertSame('Ada', $this->handle($app, $request)->content());
        self::assertSame('42', $request->route('user'));
    }

    /** @param array<string, mixed> $proxy */
    private function application(string $base = '', bool $cors = false, array $proxy = [],
        bool $database = false): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $config = [
            'App' => ['env' => 'testing', 'debug' => false],
            'Http' => ['base_path' => $base],
        ];
        if ($proxy !== []) {
            $config['TrustedProxies'] = $proxy;
        }
        if ($cors) {
            $config['Api'] = [
                'enabled' => true, 'paths' => ['/api'],
                'cors' => [
                    'enabled' => true, 'paths' => ['/api'],
                    'allowed_origins' => ['https://client.example.test'],
                    'allowed_methods' => ['GET'], 'allowed_headers' => [],
                    'exposed_headers' => [], 'allow_credentials' => false, 'max_age' => 600,
                ],
            ];
        }
        if ($database) {
            $config['Database'] = ['default' => 'main', 'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]];
        }
        foreach ($config as $name => $values) {
            $project->write('Config/' . $name . '.php', '<?php return ' . var_export($values, true) . ';');
        }
        $app = new Application($project->path());
        foreach ([DatabaseServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }

    private function rootRouteUrl(): string
    {
        $root = $this->application();
        $root->container()->make(RouteRegistry::class)->get('/users/{id}',
            static fn (): string => 'user')->named('users.show');
        return $root->container()->make(RouteRegistry::class)->url('users.show', ['id' => 42]);
    }

    private function handle(Application $app, Request $request): Response
    {
        return $app->container()->make(Kernel::class)->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }
}

/**
 * Real ORM identity fixture for mounted route binding.
 *
 * @property string $name
 */
final class BasePathBoundUser extends Model
{
    protected string $table = 'base_path_users';
}
