<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\Request;
use App\Mail\Mail;
use App\Mail\MailConfigurationException;
use App\Mail\Mailer;
use App\Notifications\NotificationException;
use App\Notifications\NotificationServiceProvider;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications;
use App\Mail\MailServiceProvider;
use App\Plugins;
use App\Queue\Queue;
use App\Queue\QueueCodec;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** One explicit identity; the worker sees the latest route at execution. */
final class QueuedRecipient implements Plugins\QueueNotifiable
{
    use Plugins\Notifiable;

    public static array $routes = [];

    public function __construct(public string $id) {}
    public function routeNotificationForMail(): string { return self::$routes[$this->id]; }
    public function notificationQueueIdentity(): array { return ['id' => $this->id]; }
    public static function resolveNotificationQueueIdentity(array $identity): static
    {
        $id = $identity['id'] ?? null;
        if (!is_string($id) || !isset(self::$routes[$id])) {
            throw new \RuntimeException('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK');
        }
        return new static($id);
    }
}

/** A JSON-safe notification with an optional Queue connection for sync tests. */
final class QueuedNotice extends Plugins\Notification implements Plugins\ShouldQueue
{
    public function __construct(private string $body, private ?string $connection = null,
        private int $delay = 0, private bool $afterCommit = false) {}
    public function via(mixed $notifiable): array { return ['array', 'mail']; }
    public function toMail(mixed $notifiable): Plugins\MailMessage
    {
        return (new Plugins\MailMessage())->subject('Queued')->text($this->body);
    }
    public function toQueuePayload(): array { return ['body' => $this->body]; }
    public static function fromQueuePayload(array $payload): static
    {
        if (!is_string($payload['body'] ?? null)) throw new \RuntimeException('Invalid test payload.');
        return new static($payload['body']);
    }
    public function queueConnection(): ?string { return $this->connection; }
    public function queueName(): string { return 'notices'; }
    public function queueDelay(): int { return $this->delay; }
    public function queueAfterCommit(): bool { return $this->afterCommit; }
}

