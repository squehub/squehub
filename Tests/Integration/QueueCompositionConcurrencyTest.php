<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Queue\Drivers\DatabaseQueueDriver;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Composed job payload survives another PHP process without static state. */
final class CompositionProcessJob implements QueueJob
{
    public function __construct(private string $label) {}
    public function handle(): void {}
    public function toQueuePayload(): array { return ['label' => $this->label]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['label']);
    }
}

/** Two processes race to settle one reservation; only one may advance it. */
final class QueueCompositionConcurrencyTest extends TestCase
{
    public function testCancellationRacingReservationCannotLeaveExecutableBatchWork(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue composition races.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = $project->path('cancel.sqlite');
        $barrier = $project->path('cancel-race');
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $database]],
        ]]));
        $processes = [];
        try {
            $connection = $manager->connection();
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            require_once $root . '/Database/Migrations/2026_09_30_create_queue_compositions.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            (new \CreateQueueCompositions())->up($connection->pdo(), $connection->schema());
            $queue = new QueueManager(['default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'test'],
            ]], fn (?string $name) => $manager->connection($name));
            $handle = $queue->batch([new CompositionProcessJob('A'), new CompositionProcessJob('B')]);
            $project->write('CancelRace.php', <<<'PHP'
<?php
require $argv[1];
$database = new \App\Database\DatabaseManager(new \App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $argv[2]]],
]]));
$driver = new \App\Queue\Drivers\DatabaseQueueDriver($database->connection(),
    new \App\Database\SystemModelClock());
file_put_contents($argv[4] . '.' . $argv[5], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[4] . '.go')) {
    if (microtime(true) > $deadline) exit(3);
    usleep(10000);
}
if ($argv[3] === 'cancel') {
    $driver->cancelComposition($argv[6]);
    echo 'CANCEL';
    exit(0);
}
$job = $driver->reserve('default');
if ($job === null) { echo 'EMPTY'; exit(0); }
if (!$driver->shouldRunComposition($job)) {
    $driver->skipComposition($job);
    echo 'SKIP';
    exit(0);
}
usleep(100000);
$driver->acknowledge($job);
echo 'EXEC';
PHP);
            foreach (['reserve', 'cancel'] as $operation) {
                $process = new Process([PHP_BINARY, $project->path('CancelRace.php'),
                    $root . '/vendor/autoload.php', $database, $operation,
                    $barrier, $operation, $handle->id], $root);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (!is_file($barrier . '.reserve') || !is_file($barrier . '.cancel')) {
                if (microtime(true) > $deadline) self::fail('Cancellation race missed the barrier.');
                usleep(10000);
            }
            file_put_contents($barrier . '.go', 'go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outcomes[] = $process->getOutput();
            }
            self::assertContains('CANCEL', $outcomes);
            self::assertSame('cancelled', $handle->status()->state);
            self::assertSame(2, $handle->status()->succeeded + $handle->status()->cancelled);
            self::assertSame(0, (int) $connection->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
            self::assertFalse((new Worker($queue))->workOnce());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
            $manager->disconnect();
            $project->remove();
        }
    }

    public function testDuplicateCompletionCannotEnqueueNextStepTwice(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue composition races.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = $project->path('queue.sqlite');
        $barrier = $project->path('settle');
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $database]],
        ]]));
        $processes = [];
        try {
            $connection = $manager->connection();
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            require_once $root . '/Database/Migrations/2026_09_30_create_queue_compositions.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            (new \CreateQueueCompositions())->up($connection->pdo(), $connection->schema());
            $queue = new QueueManager(['default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'test'],
            ]], fn (?string $name) => $manager->connection($name));
            $handle = $queue->chain([new CompositionProcessJob('A'),
                new CompositionProcessJob('B'), new CompositionProcessJob('C')]);
            $driver = $queue->driver();
            self::assertInstanceOf(DatabaseQueueDriver::class, $driver);
            $reserved = $driver->reserve('default');
            self::assertNotNull($reserved);
            $project->write('Settle.php', <<<'PHP'
<?php
require $argv[1];
$database = new \App\Database\DatabaseManager(new \App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $argv[2]]],
]]));
$driver = new \App\Queue\Drivers\DatabaseQueueDriver($database->connection(),
    new \App\Database\SystemModelClock());
$job = new \App\Queue\ReservedJob((int) $argv[3], 'default', '', 1,
    $argv[4], $argv[5], (int) $argv[6]);
file_put_contents($argv[7] . '.' . $argv[8], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[7] . '.go')) {
    if (microtime(true) > $deadline) exit(3);
    usleep(10000);
}
try {
    $driver->acknowledge($job);
    echo 'ACK';
} catch (\App\Queue\QueueException) {
    echo 'STALE';
}
PHP);
            foreach (['a', 'b'] as $label) {
                $process = new Process([PHP_BINARY, $project->path('Settle.php'),
                    $root . '/vendor/autoload.php', $database,
                    (string) $reserved->id, $reserved->token, (string) $reserved->compositionId,
                    (string) $reserved->compositionPosition, $barrier, $label], $root);
                $process->start();
                $processes[$label] = $process;
            }
            $deadline = microtime(true) + 10;
            while (!is_file($barrier . '.a') || !is_file($barrier . '.b')) {
                if (microtime(true) > $deadline) self::fail('Composition workers missed the barrier.');
                usleep(10000);
            }
            file_put_contents($barrier . '.go', 'go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outcomes[] = $process->getOutput();
            }
            sort($outcomes);
            self::assertSame(['ACK', 'STALE'], $outcomes);
            self::assertSame(1, $handle->status()->succeeded);
            self::assertSame(1, (int) $connection->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
            self::assertSame(1, (int) $connection->raw('SELECT COUNT(*) FROM `queue_jobs`'
                . ' WHERE `composition_id` = ? AND `composition_position` = 1', [$handle->id])->fetchColumn());
            self::assertTrue((new Worker($queue))->workOnce());
            self::assertTrue((new Worker($queue))->workOnce());
            self::assertSame('completed', $handle->status()->state);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
            $manager->disconnect();
            $project->remove();
        }
    }
}
