<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Routing\RouteCache;
use App\Routing\RouteCacheException;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** A route artifact must replay the same declarations without executing route PHP. */
final class RouteCacheTest extends TestCase
{
    public function testCacheReplaysNamedGroupedConstrainedAndContractRoutes(): void
    {
        $project = TestApplication::temporary(['http' => ['base_path' => '/app']]);
        try {
            $project->write('Project/Routes/Web.php', <<<'PHP'
<?php
use App\Plugins\Route;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
Route::group()->prefix('/api')->through('auth')->routes(static function (): void {
    Route::path('/users/{id}')->get(['Project\\Controllers\\Users', 'show'])
        ->named('users.show')->where('id', 'integer')
        ->contract((new OperationContract('users.show'))
            ->summary('Show a user')->path('id', Schema::integer())
            ->response(200, Schema::string()));
});
Route::path('/health')->host('health.example.test')
    ->get(['Project\\Controllers\\Health', 'show'])->named('health.show');
PHP);
            $builder = new RouteCache($project->application());
            self::assertSame(['files' => 1, 'routes' => 2, 'reused' => false], $builder->build());
            self::assertSame([], glob($project->path('Storage/Cache/Framework') . '/.Routes-source-*.php'));
            self::assertSame('active', $builder->status()['state']);
            self::assertCount(2, $builder->inspectCachedRoutes());

            $app = $this->freshApplication($project->root());
            $cache = new RouteCache($app);
            $cache->loadOrRequire();
            $routes = $app->container()->make(RouteRegistry::class);
            self::assertCount(2, $routes->all());
            self::assertSame('/app/api/users/42', $routes->url('users.show', ['id' => 42]));
            self::assertSame(['auth'], $routes->namedRoute('users.show')->middlewares());
            self::assertSame('integer', $routes->namedRoute('users.show')->pattern()->rawConstraints()['id']);
            self::assertSame('users.show', $routes->namedRoute('users.show')->contractValue()->operationId());
            self::assertSame('Show a user', $routes->namedRoute('users.show')->contractValue()->toArray()['summary']);
            self::assertSame('health.example.test', $routes->namedRoute('health.show')->hostPattern());
        } finally {
            $project->cleanup();
        }
    }

