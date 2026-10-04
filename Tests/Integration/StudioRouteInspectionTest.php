<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseManager;
use App\Database\Model;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Routing\RouteCache;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Studio\StudioInspector;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Route inspection uses registered definitions, including non-cacheable handlers. */
final class StudioRouteInspectionTest extends TestCase
{
    public function testBareRegistryInspectionKeepsRoutesWithoutProvenance(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/', static function (): void {})->named('welcome.page');

        $rows = $registry->inspection();
        self::assertCount(1, $rows);
        self::assertSame('welcome.page', $rows[0]['name']);
        self::assertSame('Closure', $rows[0]['handler']);
        self::assertNull($rows[0]['owner']);
        self::assertNull($rows[0]['source']);
    }

    public function testRegisteredRoutesExposeSafeMetadataWithoutDispatchOrBinding(): void
    {
        $project = TestApplication::temporary();
        StudioRouteProbe::$handlerCalls = 0;
        StudioRouteProbe::$controllerConstructions = 0;
        try {
            $project->write('Project/Routes/Web.php', <<<'PHP'
<?php
use App\Plugins\Route;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;

Route::path('/')->get(static function (): void {
    \SqueHub\Tests\Integration\StudioRouteProbe::$handlerCalls++;
})->named('welcome.page');
Route::path('/dashboard')
    ->get([\SqueHub\Tests\Integration\StudioRouteProbe::class, 'show'])
    ->named('dashboard')->through(['auth', 'verified']);
Route::path('/accounts/{account}/{section?}')->host('{tenant}.example.test')
    ->get([\SqueHub\Tests\Integration\StudioRouteProbe::class, 'show'])
    ->named('accounts.show')->where('tenant', 'slug')->where('account', 'integer')
    ->bind('account', \SqueHub\Tests\Integration\StudioRouteProbeModel::class, 'id');
Route::path('/api')->fallback(static function (): void {
    \SqueHub\Tests\Integration\StudioRouteProbe::$handlerCalls++;
})->named('api.fallback');
Route::path('/api/status')->get([\SqueHub\Tests\Integration\StudioRouteProbe::class, 'show'])
    ->named('api.status')->contract((new OperationContract('api.status'))
        ->response(200, Schema::string()));
PHP);
            $app = $project->application();
            $database = $app->container()->make(DatabaseManager::class);
            self::assertFalse($database->connection()->isConnected());

            // No route cache is present. Studio may register route definitions,
            // but it must not execute any action, middleware, or Model lookup.
            $routes = (new StudioInspector($app))->routes();
            self::assertSame('observed', $routes['state']);
            self::assertCount(5, $routes['items']);
            $byName = array_column($routes['items'], null, 'name');

            self::assertSame(['GET'], $byName['welcome.page']['methods']);
            self::assertSame('/', $byName['welcome.page']['path']);
            self::assertSame('Closure', $byName['welcome.page']['handler']);
            self::assertSame('Closure', $byName['welcome.page']['handler_type']);
            self::assertFalse($byName['welcome.page']['contract']);

            self::assertSame(StudioRouteProbe::class . '@show', $byName['dashboard']['handler']);
            self::assertSame(['auth', 'verified'], $byName['dashboard']['middleware']);
            self::assertSame('{tenant}.example.test', $byName['accounts.show']['host']);
            self::assertSame(['tenant' => 'slug', 'account' => 'integer'],
                $byName['accounts.show']['constraints']);
            self::assertSame(['section'], $byName['accounts.show']['optional_parameters']);
            self::assertSame([['parameter' => 'account', 'model' => StudioRouteProbeModel::class,
                'key' => 'id']], $byName['accounts.show']['model_bindings']);
            self::assertSame(['*'], $byName['api.fallback']['methods']);
            self::assertTrue($byName['api.fallback']['fallback']);
            self::assertFalse($byName['dashboard']['contract']);
            self::assertTrue($byName['api.status']['contract']);

            self::assertSame(0, StudioRouteProbe::$handlerCalls);
            self::assertSame(0, StudioRouteProbe::$controllerConstructions);
            self::assertFalse($database->connection()->isConnected());
        } finally {
            $project->cleanup();
        }
    }

