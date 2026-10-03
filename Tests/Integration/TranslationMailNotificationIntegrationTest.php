<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Mail\Mail as MailGateway;
use App\Mail\Mailer;
use App\Mail\MailServiceProvider;
use App\Mail\Transports\ArrayMailTransport;
use App\Notifications\NotificationManager;
use App\Notifications\Notifications;
use App\Notifications\NotificationServiceProvider;
use App\Plugins\Mail;
use App\Plugins\MailMessage;
use App\Plugins\Notification;
use App\Plugins\ShouldQueue;
use App\Plugins\Translation;
use App\Queue\Queue;
use App\Queue\QueueServiceProvider;
use App\Queue\Worker;
use App\Support\RuntimeContext;
use App\Testing\TestApplication;
use App\Translation\TranslationManager;
use App\Translation\TranslationServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Mail content is built in the active locale during ordinary delivery. */
final class LocalizedImmediateNotice extends Notification
{
    public function via(mixed $notifiable): array { return ['mail']; }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())->subject(Translation::get('mail.subject'))
            ->text(Translation::get('mail.body'));
    }
}

/** Queue payload carries only deliberate locale selection, never process locale state. */
final class LocalizedQueuedNotice extends Notification implements ShouldQueue
{
    public function __construct(private ?string $locale = null, private bool $selectLocale = false)
    {
    }

    public function via(mixed $notifiable): array { return ['mail']; }

    public function toMail(mixed $notifiable): MailMessage
    {
        if ($this->selectLocale) Translation::setLocale('fr');
        return (new MailMessage())->subject(Translation::get('mail.subject', locale: $this->locale))
            ->text(Translation::get('mail.body', locale: $this->locale));
    }

    public function toQueuePayload(): array
    {
        return ['locale' => $this->locale, 'select_locale' => $this->selectLocale];
    }

    public static function fromQueuePayload(array $payload): static
    {
        $locale = $payload['locale'] ?? null;
        $selectLocale = $payload['select_locale'] ?? null;
        if (($locale !== null && !is_string($locale)) || !is_bool($selectLocale)) {
            throw new \InvalidArgumentException('Localized queued test payload is invalid.');
        }
        return new static($locale, $selectLocale);
    }
}