    public function testSourceChangeInvalidatesCacheAndLoadsCurrentFile(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/old')->get('Project\\\\Controllers\\\\Home@show')->named('old');");
            $cache = new RouteCache($project->application());
            $cache->build();
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/new')->get('Project\\\\Controllers\\\\Home@show')->named('new');");
            self::assertSame('stale', $cache->status()['state']);
            self::assertNull($cache->inspectCachedRoutes());
            $app = $this->freshApplication($project->root());
            (new RouteCache($app))->loadOrRequire();
            $routes = $app->container()->make(RouteRegistry::class);
            self::assertFalse($routes->hasName('old'));
            self::assertSame('/new', $routes->url('new'));
        } finally {
            $project->cleanup();
        }
    }

    public function testAValidCacheSkipsRouteFileExecutionAcrossPhpProcesses(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('PHP subprocess execution is unavailable.');
        }
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/cached')->get('Project\\\\Controllers\\\\Home@show')->named('cached');");
            $cache = new RouteCache($project->application());
            $cache->build();
            $project->write('Probe.php', '<?php require '
                . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
                . '$squehubApp = new \\App\\Foundation\\Application('
                . var_export($project->root(), true) . ');'
                . '$squehubApp->register(\\App\\Routing\\RoutingServiceProvider::class);'
                . '$squehubApp->bootstrap(); require '
                . var_export(dirname(__DIR__, 2) . '/Bootstrap/Routes.php', true) . ';'
                . 'echo json_encode(['
                . '"included" => in_array(realpath('
                . var_export($project->path('Project/Routes/Web.php'), true)
                . '), array_map("realpath", get_included_files()), true),'
                . '"url" => $squehubApp->container()->make('
                . '\\App\\Routing\\RouteRegistry::class)->url("cached")]);');
            $hit = $this->probe($project->path('Probe.php'));
            self::assertSame(['included' => false, 'url' => '/cached'], $hit);
            self::assertTrue($cache->clear());
            $miss = $this->probe($project->path('Probe.php'));
            self::assertSame(['included' => true, 'url' => '/cached'], $miss);
        } finally {
            $project->cleanup();
        }
    }

    public function testDynamicSourceFailsBuildBeforeAnyPhpExecutes(): void
    {
        $project = TestApplication::temporary();
        try {
            $marker = $project->path('Storage/marker.txt');
            $project->write('Project/Routes/Web.php', '<?php file_put_contents('
                . var_export($marker, true) . ', "executed");');
            $cache = new RouteCache($project->application());
            try {
                $cache->build();
                self::fail('Dynamic route file was accepted.');
            } catch (RouteCacheException $failure) {
                self::assertStringContainsString('Project/Routes/Web.php', $failure->getMessage());
            }
            self::assertFileDoesNotExist($marker);
            self::assertSame('absent', $cache->status()['state']);
        } finally {
            $project->cleanup();
        }
    }

    public function testPackageRoutesKeepProvenanceAndActivationChangeInvalidatesArtifact(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Packages/News/News.php',
                '<?php namespace Packages\\News; final class News extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/News/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/news')->get('Project\\\\Controllers\\\\News@show')->named('news');");
            $packages = new PackageManager(new Application($project->root()));
            $packages->apply($packages->planEnable('News'));
            $app = $this->freshApplication($project->root());
            $cache = new RouteCache($app);
            self::assertSame(1, $cache->build()['routes']);

            $loaded = $this->freshApplication($project->root());
            (new RouteCache($loaded))->loadOrRequire();
            self::assertSame('/news', $loaded->container()->make(RouteRegistry::class)->url('news'));
            $routes = $loaded->contributions()->byType('route');
            self::assertCount(1, $routes);
            self::assertSame('package', $routes[0]->owner->type);
            self::assertSame('News', $routes[0]->owner->name);

            $packages = new PackageManager(new Application($project->root()));
            $packages->apply($packages->planDisable('News'));
            $disabled = $this->freshApplication($project->root());
            self::assertSame('stale', (new RouteCache($disabled))->status()['state']);
            (new RouteCache($disabled))->loadOrRequire();
            self::assertCount(0, $disabled->container()->make(RouteRegistry::class)->all());
        } finally {
            $project->cleanup();
        }
    }

    public function testCorruptArtifactFailsClosedAndClearRestoresNormalLoading(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/ok')->get('Project\\\\Controllers\\\\Home@show');");
            $cache = new RouteCache($project->application());
            $cache->build();
            $project->write('Storage/Cache/Framework/Routes.json', '{broken');
            self::assertSame('corrupt', $cache->status()['state']);
            $app = $this->freshApplication($project->root());
            $this->expectException(RouteCacheException::class);
            try {
                (new RouteCache($app))->loadOrRequire();
            } finally {
                self::assertTrue($cache->clear());
                self::assertFalse($cache->clear());
            }
        } finally {
            $project->cleanup();
        }
    }

    public function testValidJsonWithEditedRouteBytesFailsChecksumValidation(): void
    {
        $project = TestApplication::temporary();
        try {
            $project->write('Project/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/safe')->get('Project\\\\Controllers\\\\Home@show');");
            $cache = new RouteCache($project->application());
            $cache->build();
            $path = $project->path('Storage/Cache/Framework/Routes.json');
            $record = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $record['routes'][0]['uri'] = '/tampered';
            $project->write('Storage/Cache/Framework/Routes.json',
                json_encode($record, JSON_THROW_ON_ERROR));
            self::assertSame('corrupt', $cache->status()['state']);
            $this->expectException(RouteCacheException::class);
            (new RouteCache($this->freshApplication($project->root())))->loadOrRequire();
        } finally {
            $project->cleanup();
        }
    }

    public function testLinkedApplicationRouteFileIsRejected(): void
    {
        $project = TestApplication::temporary();
        try {
            $target = $project->path('Storage/Outside.php');
            $project->write('Storage/Outside.php',
                "<?php \\App\\Routing\\Route::path('/outside')->get('Project\\\\Controllers\\\\Home@show');");
            if (!@symlink($target, $project->path('Project/Routes/Linked.php'))) {
                self::markTestSkipped('Creating a test file link is unavailable.');
            }
            $this->expectException(RouteCacheException::class);
            (new RouteCache($project->application()))->build();
        } finally {
            $project->cleanup();
        }
    }

    private function freshApplication(string $root): Application
    {
        $app = new Application($root);
        $app->register(RoutingServiceProvider::class);
        $app->bootstrap();
        return $app;
    }

    /** @return array{included:bool,url:string} */
    private function probe(string $script): array
    {
        $process = proc_open([PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error ?: 'Probe process failed.');
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
