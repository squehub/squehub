<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Schema\Table;
use App\Idempotency\Idempotency;
use App\Routing\Route;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\IdempotencyWorkerUser;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/IdempotencyWorkerUser.php';

/** Two PHP runtimes enter the same HTTP route while one handler is held open. */
final class IdempotencyConcurrencyTest extends TestCase
{
    /** @dataProvider drivers */
    public function testConcurrentDuplicateNeverRunsSecondHandler(string $driver): void
    {
        $fixture = $this->fixture($driver, 120);
        $leader = null;
        $follower = null;
        try {
            $started = $fixture->path('Storage/worker-started');
            $release = $fixture->path('Storage/worker-release');
            $counter = $fixture->path('Storage/worker-counter');
            $script = dirname(__DIR__) . '/Fixtures/IdempotencyHttpWorker.php';
            $leader = new Process([PHP_BINARY, $script, $fixture->root(), 'leader',
                $started, $release, $counter]);
            $leader->setTimeout(15);
            $leader->start();
            $deadline = hrtime(true) + 10_000_000_000;
            while (!file_exists($started) && $leader->isRunning()) {
                if (hrtime(true) >= $deadline) break;
                usleep(10000);
            }
            self::assertFileExists($started, $leader->getErrorOutput());
            $follower = new Process([PHP_BINARY, $script, $fixture->root(), 'follower',
                $started, $release, $counter]);
            $follower->setTimeout(10);
            $follower->run();
            self::assertTrue($follower->isSuccessful(), $follower->getErrorOutput());
            $duplicate = json_decode($follower->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(409, $duplicate['status']);
            self::assertSame('idempotency_in_progress',
                json_decode($duplicate['body'], true)['error']['code']);
            self::assertSame('1', trim((string) file_get_contents($counter)));
            file_put_contents($release, 'go');
            $leader->wait();
            self::assertTrue($leader->isSuccessful(), $leader->getErrorOutput());
            $first = json_decode($leader->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(201, $first['status']);
            self::assertSame('result', $first['body']);
            self::assertSame('1', trim((string) file_get_contents($counter)));
            $replay = new Process([PHP_BINARY, $script, $fixture->root(), 'follower',
                $started, $release, $counter]);
            $replay->run();
            self::assertTrue($replay->isSuccessful(), $replay->getErrorOutput());
            $replayed = json_decode($replay->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(201, $replayed['status']);
            self::assertSame('true', $replayed['replayed']);
            self::assertSame('result', $replayed['body']);
            self::assertSame('1', trim((string) file_get_contents($counter)));
        } finally {
            if (isset($release) && !file_exists($release)) file_put_contents($release, 'go');
            if ($leader instanceof Process && $leader->isRunning()) $leader->stop(1);
            if ($follower instanceof Process && $follower->isRunning()) $follower->stop(1);
            $fixture->cleanup();
            Auth::setResolver(null);
            Database::setResolver(null);
            Idempotency::setResolver(null);
            Route::setResolver(null);
        }
    }

    /** @dataProvider drivers */
    public function testCrashedHandlerReclaimsOnlyAfterLeaseExpires(string $driver): void
    {
        $fixture = $this->fixture($driver, 3);
        try {
            $started = $fixture->path('Storage/crash-started');
            $release = $fixture->path('Storage/crash-release');
            $counter = $fixture->path('Storage/crash-counter');
            $script = dirname(__DIR__) . '/Fixtures/IdempotencyHttpWorker.php';
            $key = 'crash-request-key';
            $crash = new Process([PHP_BINARY, $script, $fixture->root(), 'crash',
                $started, $release, $counter, $key]);
            $crash->run();
            self::assertTrue($crash->isSuccessful(), $crash->getErrorOutput());
            self::assertFileExists($started);
            self::assertSame('1', trim((string) file_get_contents($counter)));
            $before = new Process([PHP_BINARY, $script, $fixture->root(), 'follower',
                $started, $release, $counter, $key]);
            $before->run();
            self::assertTrue($before->isSuccessful(), $before->getErrorOutput());
            self::assertSame(409, json_decode($before->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']);
            self::assertSame('1', trim((string) file_get_contents($counter)));
            sleep(4);
            $after = new Process([PHP_BINARY, $script, $fixture->root(), 'follower',
                $started, $release, $counter, $key]);
            $after->run();
            self::assertTrue($after->isSuccessful(), $after->getErrorOutput());
            self::assertSame(201, json_decode($after->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status']);
            self::assertSame('2', trim((string) file_get_contents($counter)));
        } finally {
            $fixture->cleanup();
            Auth::setResolver(null);
            Database::setResolver(null);
            Idempotency::setResolver(null);
            Route::setResolver(null);
        }
    }

    private function fixture(string $driver, int $lease): TestApplication
    {
        $fixture = TestApplication::temporary();
        try {
            $fixture->configure([
                'database' => ['connections' => ['testing' => [
                    'driver' => 'sqlite', 'database' => $fixture->path('Storage/idempotency-worker.sqlite')]]],
                'csrf' => ['enabled' => false],
                'api' => ['enabled' => true, 'paths' => ['/api']],
                'idempotency' => ['driver' => $driver, 'namespace' => 'process-test',
                    'lease_seconds' => $lease, 'retention_seconds' => 600],
                'auth' => [
                    'default' => 'web',
                    'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
                    'identities' => ['users' => ['driver' => 'model',
                        'model' => IdempotencyWorkerUser::class,
                        'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
                    'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                        'rehash_on_login' => false],
                ],
            ]);
            $database = $fixture->application()->container()->make(DatabaseManager::class);
            $database->schema()->create('idempotency_worker_users', static function (Table $table): void {
                $table->id();
                $table->string('email');
                $table->string('password');
            });
            IdempotencyWorkerUser::create(['email' => 'worker@example.test',
                'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
            if ($driver === 'database') {
                require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_10_02_create_idempotency_records.php';
                (new \CreateIdempotencyRecords())->up($database->connection()->pdo(), $database->schema());
            }
            $database->disconnect();
            return $fixture;
        } catch (\Throwable $failure) {
            $fixture->cleanup();
            throw $failure;
        }
    }

    public static function drivers(): array
    {
        return [['file'], ['database']];
    }
}
