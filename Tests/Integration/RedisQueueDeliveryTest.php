<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\Request;
use App\Mail\Mail;
use App\Mail\Mailer;
use App\Mail\MailServiceProvider;
use App\Notifications\NotificationServiceProvider;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications;
use App\Plugins;
use App\Queue\Queue;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use App\Redis\RedisClient;
use App\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\FakeRedisQueueClient;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/FakeRedisQueueClient.php';
require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Queueable Notification exercises the existing channel and Mail path. */
final class RedisQueueNotice extends Plugins\Notification implements Plugins\ShouldQueue
{
    public function __construct(private string $body) {}
    public function via(mixed $notifiable): array { return ['mail']; }
    public function toMail(mixed $notifiable): Plugins\MailMessage
    {
        return (new Plugins\MailMessage())->subject('Queued notice')->text($this->body);
    }
    public function toQueuePayload(): array { return ['body' => $this->body]; }
    public static function fromQueuePayload(array $payload): static
    {
        return new static((string) $payload['body']);
    }
}

/** Redis selection leaves existing Mail and Notification APIs unchanged. */
final class RedisQueueDeliveryTest extends TestCase
{
    public function testQueuedMailAndNotificationUseExistingWorkerAndDiagnostics(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Queue.php', '<?php return ["default"=>"redis","connections"=>'
                . '["redis"=>["driver"=>"redis","namespace"=>"delivery-test"]]];');
            $project->write('Config/Mail.php', '<?php return ["default"=>"array",'
                . '"from"=>["address"=>"sender@example.test"],'
                . '"transports"=>["array"=>["driver"=>"array"]]];');
            $client = new FakeRedisQueueClient();
            $redis = new RedisManager(['default' => 'main', 'connections' => [
                'main' => ['host' => 'localhost', 'prefix' => 'test-delivery:'],
            ]], null, static fn (): RedisClient => $client,
                static fn (string $class): bool => $class === \Redis::class);
            $app = new Application($project->path());
            $app->container()->instance(RedisManager::class, $redis);
            foreach ([DiagnosticsServiceProvider::class, MailServiceProvider::class,
                NotificationServiceProvider::class, QueueServiceProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            $app->container()->make(Diagnostics::class)->begin(new Request('GET', '/queued'));
            $mailer = $app->container()->make(Mailer::class);
            $worker = $app->container()->make(Worker::class);
            $message = (new Plugins\MailMessage())->to('user@example.test')
                ->subject('Welcome')->text('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK');
            Plugins\Mail::queue($message);
            self::assertSame([], $mailer->transport()->messages());
            self::assertTrue($worker->workOnce());
            self::assertCount(1, $mailer->transport()->messages());

            $manager = $app->container()->make(NotificationManager::class);
            $manager->route('mail', 'other@example.test')
                ->send(new RedisQueueNotice('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK'));
            self::assertCount(1, $mailer->transport()->messages());
            self::assertTrue($worker->workOnce());
            self::assertCount(2, $mailer->transport()->messages());
            $snapshot = $app->container()->make(Diagnostics::class)->snapshot();
            self::assertSame(2, $snapshot['queue']['dispatched']);
            self::assertSame(2, $snapshot['queue']['processed']);
            self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', json_encode($snapshot));
        } finally {
            Queue::setResolver(null);
            Mail::setResolver(null);
            Notifications::setResolver(null);
            $project->remove();
        }
    }
}
