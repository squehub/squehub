<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Packages\PackageManager;
use App\Plugins\Route;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises route declarations through the real matcher, middleware, and HTTP kernel. */
final class RouteParameterDepthTest extends TestCase
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
        \App\Routing\Route::setResolver(null);
        foreach ($this->projects as $project) {
            $project->remove();
        }
    }

    public function testOneTrailingOptionalParameterIsAbsentOrAString(): void
    {
        Route::path('/reports/{year?}')->get(static fn (Request $request, ?string $year = null): array => [
            'year' => $year,
            'route' => $request->route(),
            'present' => array_key_exists('year', $request->route()),
        ]);

        $absentRequest = new Request('GET', '/reports');
        $absent = $this->handle($absentRequest);
        self::assertSame(200, $absent->status(), $absent->content());
        self::assertSame(['year' => null, 'route' => [], 'present' => false], $this->decode($absent));
        self::assertSame([], $absentRequest->route());

        $presentRequest = new Request('GET', '/reports/2026');
        $present = $this->handle($presentRequest);
        self::assertSame(200, $present->status(), $present->content());
        self::assertSame(['year' => '2026', 'route' => ['year' => '2026'], 'present' => true],
            $this->decode($present));
        self::assertSame('2026', $presentRequest->route('year'));
        self::assertSame('/reports', (new Request('GET', '/reports/'))->path());
    }

    public function testOptionalSegmentsMustRemainAtTheEndOfThePath(): void
    {
        foreach (['/reports/{year?}/summary', '/{language?}/posts/{id}'] as $path) {
            $this->assertDeclarationFails(static fn () => Route::path($path)
                ->get(static fn (): string => 'unreachable'));
        }
    }

    public function testAdjacentTrailingOptionalsAndNamedRelativeUrls(): void
    {
        Route::path('/reports/{year?}/{month?}')
            ->get(static fn (?string $year = null, ?string $month = null): array => [
                'year' => $year, 'month' => $month,
            ])
            ->where('year', 'integer')
            ->where('month', 'integer')
            ->named('reports.show');
        $routes = $this->app->container()->make(RouteRegistry::class);

        self::assertSame('/reports', $routes->url('reports.show'));
        self::assertSame('/reports/2026', $routes->url('reports.show', ['year' => 2026]));
        self::assertSame('/reports/2026/9', $routes->url('reports.show', ['year' => 2026, 'month' => 9]));
        self::assertSame(['year' => null, 'month' => null],
            $this->decode($this->handle(new Request('GET', '/reports'))));
        self::assertSame(['year' => '2026', 'month' => null],
            $this->decode($this->handle(new Request('GET', '/reports/2026'))));
        self::assertSame(['year' => '2026', 'month' => '9'],
            $this->decode($this->handle(new Request('GET', '/reports/2026/9'))));
        self::assertSame(404, $this->handle(new Request('GET', '/reports/2026/sept'))->status());
        $this->assertDeclarationFails(static fn () => $routes->url('reports.show', ['month' => 9]));
        $this->assertDeclarationFails(static fn () => $routes->url('reports.show', ['year' => 'not-a-year']));
    }

    public function testRequiredParameterAndGroupPrefixRetainTheirCurrentBehavior(): void
    {
        Route::group()->prefix('/admin')->routes(static function (): void {
            Route::path('/reports/{year?}')->get(static fn (?string $year = null): string => $year ?? 'all');
        });
        Route::path('/required/{id}')->get(static fn (string $id): string => $id);

        self::assertSame('all', $this->handle(new Request('GET', '/admin/reports'))->content());
        self::assertSame('2026', $this->handle(new Request('GET', '/admin/reports/2026'))->content());
        self::assertSame(404, $this->handle(new Request('GET', '/required'))->status());
        self::assertSame('42', $this->handle(new Request('GET', '/required/42'))->content());
    }

    public function testConstraintMismatchFallsThroughAndNeverBecomesValidationError(): void
    {
        Route::path('/users/{id}')->get(static fn (string $id): string => 'numeric:' . $id)
            ->where('id', 'integer');
        Route::path('/users/{slug}')->get(static fn (string $slug): string => 'slug:' . $slug);

        self::assertSame('numeric:42', $this->handle(new Request('GET', '/users/42'))->content());
        self::assertSame('slug:ada', $this->handle(new Request('GET', '/users/ada'))->content());
        self::assertSame(404, $this->handle(new Request('GET', '/users/a%2Fb'))->status());
    }

    public function testCustomRegexConstraintIsAnchoredAndInvalidDeclarationsFailEarly(): void
    {
        Route::path('/years/{year}')->get(static fn (string $year): string => $year)
            ->where('year', '[0-9]{4}');

        self::assertSame('2026', $this->handle(new Request('GET', '/years/2026'))->content());
        self::assertSame(404, $this->handle(new Request('GET', '/years/20260'))->status());
        self::assertSame(404, $this->handle(new Request('GET', '/years/a2026'))->status());

        $route = Route::path('/items/{id}')->get(static fn (): string => 'item');
        $this->assertDeclarationFails(static fn () => $route->where('missing', 'integer'));
        $this->assertDeclarationFails(static fn () => $route->where('id', '['));
    }

    public function testStaticAndConstrainedRoutesTakePredictablePrecedence(): void
    {
        Route::path('/users/{slug}')->get(static fn (): string => 'broad');
        Route::path('/users/{id}')->get(static fn (): string => 'numeric')->where('id', 'integer');
        Route::path('/users/create')->get(static fn (): string => 'static');

        self::assertSame('static', $this->handle(new Request('GET', '/users/create'))->content());
        self::assertSame('numeric', $this->handle(new Request('GET', '/users/42'))->content());
        self::assertSame('broad', $this->handle(new Request('GET', '/users/ada'))->content());
    }

    public function testExactHostMatchingIgnoresDnsCaseAndIncomingPort(): void
    {
        Route::path('/dashboard')->host('admin.example.com')->get(static fn (): string => 'admin');

        self::assertSame('admin', $this->handle(new Request('GET', '/dashboard',
            server: ['HTTP_HOST' => 'ADMIN.Example.Com:8443']))->content());
        self::assertSame(404, $this->handle(new Request('GET', '/dashboard',
            server: ['HTTP_HOST' => 'www.example.com']))->status());
    }

    public function testDynamicHostAndPathParametersRemainSeparateRawRequestValues(): void
    {
        Route::path('/users/{id}')->host('{tenant}.example.com')
            ->get(static fn (Request $request, string $tenant, string $id): array => [
                'tenant' => $tenant, 'id' => $id, 'route' => $request->route(),
            ]);

        $request = new Request('GET', '/users/42', server: ['HTTP_HOST' => 'Acme.Example.Com:8000']);
        $response = $this->handle($request);
        self::assertSame(200, $response->status(), $response->content());
        self::assertSame(['tenant' => 'acme', 'id' => '42',
            'route' => ['tenant' => 'acme', 'id' => '42']], $this->decode($response));
        self::assertSame(['tenant' => 'acme', 'id' => '42'], $request->route());
        self::assertSame(404, $this->handle(new Request('GET', '/users/42',
            server: ['HTTP_HOST' => 'foo.bar.example.com']))->status());
    }

    public function testHostAndPathConstraintsComposeWithoutChangingCapturedStrings(): void
    {
        Route::path('/items/{id}')->host('{tenant}.example.com')
            ->get(static fn (Request $request): array => $request->route())
            ->where('tenant', 'slug')
            ->where('id', 'integer');

        self::assertSame(['tenant' => 'team-one', 'id' => '42'],
            $this->decode($this->handle(new Request('GET', '/items/42',
                server: ['HTTP_HOST' => 'Team-One.Example.Com']))));
        self::assertSame(404, $this->handle(new Request('GET', '/items/42',
            server: ['HTTP_HOST' => 'team_one.example.com']))->status());
        self::assertSame(404, $this->handle(new Request('GET', '/items/abc',
            server: ['HTTP_HOST' => 'team-one.example.com']))->status());
    }

    public function testConstrainedDynamicHostWinsOverEarlierBroadHostAndCanFallThrough(): void
    {
        Route::path('/dashboard')->host('{label}.example.com')
            ->get(static fn (string $label): string => 'broad:' . $label);
        Route::path('/dashboard')->host('{tenant}.example.com')
            ->get(static fn (string $tenant): string => 'constrained:' . $tenant)
            ->where('tenant', 'alpha');

        self::assertSame('constrained:acme', $this->handle(new Request('GET', '/dashboard',
            server: ['HTTP_HOST' => 'ACME.example.com']))->content());
        self::assertSame('broad:team-one', $this->handle(new Request('GET', '/dashboard',
            server: ['HTTP_HOST' => 'team-one.example.com']))->content());
    }

    public function testHostAndPathCannotDeclareTheSameParameterName(): void
    {
        $this->assertDeclarationFails(static fn () => Route::path('/users/{tenant}')
            ->host('{tenant}.example.com')->get(static fn (): string => 'unreachable'));
    }

    public function testUnsafeHostPatternsConflictingGroupsAndDuplicateFallbacksFailAtDeclaration(): void
    {
        foreach (['*.example.com', 'example.com/path', 'example.com:8080', '{Tenant}.example.com'] as $host) {
            $this->assertDeclarationFails(static fn () => Route::path('/safe')->host($host)
                ->get(static fn (): string => 'unreachable'));
        }
        Route::path('/safe')->host('api.example.com')->get(static fn (): string => 'first');
        $this->assertDeclarationFails(static fn () => Route::path('/safe')->host('API.example.com')
            ->get(static fn (): string => 'duplicate'));
        Route::path('/missing')->fallback(static fn (): string => 'first');
        $this->assertDeclarationFails(static fn () => Route::path('/missing')
            ->fallback(static fn (): string => 'duplicate'));
        $this->assertDeclarationFails(static fn () => Route::group()->host('admin.example.com')
            ->routes(static function (): void {
                Route::path('/safe')->host('public.example.com')->get(static fn (): string => 'conflict');
            }));
    }

    public function testHostAndOrdinaryRoutesCanShareAPathWithoutLeakingHostPolicy(): void
    {
        Route::path('/')->get(static fn (): string => 'ordinary');
        Route::path('/')->host('admin.example.com')->get(static fn (): string => 'admin');
        Route::path('/')->host('api.example.com')->get(static fn (): string => 'api');

        self::assertSame('admin', $this->handle(new Request('GET', '/',
            server: ['HTTP_HOST' => 'admin.example.com']))->content());
        self::assertSame('api', $this->handle(new Request('GET', '/',
            server: ['HTTP_HOST' => 'api.example.com']))->content());
        self::assertSame('ordinary', $this->handle(new Request('GET', '/',
            server: ['HTTP_HOST' => 'other.example.com']))->content());
    }

    public function testHostRoutingDoesNotTrustForwardedHostHeaders(): void
    {
        Route::path('/admin')->host('admin.example.com')->get(static fn (): string => 'private');

        $response = $this->handle(new Request('GET', '/admin',
            headers: ['X-Forwarded-Host' => 'admin.example.com'],
            server: ['HTTP_HOST' => 'public.example.com']));
        self::assertSame(404, $response->status());
        self::assertStringNotContainsString('private', $response->content());
    }

    public function testMalformedIncomingHostsCannotMatchHostRestrictedRoutes(): void
    {
        Route::path('/admin')->host('admin.example.com')->get(static fn (): string => 'private');
        foreach (['admin.example.com:0', 'admin.example.com:99999', 'admin.example.com evil',
            'admin.example.com/path', 'admin.example.com?query',
            "admin.example.com\r\nX-Other: injected", 'admin..example.com'] as $host) {
            $response = $this->handle(new Request('GET', '/admin', server: ['HTTP_HOST' => $host]));
            self::assertSame(404, $response->status());
            self::assertStringNotContainsString('private', $response->content());
        }
    }

    public function testBracketedIpv6AndLocalhostAreUsableOnDevelopmentHosts(): void
    {
        Route::path('/ready')->host('[::1]')->get(static fn (): string => 'ipv6');
        Route::path('/ready')->host('localhost')->get(static fn (): string => 'local');
        Route::path('/ready')->host('127.0.0.1')->get(static fn (): string => 'ipv4');

        self::assertSame('ipv6', $this->handle(new Request('GET', '/ready',
            server: ['HTTP_HOST' => '[::1]:8001']))->content());
        self::assertSame('local', $this->handle(new Request('GET', '/ready',
            server: ['HTTP_HOST' => 'LOCALHOST:8001']))->content());
        self::assertSame('ipv4', $this->handle(new Request('GET', '/ready',
            server: ['HTTP_HOST' => '127.0.0.1:8001']))->content());
    }

    public function testGroupHostAppliesToItsRoutes(): void
    {
        Route::group()->prefix('/admin')->host('admin.example.com')->routes(static function (): void {
            Route::path('/panel')->get(static fn (): string => 'panel');
        });

        self::assertSame('panel', $this->handle(new Request('GET', '/admin/panel',
            server: ['HTTP_HOST' => 'admin.example.com']))->content());
        self::assertSame(404, $this->handle(new Request('GET', '/admin/panel',
            server: ['HTTP_HOST' => 'public.example.com']))->status());
    }

    public function testNormalRouteWinsOverExplicitFallbackAndFallbackUsesNormalMiddleware(): void
    {
        $trace = [];
        Route::path('/help')->fallback(static function (Request $request) use (&$trace): Response {
            $trace[] = 'fallback:' . $request->path();
            return new Response('custom missing', 418);
        })->through(new DepthFallbackMiddleware($trace));
        Route::path('/help/known')->get(static function () use (&$trace): string {
            $trace[] = 'normal';
            return 'known';
        });

        self::assertSame('known', $this->handle(new Request('GET', '/help/known'))->content());
        self::assertSame(['normal'], $trace);
        $fallback = $this->handle(new Request('GET', '/help/unknown'));
        self::assertSame(418, $fallback->status());
        self::assertSame('custom missing', $fallback->content());
        self::assertSame(['normal', 'middleware', 'fallback:/help/unknown'], $trace);
        self::assertSame(404, $this->handle(new Request('GET', '/elsewhere'))->status());
    }

    public function testFallbackDoesNotEraseMethodNotAllowedOrRootRoute(): void
    {
        Route::path('/')->get(static fn (): string => 'root');
        Route::path('/help')->fallback(static fn (): string => 'fallback');
        Route::path('/help/known')->get(static fn (): string => 'known');

        self::assertSame('root', $this->handle(new Request('GET', '/'))->content());
        $wrongMethod = $this->handle(new Request('POST', '/help/known'));
        self::assertSame(405, $wrongMethod->status());
        self::assertSame('GET', $wrongMethod->header('Allow'));
        self::assertSame('fallback', $this->handle(new Request('GET', '/help/missing'))->content());
    }

    public function testHostRestrictedFallbackWinsOnlyWithinItsHostAndPrefix(): void
    {
        Route::path('/')->fallback(static fn (): string => 'global fallback');
        Route::path('/admin')->host('admin.example.com')
            ->fallback(static fn (): string => 'admin fallback');
        Route::path('/admin/known')->get(static fn (): string => 'normal route');

        self::assertSame('normal route', $this->handle(new Request('GET', '/admin/known',
            server: ['HTTP_HOST' => 'admin.example.com']))->content());
        self::assertSame('admin fallback', $this->handle(new Request('GET', '/admin/missing',
            server: ['HTTP_HOST' => 'admin.example.com']))->content());
        self::assertSame('global fallback', $this->handle(new Request('GET', '/admin/missing',
            server: ['HTTP_HOST' => 'public.example.com']))->content());
    }

    public function testEncodedSeparatorsMalformedEscapesAndOversizedValuesNeverReachController(): void
    {
        $executions = 0;
        Route::path('/files/{name}')->get(static function (string $name) use (&$executions): string {
            $executions++;
            return $name;
        });
        foreach (['/files/a%2Fb', '/files/a//b', '/files/%2E%2E', '/files/%00', '/files/%GG',
            '/files/' . str_repeat('x', 8193)] as $path) {
            self::assertSame(404, $this->handle(new Request('GET', $path))->status());
        }
        self::assertSame(0, $executions);
        self::assertSame('álîçé', $this->handle(new Request('GET', '/files/' . rawurlencode('álîçé')))->content());
        self::assertSame(1, $executions);
    }

    public function testUnmatchedApiRequestKeepsExistingJson404Envelope(): void
    {
        Route::path('/help')->fallback(static fn (): string => 'browser only');

        $response = $this->handle(new Request('GET', '/api/missing',
            headers: ['Accept' => 'application/json']));
        self::assertSame(404, $response->status());
        self::assertSame('not_found', $this->decode($response)['error']['code']);
        self::assertSame($response->header('X-Request-ID'), $this->decode($response)['request_id']);
        self::assertStringNotContainsString('browser only', $response->content());
    }

    public function testConstraintMismatchSkipsExplicitModelBindingLookup(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for route binding integration.');
        }
        $app = $this->application(true);
        $database = $app->container()->make(DatabaseManager::class);
        $database->connection()->pdo()->exec(
            'CREATE TABLE depth_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, deleted_at TEXT NULL)'
        );
        $database->connection()->pdo()->exec("INSERT INTO depth_users (name) VALUES ('Ada')");
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/users/{user}', static fn (DepthUser $user): string => $user->name)
            ->where('user', 'integer')->bind('user', DepthUser::class);
        $diagnostics = $app->container()->make(Diagnostics::class);

        self::assertSame(404, $this->handle(new Request('GET', '/users/not-an-id'), $app)->status());
        self::assertSame(0, $diagnostics->queryCount());
        self::assertSame('Ada', $this->handle(new Request('GET', '/users/1'), $app)->content());
        self::assertSame(1, $diagnostics->queryCount());
    }

    public function testRegistrationAndStaticContractInspectionHaveNoRequestEffects(): void
    {
        $executions = 0;
        Route::path('/api/users/{id}')
            ->get(static function () use (&$executions): string {
                $executions++;
                return 'executed';
            })
            ->where('id', 'integer')
            ->named('api.users.show')
            ->contract((new OperationContract())->path('id', Schema::integer())
                ->response(200, Schema::string()));
        Route::path('/api')->fallback(static fn (): string => 'fallback');

        $routes = $this->app->container()->make(RouteRegistry::class);
        self::assertCount(2, $routes->all());
        self::assertSame(['id' => '42'], (new RouteMatcher())->match($routes,
            new Request('GET', '/api/users/42'))->parameters);
        $contract = $this->app->container()->make(ContractManager::class)->openApi();
        self::assertArrayHasKey('/api/users/{id}', $contract['paths']);
        self::assertSame(0, $executions);
    }

    public function testUnrepresentableOptionalHostAndFallbackContractsAreRejected(): void
    {
        $optional = Route::path('/api/reports/{year?}')
            ->get(static fn (): string => 'reports');
        $host = Route::path('/api/hosted')->host('api.example.com')
            ->get(static fn (): string => 'hosted');
        $fallback = Route::path('/api')->fallback(static fn (): string => 'fallback');

        foreach ([$optional, $host, $fallback] as $route) {
            $this->assertDeclarationFails(static fn () => $route->contract(
                (new OperationContract())->response(200, Schema::string())
            ));
        }
        self::assertSame([], $this->app->container()->make(ContractManager::class)
            ->application()['operations']);
    }

    public function testApplicationInstancesKeepIndependentHostsConstraintsAndFallbacks(): void
    {
        Route::path('/users/{id}')->host('first.example.com')->get(static fn (): string => 'first')
            ->where('id', 'integer');
        Route::path('/')->fallback(static fn (): string => 'first fallback');
        $second = $this->application();
        $secondRoutes = $second->container()->make(RouteRegistry::class);
        $secondRoutes->get('/users/{id}', static fn (): string => 'second');

        self::assertSame('second', $this->handle(new Request('GET', '/users/ada',
            server: ['HTTP_HOST' => 'second.example.com']), $second)->content());
        self::assertSame(404, $this->handle(new Request('GET', '/other'), $second)->status());
        self::assertSame('first', $this->handle(new Request('GET', '/users/42',
            server: ['HTTP_HOST' => 'first.example.com']))->content());
        self::assertSame('first fallback', $this->handle(new Request('GET', '/other'))->content());
    }

    public function testEnabledPackageRouteUsesTheSameMatcherAndKeepsProvenance(): void
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        $project->write('Project/Packages/DepthPackage/DepthPackage.php',
            '<?php namespace Project\\Packages\\DepthPackage; final class DepthPackage extends \\App\\Plugins\\ServiceProvider {}');
        $project->write('Project/Packages/DepthPackage/Routes/Web.php', <<<'PHP'
<?php
\App\Plugins\Route::path('/package/{id}')
    ->host('package.example.com')
    ->get(static fn (string $id): string => 'package:' . $id)
    ->where('id', 'integer');
PHP);
        $manager = new PackageManager(new Application($project->path()));
        $manager->apply($manager->planEnable('DepthPackage'));
        $app = $this->boot($project, false);
        $squehubApp = $app;
        require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

        self::assertSame('package:42', $this->handle(new Request('GET', '/package/42',
            server: ['HTTP_HOST' => 'package.example.com']), $app)->content());
        self::assertSame(404, $this->handle(new Request('GET', '/package/ada',
            server: ['HTTP_HOST' => 'package.example.com']), $app)->status());
        self::assertSame('package:DepthPackage',
            $app->contributions()->ownerOf('route', 'GET /package/{id}#package.example.com')?->key());
        $contributions = $app->contributions()->byType('route');
        self::assertCount(1, $contributions);
        self::assertSame('package.example.com', $contributions[0]->metadata['host'] ?? null);
    }

    private function application(bool $database = false): Application
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        return $this->boot($project, $database);
    }

    private function boot(TemporaryProject $project, bool $database): Application
    {
        $project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $project->write('Config/Api.php', '<?php return ["enabled" => true, "paths" => ["/api"]];');
        if ($database) {
            $project->write('Config/Database.php', '<?php return ["default" => "main", '
                . '"connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
            $project->write('Config/Diagnostics.php', '<?php return ["database" => true];');
        }
        $app = new Application($project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class,
            ContractServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        return $app;
    }

    private function handle(Request $request, ?Application $app = null): Response
    {
        return ($app ?? $this->app)->container()->make(Kernel::class)->handle($request);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertDeclarationFails(Closure $declare): void
    {
        $caught = null;
        try {
            $declare();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(Throwable::class, $caught,
            'An invalid route declaration was accepted.');
        self::assertNotSame('', $caught->getMessage());
    }
}

/** Keeps the fallback middleware assertion independent of production middleware aliases. */
final class DepthFallbackMiddleware
{
    /** @param list<string> $trace */
    public function __construct(private array &$trace)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace[] = 'middleware';
        return $next($request);
    }
}

/** @property string $name */
final class DepthUser extends Model
{
    protected string $table = 'depth_users';
    protected bool $softDeletes = true;
}
