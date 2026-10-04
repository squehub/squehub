<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Container\Container;
use App\Data\QueuePayloadData;
use App\Data\TypedPayloadRegistry;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Events\EventDispatcher;
use App\Events\EventServiceProvider;
use App\Events\QueueableEvent;
use App\Foundation\Application;
use App\Notifications\Notification;
use App\Notifications\NotificationChannel;
use App\Notifications\NotificationManager;
use App\Notifications\NotificationServiceProvider;
use App\Notifications\ShouldQueue;
use App\Queue\QueueCodec;
use App\Queue\QueueContextAware;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real Queue persistence keeps typed values inside the existing job path. */
final class TypedPayloadQueueIntegrationTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private DatabaseManager $database;
    private TypedPayloadRegistry $payloads;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for typed Queue integration.');
        }
        $this->project = new TemporaryProject();
        $this->project->write('Config/Database.php', '<?php return ["default"=>"test",'
            . '"connections"=>["test"=>["driver"=>"sqlite","database"=>'
            . var_export($this->project->path('typed.sqlite'), true) . ']]];');
        $this->project->write('Config/Queue.php', '<?php return ["default"=>"database",'
            . '"connections"=>["database"=>["driver"=>"database",'
            . '"database_connection"=>"test","retry_after"=>30]]];');
        $this->app = new Application($this->project->path());
        foreach ([DatabaseServiceProvider::class, QueueServiceProvider::class,
            EventServiceProvider::class, NotificationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $this->database = $this->app->container()->make(DatabaseManager::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        (new \CreateQueueTables())->up($this->database->connection()->pdo(),
            $this->database->connection()->schema());
        $this->payloads = $this->app->container()->make(TypedPayloadRegistry::class);
        $this->payloads->define('user.registration', 1, QueuedRegistrationData::class);
        TypedPayloadTrace::$values = [];
        TypedPayloadTrace::$failNext = false;
    }

    protected function tearDown(): void
    {
        \App\Queue\Queue::setResolver(null);
        \App\Events\Events::setResolver(null);
        \App\Notifications\Notifications::setResolver(null);
        \App\Database\Database::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->project)) $this->project->remove();
    }

    private function queue(): QueueManager
    {
        return $this->app->container()->make(QueueManager::class);
    }

    private function worker(): Worker
    {
        return $this->app->container()->make(Worker::class);
    }

    private function pending(): int
    {
        return (int) $this->database->connection()->raw('SELECT COUNT(*) FROM `queue_jobs`')
            ->fetchColumn();
    }

    public function testPersistentJobRoundTripAndRetryPreserveExplicitTypedData(): void
    {
        $original = new QueuedRegistrationData('u-7', 'PRIVATE_RUNTIME_SECRET');
        self::assertSame('PRIVATE_RUNTIME_SECRET', $original->runtimeSecret());
        $typed = $this->payloads->encode($original);
        $this->queue()->dispatch(new TypedPayloadProbeJob($typed));
        $raw = (string) $this->database->connection()->raw('SELECT `payload` FROM `queue_jobs`')
            ->fetchColumn();
        self::assertStringContainsString('user.registration', $raw);
        self::assertStringNotContainsString(QueuedRegistrationData::class, $raw);
        self::assertStringNotContainsString('PRIVATE_RUNTIME_SECRET', $raw);
        self::assertInstanceOf(TypedPayloadProbeJob::class, QueueCodec::decode($raw));
        TypedPayloadTrace::$failNext = true;
        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(1, $this->pending());
        self::assertSame([], TypedPayloadTrace::$values);
        self::assertTrue($this->worker()->workOnce(tries: 2, backoff: 0));
        self::assertSame(0, $this->pending());
        self::assertSame(['u-7'], TypedPayloadTrace::$values);
    }

    public function testFreshWorkerApplicationUsesItsOwnMatchingTypeRegistration(): void
    {
        $this->queue()->dispatch(new TypedPayloadProbeJob($this->payloads->encode(
            new QueuedRegistrationData('u-fresh', 'PRIVATE_RUNTIME_SECRET'))));
        $workerApp = new Application($this->project->path());
        $workerApp->register(DatabaseServiceProvider::class);
        $workerApp->register(QueueServiceProvider::class);
        $workerApp->bootstrap();
        $workerDatabase = $workerApp->container()->make(DatabaseManager::class);
        try {
            $workerRegistry = $workerApp->container()->make(TypedPayloadRegistry::class);
            self::assertNotSame($this->payloads, $workerRegistry);
            $workerRegistry->define('user.registration', 1, QueuedRegistrationData::class);
            self::assertTrue($workerApp->container()->make(Worker::class)->workOnce());
            self::assertSame(['u-fresh'], TypedPayloadTrace::$values);
            self::assertSame(0, $this->pending());
        } finally {
            $workerDatabase->disconnect();
        }
    }

    public function testLegacyArrayJobUsesTheUnchangedQueueCodec(): void
    {
        $legacy = new LegacyArrayProbeJob(['id' => 9, 'flags' => ['active' => true]]);
        $encoded = QueueCodec::encode($legacy);
        self::assertSame($legacy->toQueuePayload(), QueueCodec::decode($encoded)->toQueuePayload());
        $this->queue()->dispatch($legacy);
        self::assertTrue($this->worker()->workOnce());
        self::assertSame(['legacy-9'], TypedPayloadTrace::$values);
    }

    public function testQueuedEventReconstructsTypedFieldWhileOrdinaryEventStaysInProcess(): void
    {
        $events = $this->app->container()->make(EventDispatcher::class);
        $immediate = [];
        $events->listen(PlainTypedEvent::class,
            static function (PlainTypedEvent $event) use (&$immediate): void {
                $immediate[] = $event->userId;
            });
        $events->emit(new PlainTypedEvent('direct'));
        self::assertSame(['direct'], $immediate);
        self::assertSame(0, $this->pending());

        $events->listenQueued(TypedQueuedEvent::class, TypedEventListener::class,
            afterCommit: false);
        $events->emit(new TypedQueuedEvent($this->payloads->encode(
            new QueuedRegistrationData('u-event', 'PRIVATE_RUNTIME_SECRET'))));
        self::assertSame([], TypedPayloadTrace::$values);
        self::assertSame(1, $this->pending());
        self::assertTrue($this->worker()->workOnce());
        self::assertSame(['u-event'], TypedPayloadTrace::$values);
        self::assertSame(['direct'], $immediate);
    }

    public function testQueuedNotificationUsesExistingWorkerAndChannel(): void
    {
        $manager = $this->app->container()->make(NotificationManager::class);
        $channel = new TypedNoticeChannel($this->payloads);
        $manager->registerChannel('typed', $channel);
        $notice = new TypedQueuedNotice($this->payloads->encode(
            new QueuedRegistrationData('u-notice', 'PRIVATE_RUNTIME_SECRET')));
        $manager->route('mail', 'route@example.test')->send($notice);
        self::assertSame([], $channel->values);
        self::assertSame(1, $this->pending());
        self::assertTrue($this->worker()->workOnce());
        self::assertSame(['u-notice'], $channel->values);
    }

    public function testCorruptTypedValueFollowsNormalFailedJobPolicyWithoutMetadataLeak(): void
    {
        $secret = 'PRIVATE_TYPED_QUEUE_SECRET';
        $this->queue()->dispatch(new TypedPayloadProbeJob([
            'type' => 'unknown', 'version' => 1, 'data' => ['token' => $secret],
        ]));
        self::assertTrue($this->worker()->workOnce(tries: 1, backoff: 0));
        self::assertSame(0, $this->pending());
        self::assertSame([], TypedPayloadTrace::$values);
        $safe = $this->database->connection()->raw('SELECT `job_class`, `error_type`, '
            . '`error_message`, `attempts` FROM `queue_failed_jobs`')->fetchAll();
        self::assertCount(1, $safe);
        self::assertStringNotContainsString($secret, json_encode($safe));
    }
}

