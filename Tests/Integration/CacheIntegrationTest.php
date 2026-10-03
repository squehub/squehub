<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\Cache;
use App\Cache\CacheServiceProvider;
use App\Cache\CacheStore;
use App\Cache\Drivers\FileCacheDriver;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Tests Application wiring, HTTP metrics, and the real CLI namespace boundary. */
final class CacheIntegrationTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        Cache::setResolver(null);
        Route::setResolver(null);
        Diagnostic::setResolver(null);
        $this->project->remove();
    }

    public function testApplicationHelperHttpMetricsAndRequestReset(): void
    {
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "array"];');
        $app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, CacheServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $store = $app->container()->make(CacheStore::class);
        self::assertSame($store, cache());
        $registry = $app->container()->make(RouteRegistry::class);
        $registry->add('GET', '/cache', static function (): Response {
            cache()->store('private:user:123', null);
            $value = cache()->read('private:user:123', 'missing');
            cache()->read('missing:secret', 'fallback');
            cache()->take('private:user:123');
            return new Response($value === null ? 'cached-null' : 'wrong');
        });
        $kernel = $app->container()->make(Kernel::class);
        $first = $kernel->handle(new Request('GET', '/cache'));
        self::assertSame('cached-null', $first->content());
        $diagnostics = $app->container()->make(Diagnostics::class);
        self::assertSame(['reads' => 3, 'hits' => 2, 'misses' => 1, 'writes' => 1, 'removals' => 1],
            array_intersect_key($diagnostics->snapshot()['cache'], array_fill_keys(
                ['reads', 'hits', 'misses', 'writes', 'removals'], true)));
        self::assertGreaterThanOrEqual(0.0, $diagnostics->snapshot()['cache']['time_ms']);
        self::assertStringNotContainsString('private:user:123', json_encode($diagnostics->snapshot()));
        $kernel->handle(new Request('GET', '/missing'));
        self::assertSame(0, $diagnostics->snapshot()['cache']['reads']);
        self::assertSame(0, $diagnostics->snapshot()['cache']['writes']);
    }

    public function testRememberMetricsCountHitAndMissWithoutKeyText(): void
    {
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "array"];');
        $app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, CacheServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $app->container()->make(RouteRegistry::class)->add('GET', '/remember', static function (): Response {
            cache()->remember('secret:key', 30, static fn (): int => 1);
            cache()->remember('secret:key', 30, static fn (): int => 2);
            return new Response('done');
        });
        $app->container()->make(Kernel::class)->handle(new Request('GET', '/remember'));
        $snapshot = $app->container()->make(Diagnostics::class)->snapshot();
        self::assertSame(2, $snapshot['cache']['reads']);
        self::assertSame(1, $snapshot['cache']['hits']);
        self::assertSame(1, $snapshot['cache']['misses']);
        self::assertSame(1, $snapshot['cache']['writes']);
        self::assertStringNotContainsString('secret:key', json_encode($snapshot));
    }

    public function testCliClearTouchesOnlyConfiguredDataCacheNamespace(): void
    {
        $root = $this->project->path('Storage/Cache');
        $namespace = 'phase8b-' . bin2hex(random_bytes(4));
        $driver = new FileCacheDriver($root, $namespace);
        $driver->put(hash('sha256', 'value'), new \App\Cache\CacheEntry('cached', null));
        $other = new FileCacheDriver($root, 'other-namespace');
        $other->put(hash('sha256', 'other'), new \App\Cache\CacheEntry('kept', null));
        file_put_contents($root . '/compiled-view.php', '<?php echo 1;');
        $process = new Process([PHP_BINARY, 'squehub', 'cache:clear'], dirname(__DIR__, 2), [
            'CACHE_DRIVER' => 'file', 'CACHE_PATH' => $root, 'CACHE_PREFIX' => $namespace,
        ]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());
        self::assertStringContainsString('Application cache cleared', $process->getOutput());
        self::assertNull($driver->fetch(hash('sha256', 'value'), static fn (): int => time()));
        self::assertSame('kept', $other->fetch(hash('sha256', 'other'), static fn (): int => time())?->value);
        self::assertFileExists($root . '/compiled-view.php');
    }

    public function testProviderRejectsUnsafeConfigurationWithoutOpeningStorage(): void
    {
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "file", "prefix" => "../bad"];');
        $app = new Application($this->project->path());
        $app->register(CacheServiceProvider::class);
        $this->expectException(\InvalidArgumentException::class);
        try {
            $app->bootstrap();
        } finally {
            self::assertDirectoryDoesNotExist($this->project->path('Storage/Cache'));
        }
    }

    /** @dataProvider invalidCacheConfigurations */
    public function testProviderRejectsUnsupportedDriverOrRelativePath(string $configuration): void
    {
        $this->project->write('Config/Cache.php', '<?php return ' . $configuration . ';');
        $app = new Application($this->project->path());
        $app->register(CacheServiceProvider::class);
        $this->expectException(\InvalidArgumentException::class);
        $app->bootstrap();
    }

    /** @return array<string, array{string}> */
    public static function invalidCacheConfigurations(): array
    {
        return [
            'driver' => ['["driver" => "unknown"]'],
            'relative path' => ['["driver" => "file", "path" => "Storage/Cache"]'],
        ];
    }

    public function testCliClearReportsBackendFailureWithoutExposingPath(): void
    {
        $root = $this->project->path('cache-root-file');
        file_put_contents($root, 'not a directory');
        $process = new Process([PHP_BINARY, 'squehub', 'cache:clear'], dirname(__DIR__, 2), [
            'CACHE_DRIVER' => 'file', 'CACHE_PATH' => $root, 'CACHE_PREFIX' => 'safe-test',
        ]);
        $process->run();
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('could not be cleared', $process->getOutput());
        self::assertStringNotContainsString($root, $process->getOutput());
        self::assertFileExists($root);
    }

    public function testSeparateArrayApplicationsDoNotShareValues(): void
    {
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "array"];');
        $first = new Application($this->project->path());
        $first->register(CacheServiceProvider::class);
        $first->bootstrap();
        $firstStore = $first->container()->make(CacheStore::class);
        $firstStore->store('key', 1);
        $second = new Application($this->project->path());
        $second->register(CacheServiceProvider::class);
        $second->bootstrap();
        self::assertFalse($second->container()->make(CacheStore::class)->has('key'));
        self::assertSame(1, $firstStore->read('key'));
    }

    public function testFileProviderSeparatesApplicationPathsAndCanShareExplicitPrefix(): void
    {
        $secondProject = new TemporaryProject();
        try {
            $root = $this->project->path('SharedCache');
            $config = '<?php return ["driver" => "file", "path" => ' . var_export($root, true) . '];';
            $this->project->write('Config/Cache.php', $config);
            $secondProject->write('Config/Cache.php', $config);
            $first = new Application($this->project->path());
            $first->register(CacheServiceProvider::class);
            $first->bootstrap();
            $second = new Application($secondProject->path());
            $second->register(CacheServiceProvider::class);
            $second->bootstrap();
            $first->container()->make(CacheStore::class)->store('same', 'first');
            self::assertFalse($second->container()->make(CacheStore::class)->has('same'));
            $second->container()->make(CacheStore::class)->store('same', 'second');
            self::assertSame('first', $first->container()->make(CacheStore::class)->read('same'));
            $first->container()->make(CacheStore::class)->clear();
            self::assertSame('second', $second->container()->make(CacheStore::class)->read('same'));

            $shared = '<?php return ["driver" => "file", "path" => ' . var_export($root, true)
                . ', "prefix" => "shared-test"];';
            $thirdProject = new TemporaryProject();
            try {
                $thirdProject->write('Config/Cache.php', $shared);
                $fourthProject = new TemporaryProject();
                try {
                    $fourthProject->write('Config/Cache.php', $shared);
                    $third = new Application($thirdProject->path());
                    $third->register(CacheServiceProvider::class);
                    $third->bootstrap();
                    $fourth = new Application($fourthProject->path());
                    $fourth->register(CacheServiceProvider::class);
                    $fourth->bootstrap();
                    $third->container()->make(CacheStore::class)->store('shared', 42);
                    self::assertSame(42, $fourth->container()->make(CacheStore::class)->read('shared'));
                } finally {
                    $fourthProject->remove();
                }
            } finally {
                $thirdProject->remove();
            }
        } finally {
            $secondProject->remove();
        }
    }

    public function testFileRememberWaitsForOtherProcessKeyLock(): void
    {
        $root = $this->project->path('Storage/Cache');
        $namespace = 'concurrency-test';
        $store = new CacheStore(new FileCacheDriver($root, $namespace), $namespace,
            new \App\Database\SystemModelClock());
        $store->store('initial', true);
        $directory = $root . '/' . hash('sha256', $namespace);
        $hash = hash('sha256', $namespace . "\0" . 'compute');
        $lock = fopen($directory . '/' . $hash . '.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $ready = $this->project->path('ready-one');
        $readyTwo = $this->project->path('ready-two');
        $called = $this->project->path('called');
        $script = $this->project->path('worker.php');
        $this->project->write('worker.php', '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; file_put_contents($argv[1], "ready");'
            . '$store = new \\App\\Cache\\CacheStore(new \\App\\Cache\\Drivers\\FileCacheDriver('
            . var_export($root, true) . ', ' . var_export($namespace, true) . '), '
            . var_export($namespace, true) . ', new \\App\\Database\\SystemModelClock());'
            . 'echo $store->remember("compute", null, static function () { file_put_contents('
            . var_export($called, true) . ', "called", FILE_APPEND | LOCK_EX); return 7; });');
        $process = new Process([PHP_BINARY, $script, $ready], dirname(__DIR__, 2));
        $secondProcess = new Process([PHP_BINARY, $script, $readyTwo], dirname(__DIR__, 2));
        $process->setTimeout(10);
        $secondProcess->setTimeout(10);
        try {
            $process->start();
            $secondProcess->start();
            $deadline = microtime(true) + 5;
            while ((!file_exists($ready) || !file_exists($readyTwo))
                && $process->isRunning() && $secondProcess->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($ready);
            self::assertFileExists($readyTwo);
            usleep(50000);
        self::assertFileDoesNotExist($called);
            $store->store('unrelated', 99); // An unrelated key remains writable.
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $process->wait();
        $secondProcess->wait();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertTrue($secondProcess->isSuccessful(), $secondProcess->getErrorOutput());
        self::assertSame('7', $process->getOutput());
        self::assertSame('7', $secondProcess->getOutput());
        self::assertFileExists($called);
        self::assertSame('called', file_get_contents($called));
        self::assertSame(7, $store->remember('compute', null, static fn (): int => 99));
    }

    public function testOnlyOneProcessCanTakeAFileEntry(): void
    {
        $root = $this->project->path('Storage/Cache');
        $namespace = 'take-test';
        $store = new CacheStore(new FileCacheDriver($root, $namespace), $namespace,
            new \App\Database\SystemModelClock());
        $store->store('one-time', 'token');
        $directory = $root . '/' . hash('sha256', $namespace);
        $hash = hash('sha256', $namespace . "\0" . 'one-time');
        $lock = fopen($directory . '/' . $hash . '.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));
        $script = $this->project->path('take-worker.php');
        $this->project->write('take-worker.php', '<?php require '
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; file_put_contents($argv[1], "ready");'
            . '$store = new \\App\\Cache\\CacheStore(new \\App\\Cache\\Drivers\\FileCacheDriver('
            . var_export($root, true) . ', ' . var_export($namespace, true) . '), '
            . var_export($namespace, true) . ', new \\App\\Database\\SystemModelClock());'
            . 'echo $store->take("one-time", "missing");');
        $readyOne = $this->project->path('take-ready-one');
        $readyTwo = $this->project->path('take-ready-two');
        $first = new Process([PHP_BINARY, $script, $readyOne], dirname(__DIR__, 2));
        $second = new Process([PHP_BINARY, $script, $readyTwo], dirname(__DIR__, 2));
        $first->setTimeout(10);
        $second->setTimeout(10);
        try {
            $first->start();
            $second->start();
            $deadline = microtime(true) + 5;
            while ((!file_exists($readyOne) || !file_exists($readyTwo))
                && $first->isRunning() && $second->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertFileExists($readyOne);
            self::assertFileExists($readyTwo);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $first->wait();
        $second->wait();
        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $results = [$first->getOutput(), $second->getOutput()];
        sort($results);
        self::assertSame(['missing', 'token'], $results);
        self::assertFalse($store->has('one-time'));
    }
}
