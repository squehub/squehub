<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Two barrier-coordinated PHP processes contend for one SQLite occurrence. */
final class SchedulerConcurrencyTest extends TestCase
{
    public function testExactlyOneProcessClaimsAndExecutesOccurrence(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Scheduler concurrency.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = $project->path('schedule.sqlite');
        $barrier = $project->path('race');
        $output = $project->path('executed.txt');
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $database]],
        ]]));
        $processes = [];
        try {
            $connection = $manager->connection();
            require_once $root . '/Database/Migrations/2026_09_24_create_schedule_tables.php';
            (new \CreateScheduleTables())->up($connection->pdo(), $connection->schema());
            $project->write('Race.php', <<<'PHP'
<?php
require $argv[1];
$database = new \App\Database\DatabaseManager(new \App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $argv[2]]],
]]));
$scheduler = new \App\Scheduler\Scheduler(new \App\Container\Container(),
    ['store' => 'database', 'prefix' => 'race', 'timezone' => 'UTC',
     'database_connection' => 'test'],
    fn (?string $name) => $database->connection($name));
$scheduler->call(static function () use ($argv): void {
    file_put_contents($argv[4], 'X', FILE_APPEND);
})->name('single-occurrence')->everyMinute();
file_put_contents($argv[3] . '.' . $argv[5], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[3] . '.go')) {
    if (microtime(true) > $deadline) exit(3);
    usleep(10000);
}
$result = $scheduler->run(new DateTimeImmutable('2026-09-24 12:00:00', new DateTimeZone('UTC')));
echo $result->executed . ':' . $result->skipped . ':' . $result->failed;
PHP);
            foreach (['a', 'b'] as $name) {
                $process = new Process([PHP_BINARY, $project->path('Race.php'),
                    $root . '/vendor/autoload.php', $database, $barrier, $output, $name], $root);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (!is_file($barrier . '.a') || !is_file($barrier . '.b')) {
                if (microtime(true) > $deadline) self::fail('Scheduler processes did not reach the barrier.');
                usleep(10000);
            }
            file_put_contents($barrier . '.go', 'go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = $process->getOutput();
            }
            sort($results);
            self::assertSame(['0:1:0', '1:0:0'], $results);
            self::assertSame('X', file_get_contents($output));
            self::assertSame(1, (int) $connection->raw('SELECT COUNT(*) FROM `schedule_runs`')->fetchColumn());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
            $manager->disconnect();
            $project->remove();
        }
    }
}