/** Real SQLite queue records and normal Array Mail transport, with no SMTP. */
final class QueuedDeliveryTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private DatabaseManager $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for queued delivery integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default"=>"test","connections"=>'
            . '["test"=>["driver"=>"sqlite","database"=>__DIR__."/../queue.sqlite"]]];');
        $this->project->write('Config/Queue.php', '<?php return ["default"=>"database","connections"=>'
            . '["database"=>["driver"=>"database","database_connection"=>"test","retry_after"=>30],'
            . '"sync"=>["driver"=>"sync"]]];');
        $this->project->write('Config/Mail.php', '<?php return ["default"=>"array",'
            . '"from"=>["address"=>"sender@example.test"],'
            . '"transports"=>["array"=>["driver"=>"array"],"other"=>["driver"=>"array"]]];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            MailServiceProvider::class, NotificationServiceProvider::class,
            QueueServiceProvider::class] as $provider) $this->app->register($provider);
        $this->app->bootstrap();
        $this->database = $this->app->container()->make(DatabaseManager::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
        $this->app->container()->make(Diagnostics::class)->begin(new Request('GET', '/queued'));
        QueuedRecipient::$routes = ['1' => 'old@example.test'];
    }

    protected function tearDown(): void
    {
        Queue::setResolver(null);
        Mail::setResolver(null);
        Notifications::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private function mailer(): Mailer { return $this->app->container()->make(Mailer::class); }
    private function worker(): Worker { return $this->app->container()->make(Worker::class); }
    private function rowCount(string $table): int
    {
        return (int) $this->database->connection()->raw('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    public function testQueuedMailAfterCommitWaitsAndRollbackDiscardsIt(): void
    {
        $connection = $this->database->connection();
        $message = (new Plugins\MailMessage())->to('user@example.test')
            ->subject('deferred')->text('body');
        $connection->begin();
        Plugins\Mail::queue($message, queue: 'mail', afterCommit: true);
        self::assertSame(0, $this->rowCount('queue_jobs'));
        $connection->rollback();
        self::assertSame(0, $this->rowCount('queue_jobs'));
        $connection->transaction(function () use ($message): void {
            Plugins\Mail::queue($message, queue: 'mail', afterCommit: true);
            self::assertSame(0, $this->rowCount('queue_jobs'));
        });
        self::assertSame(1, $this->rowCount('queue_jobs'));
        self::assertSame([], $this->mailer()->transport()->messages());
        self::assertTrue($this->worker()->workOnce('mail'));
        self::assertCount(1, $this->mailer()->transport()->messages());
    }

    public function testQueuedNotificationAfterCommitUsesOuterTransactionAndDiagnostics(): void
    {
        $connection = $this->database->connection();
        $diagnostics = $this->app->container()->make(Diagnostics::class);
        $connection->begin();
        $connection->begin();
        (new QueuedRecipient('1'))->notify(new QueuedNotice('secret', afterCommit: true));
        $connection->commit();
        self::assertSame(0, $this->rowCount('queue_jobs'));
        self::assertSame(0, $diagnostics->snapshot()['notifications']['queued']);
        $connection->commit();
        self::assertSame(1, $this->rowCount('queue_jobs'));
        self::assertSame(1, $diagnostics->snapshot()['notifications']['queued']);
        self::assertTrue($this->worker()->workOnce('notices'));
        self::assertCount(1, $this->mailer()->transport()->messages());
        $connection->begin();
        (new QueuedRecipient('1'))->notify(new QueuedNotice('rollback', afterCommit: true));
        $connection->rollback();
        self::assertSame(0, $this->rowCount('queue_jobs'));
    }

    public function testQueuedMailDefersDeliveryAndPreservesNamedTransport(): void
    {
        $secret = 'SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK';
        Plugins\Mail::queue((new Plugins\MailMessage())->to('user@example.test')
            ->subject($secret)->text($secret), connection: 'database', queue: 'mail', via: 'other');
        self::assertSame(1, $this->rowCount('queue_jobs'));
        self::assertSame([], $this->mailer()->transport('other')->messages());
        self::assertTrue($this->worker()->workOnce('mail', 'database'));
        self::assertSame(0, $this->rowCount('queue_jobs'));
        self::assertCount(1, $this->mailer()->transport('other')->messages());
        $stats = $this->app->container()->make(Diagnostics::class)->snapshot();
        self::assertSame(1, $stats['queue']['dispatched']);
        self::assertSame(1, $stats['queue']['processed']);
        self::assertSame(1, $stats['mail']['attempts']);
        self::assertSame(1, $stats['mail']['sent']);
        self::assertStringNotContainsString($secret, json_encode($stats));
    }

    public function testQueuedNotificationRestoresCurrentObjectRouteAndChannels(): void
    {
        $recipient = new QueuedRecipient('1');
        $recipient->notify(new QueuedNotice('private-token'));
        $manager = $this->app->container()->make(NotificationManager::class);
        self::assertSame([], $this->mailer()->transport()->messages());
        self::assertSame([], $manager->channel('array')->notifications());
        QueuedRecipient::$routes['1'] = 'new@example.test';
        self::assertTrue($this->worker()->workOnce('notices', 'database'));
        self::assertSame('new@example.test', $this->mailer()->transport()->messages()[0]
            ->recipients()[0]->address());
        self::assertSame([QueuedNotice::class], $manager->channel('array')->notifications());
        $stats = $this->app->container()->make(Diagnostics::class)->snapshot();
        self::assertSame(1, $stats['notifications']['attempts']);
        self::assertSame(1, $stats['notifications']['queued']);
        self::assertSame(1, $stats['notifications']['sent']);
        self::assertSame(2, $stats['notifications']['channel_deliveries']);
        self::assertStringNotContainsString('private-token', json_encode($stats));
    }

    public function testAnonymousRouteIsCapturedAndSyncQueueExecutesImmediately(): void
    {
        $manager = $this->app->container()->make(NotificationManager::class);
        $manager->route('mail', 'anonymous@example.test')->send(new QueuedNotice('body'));
        self::assertSame([], $this->mailer()->transport()->messages());
        self::assertTrue($this->worker()->workOnce('notices', 'database'));
        self::assertSame('anonymous@example.test', $this->mailer()->transport()->messages()[0]
            ->recipients()[0]->address());
        Plugins\Mail::queue((new Plugins\MailMessage())->to('sync@example.test')
            ->subject('sync')->text('body'), connection: 'sync', delay: 60);
        self::assertCount(2, $this->mailer()->transport()->messages());
        (new QueuedRecipient('1'))->notify(new QueuedNotice('sync notice', 'sync'));
        self::assertCount(3, $this->mailer()->transport()->messages());
    }

    public function testMissingRecipientRetriesThenFailsWithoutSecretMetadata(): void
    {
        (new QueuedRecipient('1'))->notify(new QueuedNotice('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK'));
        QueuedRecipient::$routes = [];
        self::assertTrue($this->worker()->workOnce('notices', 'database', tries: 2, backoff: 0));
        self::assertTrue($this->worker()->workOnce('notices', 'database', tries: 2, backoff: 0));
        self::assertSame(0, $this->rowCount('queue_jobs'));
        self::assertSame(1, $this->rowCount('queue_failed_jobs'));
        $failed = $this->database->connection()->raw('SELECT * FROM `queue_failed_jobs`')->fetchAll();
        self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', json_encode($failed));
    }

    public function testQueuedMailRejectsAttachmentsAndOversizedPayload(): void
    {
        try {
            Plugins\Mail::queue((new Plugins\MailMessage())->to('a@example.test')
                ->subject('subject')->text('body')->attachBytes('bytes', 'a.txt'));
            self::fail('Attachment accepted.');
        } catch (MailConfigurationException $failure) {
            self::assertSame('Queued Mail does not support attachments.', $failure->getMessage());
        }
        self::assertSame(0, $this->rowCount('queue_jobs'));
        try {
            Plugins\Mail::queue((new Plugins\MailMessage())->to('a@example.test')
                ->subject('subject')->text(str_repeat('x', 60000)));
            self::fail('Oversized message accepted.');
        } catch (\App\Queue\QueueException $failure) {
            self::assertStringNotContainsString('xxxxx', $failure->getMessage());
        }
        self::assertSame(0, $this->rowCount('queue_jobs'));
    }

    public function testUnknownPayloadVersionFailsBeforeDelivery(): void
    {
        $job = new \App\Mail\Queue\SendMailMessage(['version' => 100], null);
        $decoded = QueueCodec::decode(QueueCodec::encode($job));
        try { $decoded->handle(); self::fail('Unknown Mail payload version accepted.'); }
        catch (MailConfigurationException) { self::assertSame([], $this->mailer()->transport()->messages()); }
        $encoded = \App\Mail\Queue\MailMessagePayload::encode((new Plugins\MailMessage())
            ->to('a@example.test')->subject('valid')->text('body'));
        $unknownMail = \App\Mail\Queue\SendMailMessage::fromQueuePayload([
            'version' => 9, 'message' => $encoded, 'via' => null,
        ]);
        try { $unknownMail->handle(); self::fail('Unknown Mail job version accepted.'); }
        catch (MailConfigurationException $failure) {
            self::assertSame('Queued Mail job version is unsupported.', $failure->getMessage());
        }
        $encoded['attachments'] = [['bytes' => 'private']];
        try { \App\Mail\Queue\MailMessagePayload::decode($encoded);
            self::fail('Unknown attachment field was dropped.'); }
        catch (MailConfigurationException) { self::assertSame([], $this->mailer()->transport()->messages()); }
        $unknown = \App\Notifications\Queue\DeliverNotification::fromQueuePayload([
            'version' => 9, 'notification' => QueuedNotice::class, 'data' => ['body' => 'hidden'],
            'recipient' => ['type' => 'anonymous', 'mail' => ['address' => 'a@example.test']],
        ]);
        try { $unknown->handle(); self::fail('Unknown Notification job version accepted.'); }
        catch (NotificationException $failure) {
            self::assertSame('Queued notification job version is unsupported.', $failure->getMessage());
            self::assertSame([], $this->mailer()->transport()->messages());
        }
    }

    public function testNotificationDelayAndQueueNameUseTheExistingQueuePolicy(): void
    {
        (new QueuedRecipient('1'))->notify(new QueuedNotice('later', delay: 120));
        $row = $this->database->connection()->raw(
            'SELECT `queue`, `available_at`, `created_at` FROM `queue_jobs`')->fetch();
        self::assertSame('notices', $row['queue']);
        self::assertGreaterThan($row['created_at'], $row['available_at']);
        self::assertFalse($this->worker()->workOnce('notices', 'database'));
        self::assertSame([], $this->mailer()->transport()->messages());
    }

    public function testPartialNotificationDeliveryCanRepeatBeforeSafeFailure(): void
    {
        (new QueuedRecipient('1'))->notify(new QueuedNotice('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK'));
        QueuedRecipient::$routes['1'] = 'not-an-email';
        $manager = $this->app->container()->make(NotificationManager::class);
        self::assertTrue($this->worker()->workOnce('notices', 'database', tries: 2, backoff: 0));
        self::assertTrue($this->worker()->workOnce('notices', 'database', tries: 2, backoff: 0));
        self::assertSame([QueuedNotice::class, QueuedNotice::class],
            $manager->channel('array')->notifications());
        self::assertSame([], $this->mailer()->transport()->messages());
        self::assertSame(1, $this->rowCount('queue_failed_jobs'));
        $failed = $this->database->connection()->raw('SELECT * FROM `queue_failed_jobs`')->fetchAll();
        self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', json_encode($failed));
        self::assertStringNotContainsString('not-an-email', json_encode($failed));
    }

    public function testMailWorkerFailureRetriesAndStoresNoMessageContent(): void
    {
        $message = (new Plugins\MailMessage())->to('user@example.test')
            ->subject('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK')->text('body');
        $job = new \App\Mail\Queue\SendMailMessage(
            \App\Mail\Queue\MailMessagePayload::encode($message), 'missing');
        $this->app->container()->make(\App\Queue\QueueManager::class)
            ->dispatch($job, connection: 'database');
        self::assertTrue($this->worker()->workOnce('default', 'database', tries: 2, backoff: 0));
        self::assertTrue($this->worker()->workOnce('default', 'database', tries: 2, backoff: 0));
        self::assertSame(1, $this->rowCount('queue_failed_jobs'));
        $failed = $this->database->connection()->raw('SELECT * FROM `queue_failed_jobs`')->fetchAll();
        self::assertStringNotContainsString('SQUEHUB_QUEUE_SECRET_DO_NOT_LEAK', json_encode($failed));
        self::assertSame([], $this->mailer()->transport()->messages());
    }

    public function testMissingQueueAndPersistenceFailureDoNotReportAcceptedNotification(): void
    {
        $diagnostics = $this->app->container()->make(Diagnostics::class);
        $manager = new NotificationManager($diagnostics);
        try {
            $manager->send(new QueuedRecipient('1'), new QueuedNotice('body'));
            self::fail('Missing Queue service accepted a queued Notification.');
        } catch (NotificationException $failure) {
            self::assertSame('Queue service is unavailable for notifications.', $failure->getMessage());
        }
        $this->database->schema()->drop('queue_jobs');
        try {
            (new QueuedRecipient('1'))->notify(new QueuedNotice('body'));
            self::fail('Missing Queue table accepted a queued Notification.');
        } catch (NotificationException $failure) {
            self::assertSame('Notification enqueue failed.', $failure->getMessage());
        }
        self::assertSame(0, $diagnostics->snapshot()['notifications']['queued']);
        self::assertSame(0, $diagnostics->snapshot()['notifications']['attempts']);
    }

    public function testQueuedMailValidatesBeforePersistenceAndDatabaseFailurePropagates(): void
    {
        try {
            Plugins\Mail::queue((new Plugins\MailMessage())->to('a@example.test')->text('body'));
            self::fail('Incomplete Mail accepted.');
        } catch (MailConfigurationException) {
            self::assertSame(0, $this->rowCount('queue_jobs'));
        }
        $this->database->schema()->drop('queue_jobs');
        $this->expectException(\App\Database\Exception\QueryException::class);
        Plugins\Mail::queue((new Plugins\MailMessage())->to('a@example.test')
            ->subject('subject')->text('body'));
    }

    public function testInvalidNotificationClassAndOversizedPayloadFailSafely(): void
    {
        $job = \App\Notifications\Queue\DeliverNotification::fromQueuePayload([
            'version' => 1, 'notification' => \stdClass::class, 'data' => [],
            'recipient' => ['type' => 'anonymous', 'mail' => ['address' => 'a@example.test']],
        ]);
        try { $job->handle(); self::fail('Non-notification class accepted.'); }
        catch (NotificationException $failure) {
            self::assertSame('Queued notification class is invalid.', $failure->getMessage());
        }
        try {
            (new QueuedRecipient('1'))->notify(new QueuedNotice(str_repeat('x', 60000)));
            self::fail('Oversized Notification accepted.');
        } catch (NotificationException $failure) {
            self::assertSame('Notification enqueue failed.', $failure->getMessage());
            self::assertStringNotContainsString('xxxxx', $failure->getMessage());
        }
        self::assertSame(0, $this->rowCount('queue_jobs'));
    }

    public function testSyncDeliveryFailureCountsOneNotificationFailure(): void
    {
        QueuedRecipient::$routes['1'] = 'invalid-address';
        try {
            (new QueuedRecipient('1'))->notify(new QueuedNotice('body', 'sync'));
            self::fail('Invalid Mail route accepted.');
        } catch (NotificationException) {
            $stats = $this->app->container()->make(Diagnostics::class)->snapshot();
            self::assertSame(1, $stats['notifications']['failures']);
            self::assertSame(0, $stats['notifications']['queued']);
            self::assertSame(0, $stats['notifications']['attempts']);
        }
    }

    public function testUnknownInternalVersionUsesNormalQueueRetryAndFailure(): void
    {
        $unknown = \App\Notifications\Queue\DeliverNotification::fromQueuePayload([
            'version' => 9, 'notification' => QueuedNotice::class, 'data' => ['body' => 'private'],
            'recipient' => ['type' => 'anonymous', 'mail' => ['address' => 'a@example.test']],
        ]);
        $this->app->container()->make(\App\Queue\QueueManager::class)
            ->dispatch($unknown, connection: 'database');
        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(1, $this->rowCount('queue_jobs'));
        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(0, $this->rowCount('queue_jobs'));
        self::assertSame(1, $this->rowCount('queue_failed_jobs'));
        self::assertSame([], $this->mailer()->transport()->messages());
    }

    public function testSyncQueueKeepsTheOriginatingApplicationServices(): void
    {
        $firstMailer = $this->mailer();
        $firstNotifications = $this->app->container()->make(NotificationManager::class);
        $firstMailer->queue((new Plugins\MailMessage())->to('deferred@example.test')
            ->subject('deferred')->text('body'), connection: 'database', queue: 'mail');
        $firstNotifications->send(new QueuedRecipient('1'), new QueuedNotice('deferred'));
        $other = new TemporaryProject();
        try {
            $other->write('Config/Queue.php', '<?php return ["default"=>"sync",'
                . '"connections"=>["sync"=>["driver"=>"sync"]]];');
            $other->write('Config/Mail.php', '<?php return ["default"=>"array",'
                . '"from"=>["address"=>"other@example.test"],'
                . '"transports"=>["array"=>["driver"=>"array"]]];');
            $app = new Application($other->path());
            foreach ([MailServiceProvider::class, NotificationServiceProvider::class,
                QueueServiceProvider::class] as $provider) $app->register($provider);
            $app->bootstrap();
            $otherMailer = $app->container()->make(Mailer::class);
            $firstMailer->queue((new Plugins\MailMessage())->to('first@example.test')
                ->subject('first')->text('body'), connection: 'sync');
            $firstNotifications->send(new QueuedRecipient('1'), new QueuedNotice('first', 'sync'));
            self::assertCount(2, $firstMailer->transport()->messages());
            self::assertSame([], $otherMailer->transport()->messages());
            self::assertTrue($this->worker()->workOnce('mail', 'database'));
            self::assertTrue($this->worker()->workOnce('notices', 'database'));
            self::assertCount(4, $firstMailer->transport()->messages());
            self::assertSame([], $otherMailer->transport()->messages());
        } finally {
            $other->remove();
        }
    }
}
