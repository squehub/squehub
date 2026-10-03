<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Routing\RouteCache;
use App\Routing\RoutingServiceProvider;
use App\Foundation\Application;
use App\Studio\StudioInspector;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Studio reads registered route metadata without dispatching handlers. */
final class StudioInspectorIntegrationTest extends TestCase
{
    public function testCurrentCacheProvidesBoundedRouteAndContractMetadata(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php', <<<'PHP'
<?php
use App\Plugins\Route;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
Route::path('/users/{id}')->get(['Project\\Controllers\\UserController', 'show'])
    ->named('users.show')->through('auth')
    ->contract((new OperationContract('users.show'))
        ->response(200, Schema::string()));
Route::path('/health')->get(['Project\\Controllers\\HealthController', 'show'])
    ->named('health.show');
PHP);
            $builder = $project->application();
            self::assertSame(2, (new RouteCache($builder))->build()['routes']);
            $app = $this->freshApplication($project->root());
            $inspector = new StudioInspector($app);
            $routes = $inspector->routes(1);
            self::assertSame('observed', $routes['state']);
            self::assertCount(1, $routes['items']);
            self::assertTrue($routes['truncated']);
            self::assertSame('users.show', $routes['items'][0]['name']);
            self::assertSame(['auth'], $routes['items'][0]['middleware']);
            self::assertSame('Project\\Controllers\\UserController@show',
                $routes['items'][0]['handler']);
            self::assertTrue($routes['items'][0]['contract']);
            $contract = $inspector->contract();
            self::assertSame('observed', $contract['state']);
            self::assertCount(1, $contract['items']);
            self::assertSame('/users/{id}', $contract['items'][0]['path']);
        } finally {
            $project->cleanup();
        }
    }

    public function testStaleRouteCacheFallsBackToCurrentRegisteredRoutes(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/old')->get('Project\\\\Controllers\\\\Home@show');");
            $builder = $project->application();
            (new RouteCache($builder))->build();
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/current')->get('Project\\\\Controllers\\\\Home@show')->named('current');");
            $app = $this->freshApplication($project->root());
            $inspector = new StudioInspector($app);
            self::assertSame('observed', $inspector->routes()['state']);
            self::assertSame(['/current'], array_column($inspector->routes()['items'], 'path'));
            self::assertSame('observed', $inspector->contract()['state']);
            self::assertSame('stale', $inspector->overview()['route_cache']['state']);
        } finally {
            $project->cleanup();
        }
    }

    public function testActivationInspectionReadsPackageAndKitMetadataWithoutTheirPhp(): void
    {
        $project = TestApplication::temporary();
        try {
            $marker = $project->path('executed.txt');
            $project->write('Project/Packages/Sample/Sample.php',
                '<?php namespace Packages\\Sample; file_put_contents('
                . var_export($marker, true) . ', "package"); '
                . 'final class Sample extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Kits/Demo/kit.json',
                '{"format":1,"name":"Demo","version":"1.0.0"}');
            $project->write('Project/Kits/Demo/Demo.php',
                '<?php namespace Project\\Kits\\Demo; file_put_contents('
                . var_export($marker, true) . ', "kit"); '
                . 'final class Demo extends \\App\\Plugins\\Kit {}');
            $inspector = new StudioInspector($project->application());
            $activation = $inspector->activation();
            self::assertSame('observed', $activation['state']);
            self::assertSame('Sample', $activation['packages']['items'][0]['name']);
            self::assertSame('disabled', $activation['packages']['items'][0]['status']);
            self::assertSame('Demo', $activation['kits']['items'][0]['name']);
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->cleanup();
        }
    }

    public function testFrontendOverviewReportsOnlySafeSelectedBuildMetadata(): void
    {
        $project = TestApplication::temporary(['frontend' => [
            'adapter' => 'vite', 'development' => ['enabled' => true],
            'build' => ['directory' => 'public/assets/build',
                'manifest' => '.vite/manifest.json'],
        ]]);
        try {
            $inspector = new StudioInspector($project->application());
            self::assertSame(['adapter' => 'vite', 'development' => 'configured',
                'manifest' => 'unavailable'], $inspector->overview()['frontend']);
            $project->write('public/assets/build/assets/app.123.js', 'export default 1;');
            $project->write('public/assets/build/.vite/manifest.json', json_encode([
                'src/main.js' => ['file' => 'assets/app.123.js', 'isEntry' => true],
            ], JSON_THROW_ON_ERROR));
            self::assertSame('valid', $inspector->overview()['frontend']['manifest']);
            self::assertArrayNotHasKey('url', $inspector->overview()['frontend']);
        } finally {
            $project->cleanup();
        }
    }

    public function testPlainApplicationFrontendOverviewNeedsNoBuildOrNode(): void
    {
        $project = TestApplication::temporary();
        try {
            self::assertSame(['adapter' => 'none', 'development' => 'not_required',
                'manifest' => 'not_required'],
                (new StudioInspector($project->application()))->overview()['frontend']);
        } finally {
            $project->cleanup();
        }
    }

    /** A route-cache build has already populated its own registry. Reboot to test replay/fallback. */
    private function freshApplication(string $root): Application
    {
        $app = new Application($root);
        $app->register(RoutingServiceProvider::class);
        $app->bootstrap();
        return $app;
    }
}