/** Only user_id crosses the Queue boundary; runtime credentials do not. */
final readonly class QueuedRegistrationData implements QueuePayloadData
{
    public function __construct(public string $userId, private string $runtimeSecret) {}
    public function runtimeSecret(): string { return $this->runtimeSecret; }
    public function toQueueData(): array { return ['user_id' => $this->userId]; }
    public static function fromQueueData(array $data): static
    {
        if (array_keys($data) !== ['user_id'] || !is_string($data['user_id'])) {
            throw new \RuntimeException('Typed registration payload is invalid.');
        }
        return new static($data['user_id'], '');
    }
}

/** Test-only business observation shared by worker attempts. */
final class TypedPayloadTrace
{
    /** @var list<string> */
    public static array $values = [];
    public static bool $failNext = false;
}

/** Existing QueueJob can carry the registered envelope as ordinary JSON data. */
final class TypedPayloadProbeJob implements QueueJob, QueueContextAware
{
    private ?Container $container = null;

    /** @param array<string|int, mixed> $typed */
    public function __construct(private array $typed) {}

    public function toQueuePayload(): array { return ['typed' => $this->typed]; }
    public static function fromQueuePayload(array $payload): static
    {
        if (!is_array($payload['typed'] ?? null)) {
            throw new \RuntimeException('Typed Queue job is invalid.');
        }
        return new static($payload['typed']);
    }
    public function setQueueContainer(Container $container): void { $this->container = $container; }
    public function handle(): void
    {
        if ($this->container === null) throw new \RuntimeException('Worker Container is unavailable.');
        $value = $this->container->make(TypedPayloadRegistry::class)->decode($this->typed);
        if (!$value instanceof QueuedRegistrationData) {
            throw new \RuntimeException('Unexpected typed Queue data.');
        }
        if (TypedPayloadTrace::$failNext) {
            TypedPayloadTrace::$failNext = false;
            throw new \RuntimeException('Intentional first attempt failure.');
        }
        TypedPayloadTrace::$values[] = $value->userId;
    }
}

