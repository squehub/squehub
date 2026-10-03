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
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Storage\Storage;
use App\Storage\StorageManager;
use App\Storage\StorageServiceProvider;
use App\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Verifies provider isolation, lazy roots, helpers, and request-safe metrics. */
final class StorageIntegrationTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        Storage::setResolver(null);
        $this->project->remove();
    }

    public function testStorageOnlyApplicationUsesNamedDrivesAndLazyLocalRoot(): void
    {
        $this->project->write('Config/Storage.php', '<?php return ["default" => "memory", "drives" => ["memory" => ["driver" => "array"], "other" => ["driver" => "array"], "local" => ["driver" => "local", "root" => "Storage/Files"]]];');
        $app = new Application($this->project->path());
        $app->register(StorageServiceProvider::class);
        $app->bootstrap();
        self::assertDirectoryDoesNotExist($this->project->path('Storage/Files'));
        $manager = $app->container()->make(StorageManager::class);
        self::assertSame($manager, storage());
        self::assertSame($manager->drive('memory'), $manager->drive('memory'));
        $manager->write('a', 'one');
        self::assertSame('one', $manager->read('a'));
        self::assertFalse($manager->drive('other')->exists('a'));
        $manager->drive('local')->write('a', 'disk');
        self::assertSame('disk', file_get_contents($this->project->path('Storage/Files/a')));
        self::assertSame('one', $manager->read('a'));
        self::assertDirectoryExists($this->project->path('Storage/Files'));
        try {
            $manager->drive('missing');
            self::fail('Unknown drive must fail.');
        } catch (StorageException) {}
    }

    public function testSeparateApplicationsDoNotShareMemory(): void
    {
        $this->project->write('Config/Storage.php', '<?php return ["default" => "memory", "drives" => ["memory" => ["driver" => "array"]]];');
        $first = new Application($this->project->path());
        $first->register(StorageServiceProvider::class);
        $first->bootstrap();
        $first->container()->make(StorageManager::class)->write('a', 'first');
        $second = new Application($this->project->path());
        $second->register(StorageServiceProvider::class);
        $second->bootstrap();
        self::assertFalse($second->container()->make(StorageManager::class)->exists('a'));
    }

    public function testUnsupportedDriverAndPrivateException(): void
    {
        $this->project->write('Config/Storage.php', '<?php return ["default" => "bad", "drives" => ["bad" => ["driver" => "s3"]]];');
        $app = new Application($this->project->path());
        $app->register(StorageServiceProvider::class);
        $app->bootstrap();
        $manager = $app->container()->make(StorageManager::class);
        try {
            $manager->write('customer-secret-name', 'sensitive-content');
            self::fail('Unsupported driver must fail.');
        } catch (StorageException $error) {
            self::assertStringNotContainsString('customer-secret-name', $error->getMessage());
            self::assertStringNotContainsString('sensitive-content', $error->getMessage());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        }
    }

    public function testStorageMetricsResetBetweenRequestsWithoutRetainingNames(): void
    {
        $this->project->write('Config/Storage.php', '<?php return ["default" => "memory", "drives" => ["memory" => ["driver" => "array"]]];');
        $app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, StorageServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) $app->register($provider);
        $app->bootstrap();
        $app->container()->make(RouteRegistry::class)->add('GET', '/files', static function (): Response {
            storage()->write('private/customer-secret-name', 'sensitive-content');
            storage()->read('private/customer-secret-name');
            storage()->exists('private/customer-secret-name');
            storage()->files('private');
            try {
                storage()->read('private/missing-private');
            } catch (StorageException) {
                // Failed attempts contribute only aggregate diagnostics.
            }
            return new Response('ok');
        });
        $kernel = $app->container()->make(Kernel::class);
        self::assertSame('ok', $kernel->handle(new Request('GET', '/files'))->content());
        $diagnostics = $app->container()->make(Diagnostics::class);
        $metrics = $diagnostics->snapshot()['storage'];
        self::assertSame(5, $metrics['operations']);
        self::assertSame(2, $metrics['reads']);
        self::assertSame(1, $metrics['writes']);
        self::assertSame(1, $metrics['failures']);
        self::assertSame(17, $metrics['bytes_written']);
        self::assertSame(17, $metrics['bytes_read']);
        self::assertStringNotContainsString('customer-secret-name', json_encode($diagnostics->snapshot()));
        self::assertStringNotContainsString('sensitive-content', json_encode($diagnostics->snapshot()));
        $kernel->handle(new Request('GET', '/missing'));
        self::assertSame(0, $diagnostics->snapshot()['storage']['operations']);
    }

    public function testDefaultRootCannotTouchSiblingRuntimeAreas(): void
    {
        $this->project->write('Config/Storage.php', '<?php return ["default" => "local", "drives" => ["local" => ["driver" => "local", "root" => null]]];');
        $this->project->write('Storage/Logs/log.txt', 'keep log');
        $this->project->write('Storage/Cache/view.php', 'keep cache');
        $app = new Application($this->project->path());
        $app->register(StorageServiceProvider::class);
        $app->bootstrap();
        $manager = $app->container()->make(StorageManager::class);
        $manager->write('temp/file', 'remove me');
        $manager->removeDirectory('temp', true);
        self::assertSame('keep log', file_get_contents($this->project->path('Storage/Logs/log.txt')));
        self::assertSame('keep cache', file_get_contents($this->project->path('Storage/Cache/view.php')));
    }
}