    public function testDeclarativeRouteCacheDoesNotGateInspection(): void
    {
        foreach ([false, true] as $cached) {
            $project = TestApplication::temporary();
            try {
                $project->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Plugins\Route::path('/dashboard')
    ->get(['Project\\Controllers\\DashboardController', 'show'])->named('dashboard');
PHP);
                $app = $project->application();
                if ($cached) {
                    self::assertSame(1, (new RouteCache($app))->build()['routes']);
                    $app = new Application($project->root());
                    $app->register(RoutingServiceProvider::class);
                    $app->bootstrap();
                }
                $routes = (new StudioInspector($app))->routes();
                self::assertSame('observed', $routes['state']);
                self::assertSame(['dashboard'], array_column($routes['items'], 'name'));
                self::assertSame(['/dashboard'], array_column($routes['items'], 'path'));
            } finally {
                $project->cleanup();
            }
        }
    }

    public function testEnabledPackageRouteHasOwnerAndDisabledPackageRouteIsNotLoaded(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Packages/Enabled/Enabled.php',
                '<?php namespace Packages\\Enabled; final class Enabled extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/Enabled/Routes/Web.php', <<<'PHP'
<?php
\App\Plugins\Route::path('/enabled')
    ->get(['Project\\Controllers\\EnabledController', 'show'])->named('enabled');
PHP);
            $project->write('Project/Packages/Disabled/Disabled.php',
                '<?php namespace Packages\\Disabled; final class Disabled extends \\App\\Plugins\\ServiceProvider {}');
            $disabledMarker = $project->path('disabled-route-executed.txt');
            $project->write('Project/Packages/Disabled/Routes/Web.php',
                '<?php file_put_contents(' . var_export($disabledMarker, true) . ', "executed");'
                . ' \\App\\Plugins\\Route::path("/disabled")->get('
                . '"Project\\\\Controllers\\\\DisabledController@show");');
            $manager = new PackageManager(new Application($project->root()));
            $manager->apply($manager->planEnable('Enabled'));

            $routes = (new StudioInspector($project->application()))->routes();
            self::assertSame(['enabled'], array_column($routes['items'], 'name'));
            self::assertSame(['type' => 'package', 'name' => 'Enabled'],
                $routes['items'][0]['owner']);
            self::assertFileDoesNotExist($disabledMarker);
        } finally {
            $project->cleanup();
        }
    }

    public function testRouteInspectionRemainsApplicationScoped(): void
    {
        $first = TestApplication::temporary();
        $second = TestApplication::temporary();
        try {
            $first->write('Project/Routes/Web.php', <<<'PHP'
<?php \App\Plugins\Route::path('/first')->get('Project\\Controllers\\First@show')->named('first');
PHP);
            $second->write('Project/Routes/Web.php', <<<'PHP'
<?php \App\Plugins\Route::path('/second')->get('Project\\Controllers\\Second@show')->named('second');
PHP);
            $firstInspector = new StudioInspector($first->application());
            $secondInspector = new StudioInspector($second->application());
            self::assertSame(['first'], array_column($firstInspector->routes()['items'], 'name'));
            self::assertSame(['second'], array_column($secondInspector->routes()['items'], 'name'));
            self::assertSame(['first'], array_column($firstInspector->routes()['items'], 'name'));
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }
}

/** Side-effect probes must remain untouched during route inspection. */
final class StudioRouteProbe
{
    public static int $handlerCalls = 0;
    public static int $controllerConstructions = 0;

    public function __construct()
    {
        self::$controllerConstructions++;
    }

    public function show(): void
    {
        self::$handlerCalls++;
    }
}

/** Binding metadata is declarative; inspection must not query this Model. */
final class StudioRouteProbeModel extends Model
{
    protected string $table = 'studio_route_probe';
}
