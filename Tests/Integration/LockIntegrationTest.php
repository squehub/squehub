<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\SystemModelClock;
use App\Foundation\Application;
use App\Locks\Lock;
use App\Locks\LockBackendException;
use App\Locks\LockConfigurationException;
use App\Locks\LockManager;
use App\Locks\LockOwnershipException;
use App\Locks\LockServiceProvider;
use App\Locks\LockStorageException;
use App\Locks\Stores\DatabaseLockStore;
use App\Locks\Stores\FileLockStore;
use App\Support\RuntimeContext;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises real local storage and two independent PHP processes per backend. */
final class LockIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private ?DatabaseManager $database = null;
    private ?Process $worker = null;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        if ($this->worker?->isRunning()) $this->worker->stop(1);
        $this->database?->disconnect();
        Lock::setResolver(null);
        $this->project->remove();
    }

    public function testFileDriverUsesPrivateHashedLeaseAndRejectsWrongOwner(): void
    {
        $root = $this->project->path('Storage/Locks');
        $namespace = 'lock-test';
        $manager = new LockManager(new FileLockStore($root, $namespace), $namespace,
            new SystemModelClock(), 'file');
        $first = $manager->acquire('invoice:secret', 30);
        self::assertTrue($first->acquired());
        self::assertFalse($manager->acquire('invoice:secret', 30)->acquired());
        $directory = $root . '/' . hash('sha256', $namespace);
        $hash = hash('sha256', "squehub-lock-v1\0" . $namespace . "\0invoice:secret");
        self::assertFileExists($directory . '/' . $hash . '.lease');
        self::assertFileExists($directory . '/' . substr($hash, 0, 2) . '.mutex');
        self::assertFileDoesNotExist($directory . '/invoice:secret.lease');
        $other = new FileLockStore($root, $namespace);
        $this->expectException(LockOwnershipException::class);
        try {
            $other->release($hash, str_repeat('0', 64), time());
        } finally {
            self::assertTrue($first->release());
        }
    }

    public function testFileCorruptionFailsClosed(): void
    {
        $root = $this->project->path('Storage/Locks');
        $namespace = 'corrupt-test';
        $manager = new LockManager(new FileLockStore($root, $namespace), $namespace,
            new SystemModelClock(), 'file');
        $manager->acquire('key');
        $hash = hash('sha256', "squehub-lock-v1\0" . $namespace . "\0key");
        $path = $root . '/' . hash('sha256', $namespace) . '/' . $hash . '.lease';
        file_put_contents($path, '{broken');
        $this->expectException(LockStorageException::class);
        $manager->acquire('key');
    }

    public function testFileMutexCountIsBoundedForDynamicLockNames(): void
    {
        $root = $this->project->path('Storage/Locks');
        $namespace = 'bounded-stripes';
        $manager = new LockManager(new FileLockStore($root, $namespace), $namespace,
            new SystemModelClock(), 'file');
        for ($index = 0; $index < 300; ++$index) {
            $lease = $manager->acquire('invoice:' . $index);
            self::assertTrue($lease->acquired());
            self::assertTrue($lease->release());
        }
        $directory = $root . '/' . hash('sha256', $namespace);
        $mutexes = glob($directory . '/*.mutex');
        $leases = glob($directory . '/*.lease');
        self::assertIsArray($mutexes);
        self::assertIsArray($leases);
        self::assertGreaterThan(0, count($mutexes));
        self::assertLessThanOrEqual(256, count($mutexes));
        self::assertSame([], $leases);
    }

    public function testFileDriverRejectsLinkedAncestorWithoutCreatingLease(): void
    {
        $target = $this->project->path('actual-parent');
        mkdir($target);
        $link = $this->project->path('linked-parent');
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Creating directory symlinks is unavailable on this host.');
        }
        $manager = new LockManager(new FileLockStore($link . '/Locks', 'linked-test'),
            'linked-test', new SystemModelClock(), 'file');
        $this->expectException(LockBackendException::class);
        try {
            $manager->acquire('key');
        } finally {
            self::assertDirectoryDoesNotExist($target . '/Locks');
        }
    }

    public function testFileContentionAcrossProcessesAndRecoveryAfterRelease(): void
    {
        $root = $this->project->path('Storage/Locks');
        $this->startWorker('file', $root, 'file-process', 'hold');
        $manager = new LockManager(new FileLockStore($root, 'file-process'), 'file-process',
            new SystemModelClock(), 'file');
        self::assertFalse($manager->acquire('cross-process')->acquired());
        $this->waitWorker();
        self::assertTrue($manager->acquire('cross-process')->acquired());
    }

    public function testCrashedFileOwnerRemainsUntilExpiryAndThenCanBeReclaimed(): void
    {
        $root = $this->project->path('Storage/Locks');
        $this->startWorker('file', $root, 'file-crash', 'crash');
        $this->waitWorker();
        $store = new FileLockStore($root, 'file-crash');
        $current = new LockManager($store, 'file-crash', new SystemModelClock(), 'file');
        self::assertFalse($current->acquire('cross-process')->acquired());
        $future = new class implements \App\Database\ModelClock {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . (time() + 10)); }
        };
        self::assertTrue((new LockManager($store, 'file-crash', $future, 'file'))
            ->acquire('cross-process')->acquired());
    }

    public function testDatabaseDriverUniqueClaimExpiryAndWrongOwner(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->database = $this->database($this->project->path('locks.sqlite'));
        $this->migrate($this->database);
        $one = new LockManager(new DatabaseLockStore($this->database->connection()), 'db-test',
            new SystemModelClock(), 'database');
        $otherDb = $this->database($this->project->path('locks.sqlite'));
        try {
            $two = new LockManager(new DatabaseLockStore($otherDb->connection()), 'db-test',
                new SystemModelClock(), 'database');
            $first = $one->acquire('invoice:123', 30);
            self::assertTrue($first->acquired());
            self::assertFalse($two->acquire('invoice:123')->acquired());
            $rows = $this->database->raw('SELECT `key_hash`,`owner_token` FROM `reliability_locks`')->fetchAll();
            self::assertCount(1, $rows);
            self::assertSame($first->ownerToken(), $rows[0]['owner_token']);
            self::assertStringNotContainsString('invoice:123', $rows[0]['key_hash']);
            self::assertTrue($first->release());
            self::assertTrue($two->acquire('invoice:123')->acquired());
        } finally {
            $otherDb->disconnect();
        }
    }

    public function testDatabaseContentionAcrossProcessesAndRecoveryAfterRelease(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $path = $this->project->path('locks.sqlite');
        $this->database = $this->database($path);
        $this->migrate($this->database);
        $this->startWorker('database', $path, 'db-process', 'hold');
        $manager = new LockManager(new DatabaseLockStore($this->database->connection()),
            'db-process', new SystemModelClock(), 'database');
        self::assertFalse($manager->acquire('cross-process')->acquired());
        $this->waitWorker();
        self::assertTrue($manager->acquire('cross-process')->acquired());
    }

    public function testDatabaseExpiryReacquisitionKeepsNewOwnerSafe(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->database = $this->database($this->project->path('locks.sqlite'));
        $this->migrate($this->database);
        $clock = new class implements \App\Database\ModelClock {
            public int $epoch = 1_800_000_000;
            public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->epoch); }
        };
        $manager = new LockManager(new DatabaseLockStore($this->database->connection()),
            'db-expiry', $clock, 'database');
        $old = $manager->acquire('key', 1);
        $clock->epoch += 1;
        $new = $manager->acquire('key', 30);
        self::assertTrue($new->acquired());
        self::assertNotSame($old->ownerToken(), $new->ownerToken());
        try {
            $old->release();
            self::fail('A stale owner must not release a live successor.');
        } catch (LockOwnershipException) {
            self::assertTrue($new->release());
        }
    }

    public function testDatabaseLeaseRejectsUseInsideExistingTransaction(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->database = $this->database($this->project->path('locks.sqlite'));
        $this->migrate($this->database);
        $connection = $this->database->connection();
        $connection->begin();
        try {
            $manager = new LockManager(new DatabaseLockStore($connection), 'db-transaction',
                new SystemModelClock(), 'database');
            $this->expectException(LockBackendException::class);
            $manager->acquire('key');
        } finally {
            $connection->rollback();
        }
    }

    public function testProviderIsLazyAndGlobalBridgeSelectsOwningApplication(): void
    {
        $other = new TemporaryProject();
        try {
            foreach ([$this->project, $other] as $project) {
                $project->write('Config/Locks.php', '<?php return ["driver" => "array"];');
            }
            $first = new Application($this->project->path());
            $first->register(LockServiceProvider::class);
            $first->bootstrap();
            $second = new Application($other->path());
            $second->register(LockServiceProvider::class);
            $second->bootstrap();
            RuntimeContext::select($first);
            $held = lock()->acquire('same');
            self::assertTrue($held->acquired());
            self::assertFalse(\App\Plugins\Lock::acquire('same')->acquired());
            self::assertTrue(class_exists(\App\Plugins\LockHandle::class));
            self::assertInstanceOf(\App\Plugins\LockHandle::class, $held);
            RuntimeContext::select($second);
            self::assertTrue(lock()->acquire('same')->acquired());
            RuntimeContext::select($first);
            self::assertFalse(lock()->acquire('same')->acquired());
            self::assertTrue($held->release());
        } finally {
            $other->remove();
        }
    }

    public function testFileProviderBootDoesNotCreateStorageUntilAcquire(): void
    {
        $this->project->write('Config/Locks.php', '<?php return ["driver" => "file"];');
        $app = new Application($this->project->path());
        $app->register(LockServiceProvider::class);
        $app->bootstrap();
        self::assertDirectoryDoesNotExist($this->project->path('Storage/Locks'));
        $handle = $app->container()->make(LockManager::class)->acquire('lazy');
        self::assertTrue($handle->acquired());
        self::assertDirectoryExists($this->project->path('Storage/Locks'));
        self::assertTrue($handle->release());
    }

    public function testExplicitRedisNeverFallsBackAndDistributedRequirementRejectsLocal(): void
    {
        $this->project->write('Config/Locks.php', '<?php return ["driver" => "redis"];');
        $app = new Application($this->project->path());
        $app->register(LockServiceProvider::class);
        $app->bootstrap();
        $this->expectException(LockConfigurationException::class);
        $app->container()->make(LockManager::class);
    }

    public function testDistributedRequirementRejectsLocalBackendAtBoot(): void
    {
        $this->project->write('Config/Locks.php',
            '<?php return ["driver" => "file", "require_distributed" => true];');
        $app = new Application($this->project->path());
        $app->register(LockServiceProvider::class);
        $this->expectException(LockConfigurationException::class);
        $app->bootstrap();
    }

    private function database(string $path): DatabaseManager
    {
        return new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $path,
            ]],
        ]]));
    }

    private function migrate(DatabaseManager $database): void
    {
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_10_02_create_reliability_locks.php';
        (new \CreateReliabilityLocks())->up($database->connection()->pdo(),
            $database->connection()->schema());
    }

    private function startWorker(string $driver, string $path, string $namespace, string $mode): void
    {
        $marker = $this->project->path('worker.ready');
        $this->worker = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/LockProcessWorker.php',
            $driver, $path, $namespace, $marker, $mode]);
        $this->worker->start();
        $deadline = hrtime(true) + 5_000_000_000;
        while (!is_file($marker) && hrtime(true) < $deadline) {
            if (!$this->worker->isRunning()) break;
            usleep(10_000);
        }
        self::assertFileExists($marker, $this->worker->getErrorOutput());
    }

    private function waitWorker(): void
    {
        self::assertNotNull($this->worker);
        $this->worker->wait();
        self::assertTrue($this->worker->isSuccessful(),
            $this->worker->getErrorOutput() . $this->worker->getOutput());
    }
}
