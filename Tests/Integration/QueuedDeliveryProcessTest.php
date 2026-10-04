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

/** Enqueue and worker run in different PHP processes against one SQLite Queue. */
final class QueuedDeliveryProcessTest extends TestCase
{
    public function testSeparateProcessesDeliverNotificationAndMailJobs(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for process-boundary delivery.');
        }
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $database = new DatabaseManager(new Repository(['database' => [
            'default' => 'test', 'connections' => ['test' => [
                'driver' => 'sqlite', 'database' => $project->path('queue.sqlite'),
            ]],
        ]]));
        try {
            $project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
                . '["test"=>["driver"=>"sqlite","database"=>__DIR__."/../queue.sqlite"]]];');
            $project->write('Config/Queue.php', '<?php return ["default"=>"database","connections"=>'
                . '["database"=>["driver"=>"database","database_connection"=>"test"]]];');
            $project->write('Config/Mail.php', '<?php return ["default"=>"array",'
                . '"from"=>["address"=>"sender@example.test"],'
                . '"transports"=>["array"=>["driver"=>"array"]]];');
            $project->write('ProcessNotice.php', <<<'PHP'
<?php
final class ProcessNotice extends \App\Notifications\Notification implements \App\Notifications\ShouldQueue
{
    public function __construct(private string $marker) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toQueuePayload(): array { return ['marker' => $this->marker]; }
    public static function fromQueuePayload(array $payload): static { return new static($payload['marker']); }
    public function toMail(mixed $notifiable): \App\Mail\MailMessage
    {
        file_put_contents($this->marker, 'worker-ran');
        return (new \App\Mail\MailMessage())->subject('Notice')->text('delivered');
    }
}

final class ProcessFailNotice extends \App\Notifications\Notification implements \App\Notifications\ShouldQueue
{
    public function __construct(private string $secret) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toQueuePayload(): array { return ['secret' => $this->secret]; }
    public static function fromQueuePayload(array $payload): static { return new static($payload['secret']); }
    public function toMail(mixed $notifiable): \App\Mail\MailMessage
    {
        throw new \RuntimeException($this->secret);
    }
}
PHP);
            $bootstrap = '<?php require ' . var_export($root . '/vendor/autoload.php', true) . '; '
                . 'require ' . var_export($root . '/App/Core/Helper.php', true) . '; '
                . 'require __DIR__."/ProcessNotice.php"; '
                . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
                . 'foreach ([\\App\\Database\\DatabaseServiceProvider::class,'
                . '\\App\\Mail\\MailServiceProvider::class,'
                . '\\App\\Notifications\\NotificationServiceProvider::class,'
                . '\\App\\Queue\\QueueServiceProvider::class] as $provider) '
                . '$squehubApp->register($provider); $squehubApp->bootstrap(); ';
            $project->write('Enqueue.php', $bootstrap
                . '\\App\\Notifications\\Notifications::manager()->route("mail","person@example.test")'
                . '->send(new ProcessNotice(__DIR__."/delivered.txt")); '
                . '\\App\\Mail\\Mail::queue((new \\App\\Mail\\MailMessage())'
                . '->to("direct@example.test")->subject("Direct")->text("body"));');
            $project->write('CliRunner.php', $bootstrap
                . 'require ' . var_export($root . '/squehub', true) . ';');
            require_once $root . '/Database/Migrations/2026_09_24_create_queue_tables.php';
            (new \CreateQueueTables())->up($database->connection()->pdo(), $database->connection()->schema());

            $enqueue = new Process([PHP_BINARY, $project->path('Enqueue.php')], $root);
            $enqueue->run();
            self::assertSame(0, $enqueue->getExitCode(), $enqueue->getErrorOutput() . $enqueue->getOutput());
            self::assertFileDoesNotExist($project->path('delivered.txt'));
            self::assertSame(2, (int) $database->connection()->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());

            for ($i = 0; $i < 2; ++$i) {
                $worker = new Process([PHP_BINARY, $project->path('CliRunner.php'), 'queue:work', '--once'], $root);
                $worker->run();
                self::assertSame(0, $worker->getExitCode(), $worker->getErrorOutput() . $worker->getOutput());
                self::assertStringContainsString('Processed 1 Queue job(s).', $worker->getOutput());
            }
            self::assertSame('worker-ran', file_get_contents($project->path('delivered.txt')));
            self::assertSame(0, (int) $database->connection()->raw('SELECT COUNT(*) FROM `queue_jobs`')->fetchColumn());
            self::assertSame(0, (int) $database->connection()->raw('SELECT COUNT(*) FROM `queue_failed_jobs`')->fetchColumn());

            $secret = 'SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK';
            $failurePayload = json_encode(['version' => 1,
                'job' => \App\Notifications\Queue\DeliverNotification::class,
                'data' => ['version' => 1, 'notification' => 'ProcessFailNotice',
                    'data' => ['secret' => $secret], 'recipient' => ['type' => 'anonymous',
                        'mail' => ['address' => 'person@example.test', 'name' => null]]]],
                JSON_THROW_ON_ERROR);
            $database->connection()->raw('INSERT INTO `queue_jobs` '
                . '(`queue`,`payload`,`attempts`,`available_at`,`created_at`) VALUES (?,?,0,?,?)',
                ['default', $failurePayload, '2000-01-01 00:00:00', '2000-01-01 00:00:00']);
            $failureWorker = new Process([PHP_BINARY, $project->path('CliRunner.php'),
                'queue:work', '--once', '--tries=1'], $root);
            $failureWorker->run();
            self::assertSame(0, $failureWorker->getExitCode());
            self::assertStringNotContainsString($secret,
                $failureWorker->getErrorOutput() . $failureWorker->getOutput());
            $failure = $database->connection()->raw('SELECT * FROM `queue_failed_jobs`')->fetchAll();
            self::assertCount(1, $failure);
            self::assertStringNotContainsString($secret, json_encode($failure));
        } finally {
            $database->disconnect();
            $project->remove();
        }
    }
}