/** Legacy scalar and array jobs need no typed registration or new envelope. */
final class LegacyArrayProbeJob implements QueueJob
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}
    public function toQueuePayload(): array { return $this->data; }
    public static function fromQueuePayload(array $payload): static { return new static($payload); }
    public function handle(): void
    {
        TypedPayloadTrace::$values[] = 'legacy-' . $this->data['id'];
    }
}

/** Ordinary in-process events retain their existing object contract. */
final readonly class PlainTypedEvent
{
    public function __construct(public string $userId) {}
}

/** A selected Event stores a typed field in its existing explicit array. */
final readonly class TypedQueuedEvent implements QueueableEvent
{
    /** @param array<string|int, mixed> $typed */
    public function __construct(public array $typed) {}
    public function toQueuePayload(): array { return ['typed' => $this->typed]; }
    public static function fromQueuePayload(array $payload): static
    {
        if (!is_array($payload['typed'] ?? null)) {
            throw new \RuntimeException('Typed event payload is invalid.');
        }
        return new static($payload['typed']);
    }
}

/** Queued listener uses its Application's registry after worker reconstruction. */
final readonly class TypedEventListener
{
    public function __construct(private TypedPayloadRegistry $payloads) {}
    public function handle(TypedQueuedEvent $event): void
    {
        $data = $this->payloads->decode($event->typed);
        if (!$data instanceof QueuedRegistrationData) {
            throw new \RuntimeException('Unexpected typed Event data.');
        }
        TypedPayloadTrace::$values[] = $data->userId;
    }
}

/** Notification Queue data stays an array until its normal channel runs. */
final class TypedQueuedNotice extends Notification implements ShouldQueue
{
    /** @param array<string|int, mixed> $typed */
    public function __construct(public array $typed) {}
    public function via(mixed $notifiable): array { return ['typed']; }
    public function toQueuePayload(): array { return ['typed' => $this->typed]; }
    public static function fromQueuePayload(array $payload): static
    {
        if (!is_array($payload['typed'] ?? null)) {
            throw new \RuntimeException('Typed notification payload is invalid.');
        }
        return new static($payload['typed']);
    }
}

/** A regular Notification channel resolves only its selected registered data. */
final class TypedNoticeChannel implements NotificationChannel
{
    /** @var list<string> */
    public array $values = [];
    public function __construct(private TypedPayloadRegistry $payloads) {}
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (!$notification instanceof TypedQueuedNotice) {
            throw new \RuntimeException('Typed notification was expected.');
        }
        $data = $this->payloads->decode($notification->typed);
        if (!$data instanceof QueuedRegistrationData) {
            throw new \RuntimeException('Unexpected typed Notification data.');
        }
        $this->values[] = $data->userId;
    }
}