/** Real SQLite Queue, Array Mail, and Translation providers share one Application. */
final class TranslationMailNotificationIntegrationTest extends TestCase
{
    private TestApplication $fixture;
    private Application $app;
    private DatabaseManager $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for queued translation integration.');
        }
        $this->fixture = TestApplication::temporary([
            'translation' => ['default' => 'en', 'fallback' => 'en', 'supported' => ['en', 'fr']],
            'mail' => ['default' => 'array', 'from' => ['address' => 'sender@example.test'],
                'transports' => ['array' => ['driver' => 'array']]],
            'queue' => ['default' => 'database', 'connections' => [
                'database' => ['driver' => 'database', 'database_connection' => 'testing',
                    'retry_after' => 30],
            ]],
        ]);
        $this->fixture->write('Project/Translations/en/mail.json',
            '{"subject":"Welcome", "body":"Hello"}');
        $this->fixture->write('Project/Translations/fr/mail.json',
            '{"subject":"Bienvenue", "body":"Bonjour"}');
        $this->app = new Application($this->fixture->root());
        foreach ([DiagnosticsServiceProvider::class, TranslationServiceProvider::class,
            DatabaseServiceProvider::class, QueueServiceProvider::class,
            MailServiceProvider::class, NotificationServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        RuntimeContext::select($this->app);
        $this->database = $this->app->container()->make(DatabaseManager::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        // Use the actual Queue migration while keeping its unnamespaced class
        // out of this test's static application symbol inventory.
        /** @var class-string $migrationClass */
        $migrationClass = 'CreateQueueTables';
        $migration = new ReflectionClass($migrationClass);
        $migration->getMethod('up')->invoke($migration->newInstance(),
            $this->database->connection()->pdo(), $this->database->connection()->schema());
    }

    protected function tearDown(): void
    {
        MailGateway::setResolver(null);
        Notifications::setResolver(null);
        Queue::setResolver(null);
        \App\Translation\Translation::setResolver(null);
        if (isset($this->database)) $this->database->disconnect();
        if (isset($this->fixture)) $this->fixture->cleanup();
    }

    public function testSynchronousMailAndNotificationUseActiveLocaleWithoutLeakingIt(): void
    {
        $locale = $this->locale();
        $outbox = $this->outbox();
        $locale->beginScope('fr');
        try {
            Mail::send((new MailMessage())->to('direct@example.test')
                ->subject(Translation::get('mail.subject'))
                ->text(Translation::get('mail.body')));
            $this->notifications()->route('mail', 'notify@example.test')
                ->send(new LocalizedImmediateNotice());
        } finally {
            $locale->endScope();
        }
        self::assertSame('en', Translation::locale());
        self::assertCount(2, $outbox->messages());
        self::assertSame(['Bienvenue', 'Bienvenue'], array_map(
            static fn (MailMessage $message): ?string => $message->subjectLine(),
            $outbox->messages()));
        self::assertSame(['Bonjour', 'Bonjour'], array_map(
            static fn (MailMessage $message): ?string => $message->textBody(),
            $outbox->messages()));
        $this->notifications()->route('mail', 'next@example.test')
            ->send(new LocalizedImmediateNotice());
        self::assertSame('Welcome', $outbox->messages()[2]->subjectLine());
    }

    public function testQueuedMailPreservesAlreadyLocalizedContentUntilWorkerDelivery(): void
    {
        $locale = $this->locale();
        $locale->beginScope('fr');
        try {
            Mail::queue((new MailMessage())->to('queued@example.test')
                ->subject(Translation::get('mail.subject'))
                ->text(Translation::get('mail.body')));
        } finally {
            $locale->endScope();
        }
        self::assertSame('en', Translation::locale());
        self::assertSame([], $this->outbox()->messages());
        self::assertSame(1, $this->queuedCount());
        self::assertTrue($this->worker()->workOnce());
        self::assertSame(0, $this->queuedCount());
        self::assertSame(['Bienvenue'], array_map(
            static fn (MailMessage $message): ?string => $message->subjectLine(),
            $this->outbox()->messages()));
        self::assertSame(['Bonjour'], array_map(
            static fn (MailMessage $message): ?string => $message->textBody(),
            $this->outbox()->messages()));
        self::assertSame('en', Translation::locale());
    }

    public function testQueuedNotificationResolvesLocaleAtDeliveryAndWorkerDoesNotLeakIt(): void
    {
        $locale = $this->locale();
        $recipient = $this->notifications()->route('mail', 'queued@example.test');
        $locale->beginScope('fr');
        try {
            $recipient->send(new LocalizedQueuedNotice(selectLocale: true));
            $recipient->send(new LocalizedQueuedNotice());
            $recipient->send(new LocalizedQueuedNotice(locale: 'fr'));
        } finally {
            $locale->endScope();
        }
        self::assertSame([], $this->outbox()->messages());
        self::assertSame(3, $this->queuedCount());
        $expectedSubjects = ['Bienvenue', 'Welcome', 'Bienvenue'];
        foreach ($expectedSubjects as $index => $subject) {
            self::assertTrue($this->worker()->workOnce());
            self::assertSame(array_slice($expectedSubjects, 0, $index + 1), array_map(
                static fn (MailMessage $message): ?string => $message->subjectLine(),
                $this->outbox()->messages()));
            self::assertSame('en', Translation::locale(),
                'The worker must restore its Application locale after each delivery.');
        }
        self::assertSame(0, $this->queuedCount());
    }

    private function locale(): TranslationManager
    {
        return $this->app->container()->make(TranslationManager::class);
    }

    private function mailer(): Mailer
    {
        return $this->app->container()->make(Mailer::class);
    }

    private function outbox(): ArrayMailTransport
    {
        $transport = $this->mailer()->transport();
        self::assertInstanceOf(ArrayMailTransport::class, $transport);
        return $transport;
    }

    private function notifications(): NotificationManager
    {
        return $this->app->container()->make(NotificationManager::class);
    }

    private function worker(): Worker
    {
        return $this->app->container()->make(Worker::class);
    }

    private function queuedCount(): int
    {
        return (int) $this->database->connection()->raw('SELECT COUNT(*) FROM queue_jobs')
            ->fetchColumn();
    }
}
