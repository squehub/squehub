<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\DatabaseManager;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\CliRetryJob;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The real Console command processes a disposable, persisted SQLite job. */
final class QueueWorkerCliTest extends TestCase
{
    public function testFailedJobCanBeListedRetriedAndCompletedAcrossProcesses(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue worker CLI.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = $project->path('queue.sqlite');
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $database]],
        ]]));
        try {
            $project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
                . '["test"=>["driver"=>"sqlite","database"=>__DIR__."/../queue.sqlite"]]];');
            $project->write('Config/Queue.php', '<?php return ["default"=>"database","connections"=>'
                . '["database"=>["driver"=>"database","database_connection"=>"test"]]];');
            // The same real job class is loaded on both sides of the process boundary.
            self::assertTrue(copy(dirname(__DIR__) . '/Fixtures/CliRetryJob.php', $project->path('CliRetryJob.php')));
            $runner = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; '
                . 'require __DIR__."/CliRetryJob.php"; '
                . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
                . '$squehubApp->register(\\App\\Database\\DatabaseServiceProvider::class); '
                . '$squehubApp->register(\\App\\Queue\\QueueServiceProvider::class); '
                . '$squehubApp->bootstrap(); require ' . var_export($root . '/squehub', true) . ';';
            $project->write('CliRunner.php', $runner);
            $connection = $manager->connection();
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            require_once $root . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            (new \AddFailedQueuePayload())->up($connection->pdo(), $connection->schema());
            file_put_contents($project->path('fail.marker'), 'x');
            require_once $project->path('CliRetryJob.php');
            $payload = \App\Queue\QueueCodec::encode(new CliRetryJob($project->path()));
            $connection->raw('INSERT INTO `queue_jobs` (`queue`,`payload`,`attempts`,`available_at`,`created_at`)'
                . ' VALUES (?,?,0,?,?)', ['default', $payload, '2000-01-01 00:00:00', '2000-01-01 00:00:00']);
            $run = static function (array $arguments) use ($project, $root): Process {
                $process = new Process(array_merge([PHP_BINARY, $project->path('CliRunner.php')], $arguments), $root);
                $process->run();
                return $process;
            };
            $queued = $run(['queue:status']);
            self::assertSame(0, $queued->getExitCode(), $queued->getErrorOutput());
            self::assertStringContainsString('Ready: 1', $queued->getOutput());
            self::assertStringContainsString('Failed (all queues): 0', $queued->getOutput());
            $failedWorker = $run(['queue:work', '--once', '--tries=1']);
            self::assertSame(0, $failedWorker->getExitCode(), $failedWorker->getOutput());
            $id = (int) $connection->raw('SELECT `id` FROM `queue_failed_jobs`')->fetchColumn();
            self::assertGreaterThan(0, $id);
            $failedStatus = $run(['queue:status']);
            self::assertSame(0, $failedStatus->getExitCode());
            self::assertStringContainsString('Failed (all queues): 1', $failedStatus->getOutput());
            self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK',
                $failedStatus->getOutput() . $failedStatus->getErrorOutput());
            $listing = $run(['queue:failed']);
            self::assertSame(0, $listing->getExitCode());
            self::assertStringContainsString('CliRetryJob', $listing->getOutput());
            self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK',
                $listing->getOutput() . $listing->getErrorOutput());
            self::assertNotSame(0, $run(['queue:retry', 'not-an-id'])->getExitCode());
            unlink($project->path('fail.marker'));
            $retry = $run(['queue:retry', (string) $id]);
            self::assertSame(0, $retry->getExitCode(), $retry->getOutput());
            self::assertNotSame(0, $run(['queue:retry', (string) $id])->getExitCode());
            self::assertSame(0, (int) $connection->raw('SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());
            self::assertSame(0, $run(['queue:work', '--once'])->getExitCode());
            self::assertSame('done', file_get_contents($project->path('done.marker')));
            self::assertSame(0, (int) $connection->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
            $empty = $run(['queue:work', '--stop-when-empty', '--sleep=10']);
            self::assertSame(0, $empty->getExitCode(), $empty->getErrorOutput());
            self::assertStringContainsString('Worker exit: empty.', $empty->getOutput());
            $restart = $run(['queue:restart']);
            self::assertSame(0, $restart->getExitCode(), $restart->getOutput());
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/',
                (string) file_get_contents($project->path('Storage/Queue/Restart.token')));
            $idleWorker = new Process([PHP_BINARY, $project->path('CliRunner.php'),
                'queue:work', '--max-time=5', '--sleep=2'], $root);
            $idleWorker->start();
            // The worker has no ready event; a bounded runtime prevents a
            // stalled subprocess from holding the test indefinitely.
            usleep(750000);
            self::assertTrue($idleWorker->isRunning());
            self::assertSame(0, $run(['queue:restart'])->getExitCode());
            $idleWorker->wait();
            self::assertSame(0, $idleWorker->getExitCode(), $idleWorker->getErrorOutput());
            self::assertStringContainsString('Worker exit: restart.', $idleWorker->getOutput());
            self::assertSame(0, $run(['queue:prune', '--hours=168'])->getExitCode());
            self::assertNotSame(0, $run(['queue:forget', (string) $id])->getExitCode());
        } finally {
            $manager->disconnect();
            $project->remove();
        }
    }

    public function testOnceRunsOneJobAndLeavesNoActiveRecord(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Queue worker CLI.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = $project->path('queue.sqlite');
        $done = $project->path('done.txt');
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => $database]],
        ]]));
        try {
            $project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
                . '["test"=>["driver"=>"sqlite","database"=>__DIR__."/../queue.sqlite"]]];');
            $project->write('Config/Queue.php', '<?php return ["default"=>"database","connections"=>'
                . '["database"=>["driver"=>"database","database_connection"=>"test"]]];');
            $project->write('QueueCliJob.php', <<<'PHP'
<?php
final class QueueCliJob implements \App\Queue\QueueJob
{
    public function __construct(private string $output) {}
    public function handle(): void { file_put_contents($this->output, 'done'); }
    public function toQueuePayload(): array { return ['output' => $this->output]; }
    public static function fromQueuePayload(array $payload): static { return new static($payload['output']); }
}
PHP);
            $runner = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; '
                . 'require __DIR__."/QueueCliJob.php"; '
                . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
                . '$squehubApp->register(\\App\\Database\\DatabaseServiceProvider::class); '
                . '$squehubApp->register(\\App\\Queue\\QueueServiceProvider::class); '
                . '$squehubApp->bootstrap(); require ' . var_export($root . '/squehub', true) . ';';
            $project->write('CliRunner.php', $runner);
            $connection = $manager->connection();
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            (new \CreateQueueTables())->up($connection->pdo(), $connection->schema());
            $payload = json_encode(['version' => 1, 'job' => 'QueueCliJob', 'data' => ['output' => $done]],
                JSON_THROW_ON_ERROR);
            $connection->raw('INSERT INTO `queue_jobs` (`queue`,`payload`,`attempts`,`available_at`,`created_at`)'
                . ' VALUES (?,?,0,?,?)', ['default', $payload, '2000-01-01 00:00:00', '2000-01-01 00:00:00']);
            $process = new Process([PHP_BINARY, $project->path('CliRunner.php'), 'queue:work', '--once'], $root);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            self::assertStringContainsString('Processed 1 Queue job(s).', $process->getOutput());
            self::assertSame('done', file_get_contents($done));
            self::assertSame(0, (int) $connection->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
        } finally {
            $manager->disconnect();
            $project->remove();
        }
    }
}
