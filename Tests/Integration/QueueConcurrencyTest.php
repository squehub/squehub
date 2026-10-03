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

/** Two independent PHP workers race for one SQLite row behind a barrier. */
final class QueueConcurrencyTest extends TestCase
{
    public function testTwoProcessesCannotClaimOneJob(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue concurrency.');
        }
        $project = new TemporaryProject();
        $database = $project->path('queue.sqlite');
        $barrier = $project->path('claim');
        $root = dirname(__DIR__, 2);
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $database]],
        ]]));
        $processes = [];
        try {
            $connection = $manager->connection();
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            $connection->raw('INSERT INTO `queue_jobs` (`queue`,`payload`,`attempts`,`available_at`,`created_at`)'
                . ' VALUES (?,?,0,?,?)', ['default', '{}', '2000-01-01 00:00:00', '2000-01-01 00:00:00']);
            $project->write('Claim.php', <<<'PHP'
<?php
require $argv[1];
$db = new \App\Database\DatabaseManager(new \App\Config\Repository(['database' => [
    'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $argv[2]]],
]]));
$driver = new \App\Queue\Drivers\DatabaseQueueDriver($db->connection(),
    new \App\Database\SystemModelClock());
file_put_contents($argv[3] . '.' . $argv[4], 'ready');
$deadline = microtime(true) + 10;
while (!is_file($argv[3] . '.go')) {
    if (microtime(true) > $deadline) exit(3);
    usleep(10000);
}
try {
    echo $driver->reserve('default') === null ? 'EMPTY' : 'CLAIM';
} catch (Throwable) {
    echo 'ERROR';
    exit(2);
}
PHP);
            foreach (['a', 'b'] as $worker) {
                $process = new Process([PHP_BINARY, $project->path('Claim.php'),
                    $root . '/vendor/autoload.php', $database, $barrier, $worker], $root);
                $process->start();
                $processes[$worker] = $process;
            }
            $deadline = microtime(true) + 10;
            while (!is_file($barrier . '.a') || !is_file($barrier . '.b')) {
                if (microtime(true) > $deadline) self::fail('Queue workers did not reach the barrier.');
                usleep(10000);
            }
            file_put_contents($barrier . '.go', 'go');
            $outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $outputs[] = $process->getOutput();
            }
            sort($outputs);
            self::assertSame(['CLAIM', 'EMPTY'], $outputs);
            self::assertSame(1, (int) $connection->raw('SELECT `attempts` FROM `queue_jobs`')->fetchColumn());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) $process->stop(1);
            }
            $manager->disconnect();
            $project->remove();
        }
    }
}
