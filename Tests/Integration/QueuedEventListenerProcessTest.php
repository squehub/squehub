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

/** A fresh worker process reconstructs a queued event without request memory. */
final class QueuedEventListenerProcessTest extends TestCase
{
    public function testSeparateEnqueueAndWorkerProcessesDeliverOneListener(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for queued listener process delivery.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $project->path('queue.sqlite'),
            ]],
        ]]));
        try {
            $project->write('Config/Database.php', '<?php return ["default"=>"test",'
                . '"connections"=>["test"=>["driver"=>"sqlite",'
                . '"database"=>__DIR__."/../queue.sqlite"]]];');
            $project->write('Config/Queue.php', '<?php return ["default"=>"database",'
                . '"connections"=>["database"=>["driver"=>"database",'
                . '"database_connection"=>"test"]]];');
            $project->write('ProcessEvent.php', <<<'PHP'
<?php
final class ProcessQueuedEvent implements \App\Events\QueueableEvent
{
    public function __construct(public string $marker) {}
    public function toQueuePayload(): array { return ['marker' => $this->marker]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) ($payload['marker'] ?? ''));
    }
}
final class ProcessQueuedListener
{
    public function handle(ProcessQueuedEvent $event): void
    {
        file_put_contents($event->marker, 'worker-ran');
    }
}
final class ProcessFailQueuedListener
{
    public function handle(ProcessQueuedEvent $event): void
    {
        throw new \RuntimeException($event->marker);
    }
}
PHP);
            $bootstrap = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; '
                . 'require __DIR__."/ProcessEvent.php"; '
                . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
                . 'foreach ([\\App\\Events\\EventServiceProvider::class,'
                . '\\App\\Database\\DatabaseServiceProvider::class,'
                . '\\App\\Queue\\QueueServiceProvider::class] as $provider) '
                . '$squehubApp->register($provider); $squehubApp->bootstrap(); ';
            $project->write('Enqueue.php', $bootstrap
                . '$events=$squehubApp->container()->make(\\App\\Events\\EventDispatcher::class); '
                . '$events->listenQueued(ProcessQueuedEvent::class, ProcessQueuedListener::class); '
                . '$events->emit(new ProcessQueuedEvent(__DIR__."/delivered.txt"));');
            $project->write('CliRunner.php', $bootstrap
                . 'require ' . var_export($root . '/squehub', true) . ';');
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            (new \CreateQueueTables())->up($database->connection()->pdo(),
                $database->connection()->schema());

            $enqueue = new Process([PHP_BINARY, $project->path('Enqueue.php')], $root);
            $enqueue->run();
            self::assertSame(0, $enqueue->getExitCode(), $enqueue->getErrorOutput() . $enqueue->getOutput());
            self::assertFileDoesNotExist($project->path('delivered.txt'));
            self::assertSame(1, (int) $database->connection()->raw(
                'SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());

            $worker = new Process([PHP_BINARY, $project->path('CliRunner.php'),
                'queue:work', '--once'], $root);
            $worker->run();
            self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
            self::assertSame('worker-ran', file_get_contents($project->path('delivered.txt')));
            self::assertSame(0, (int) $database->connection()->raw(
                'SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());

            $secret = 'SQUEHUB_QUEUED_EVENT_SECRET_DO_NOT_LEAK';
            $project->write('EnqueueFailure.php', $bootstrap
                . '$events=$squehubApp->container()->make(\\App\\Events\\EventDispatcher::class); '
                . '$events->listenQueued(ProcessQueuedEvent::class, ProcessFailQueuedListener::class); '
                . '$events->emit(new ProcessQueuedEvent(' . var_export($secret, true) . '));');
            $failureEnqueue = new Process([PHP_BINARY, $project->path('EnqueueFailure.php')], $root);
            $failureEnqueue->run();
            self::assertSame(0, $failureEnqueue->getExitCode(),
                $failureEnqueue->getErrorOutput() . $failureEnqueue->getOutput());
            $failureWorker = new Process([PHP_BINARY, $project->path('CliRunner.php'),
                'queue:work', '--once', '--tries=1'], $root);
            $failureWorker->run();
            self::assertSame(0, $failureWorker->getExitCode());
            self::assertStringNotContainsString($secret,
                $failureWorker->getErrorOutput() . $failureWorker->getOutput());
            $failures = $database->connection()->raw(
                'SELECT `error_type`, `error_message` FROM `queue_failed_jobs`')->fetchAll();
            self::assertCount(1, $failures);
            self::assertStringNotContainsString($secret, json_encode($failures));
        } finally {
            $database->disconnect();
            $project->remove();
        }
    }
}
