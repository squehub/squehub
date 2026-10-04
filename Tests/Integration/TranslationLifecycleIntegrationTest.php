<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\DatabaseManager;
use App\Http\Kernel;
use App\Http\Request;
use App\Plugins\Translation;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Queue\Worker;
use App\Routing\RouteRegistry;
use App\Testing\TestApplication;
use App\Translation\TranslationManager;
use PDO;
use PHPUnit\Framework\TestCase;

/** JSON-safe probe for locale isolation across consecutive worker attempts. */
final class TranslationLocaleProbeJob implements QueueJob
{
    /** @var list<string> */
    public static array $observed = [];

    public function __construct(private readonly bool $changeLocale)
    {
    }

    public function handle(): void
    {
        self::$observed[] = Translation::locale();
        if ($this->changeLocale) {
            Translation::setLocale('fr');
        }
    }

    /** @return array{change:bool} */
    public function toQueuePayload(): array
    {
        return ['change' => $this->changeLocale];
    }

    /** @param array<string,mixed> $payload */
    public static function fromQueuePayload(array $payload): static
    {
        return new static($payload['change'] === true);
    }
}

/** Locale selection is scoped to a request or a worker job, never a PHP process. */
final class TranslationLifecycleIntegrationTest extends TestCase
{
    /** @var list<TestApplication> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        TranslationLocaleProbeJob::$observed = [];
        foreach ($this->fixtures as $fixture) {
            $fixture->cleanup();
        }
    }

    public function testHttpRequestLocaleReturnsToApplicationSelectionAfterEachResponse(): void
    {
        $fixture = $this->fixture();
        $fixture->write('Project/Translations/en/messages.json', '{"hello":"Hello"}');
        $fixture->write('Project/Translations/fr/messages.json', '{"hello":"Bonjour"}');
        $app = $fixture->application();
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/switch', static function (): string {
            Translation::setLocale('fr');
            return Translation::get('messages.hello');
        });
        $routes->get('/read', static fn (): string => Translation::get('messages.hello'));

        $kernel = $app->container()->make(Kernel::class);
        self::assertSame('Bonjour', $kernel->handle(new Request('GET', '/switch'))->content());
        self::assertSame('en', $app->container()->make(TranslationManager::class)->locale());
        self::assertSame('Hello', $kernel->handle(new Request('GET', '/read'))->content());
    }

    public function testWorkerLocaleDoesNotLeakIntoNextJobOrAnotherApplication(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for the isolated Queue worker.');
        }
        $fixture = $this->fixture();
        $app = $fixture->application();
        $database = $app->container()->make(DatabaseManager::class);
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_24_create_queue_tables.php';
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_25_add_failed_queue_payload.php';
        (new \CreateQueueTables())->up($database->connection()->pdo(), $database->schema());
        (new \AddFailedQueuePayload())->up($database->connection()->pdo(), $database->schema());

        $queue = new QueueManager(['default' => 'database', 'connections' => [
            'database' => ['driver' => 'database', 'database_connection' => 'testing',
                'retry_after' => 2],
        ]], static fn (?string $name) => $database->connection($name));
        $app->container()->instance(QueueManager::class, $queue);
        $queue->dispatch(new TranslationLocaleProbeJob(true));
        $queue->dispatch(new TranslationLocaleProbeJob(false));

        $worker = new Worker($queue, container: $app->container());
        self::assertTrue($worker->workOnce());
        self::assertSame('en', $app->container()->make(TranslationManager::class)->locale());
        self::assertTrue($worker->workOnce());
        self::assertSame(['en', 'en'], TranslationLocaleProbeJob::$observed);

        $other = $this->fixture(['translation' => [
            'default' => 'fr', 'fallback' => 'fr', 'supported' => ['fr'],
        ]]);
        $other->application();
        self::assertSame('fr', $other->application()->container()
            ->make(TranslationManager::class)->locale());
        self::assertSame('en', $app->container()->make(TranslationManager::class)->locale());
    }

    /** @param array<string,array<string,mixed>> $configuration */
    private function fixture(array $configuration = []): TestApplication
    {
        $fixture = TestApplication::temporary(array_replace([
            'translation' => ['default' => 'en', 'fallback' => 'en',
                'supported' => ['en', 'fr']],
        ], $configuration));
        $this->fixtures[] = $fixture;
        return $fixture;
    }
}
