<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Container\Container;
use App\Database\DatabaseManager;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Events\EventDispatcher;
use App\Events\EventException;
use App\Events\EventServiceProvider;
use App\Events\Events;
use App\Foundation\Application;
use App\Foundation\ServiceProvider;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Log;
use App\Logging\LoggingServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Verifies provider wiring, request metrics, HTTP errors, and SQLite transactions. */
final class EventIntegrationTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        ConfiguredEventListener::$constructed = 0;
        ConfiguredEventListener::$calls = [];
        EventProviderProbe::$calls = [];
        EventOrder::$calls = [];
    }

    protected function tearDown(): void
    {
        Events::setResolver(null);
        Route::setResolver(null);
        Log::setResolver(null);
        Diagnostic::setResolver(null);
        $this->project->remove();
    }

    public function testConfigurationIsValidatedAtBootAndListenersResolveLazily(): void
    {
        $this->project->write('Config/Events.php', '<?php return ["listeners" => ['
            . var_export(IntegrationSignal::class, true) . ' => [['
            . '"listener" => ' . var_export(ConfiguredEventListener::class, true)
            . ', "priority" => 0]]]];');
        $app = new Application($this->project->path());
        $app->register(EventServiceProvider::class);
        $app->register(EventProviderProbe::class);
        $app->bootstrap();
        self::assertSame(0, ConfiguredEventListener::$constructed);
        self::assertSame($app->container()->make(EventDispatcher::class), events());
        events()->emit(new IntegrationSignal('safe'));
        self::assertSame(1, ConfiguredEventListener::$constructed);
        self::assertSame(['configured:safe'], ConfiguredEventListener::$calls);
        self::assertSame(['provider:safe'], EventProviderProbe::$calls);
        self::assertSame(['configured:safe', 'provider:safe'], EventOrder::$calls);
        events()->emit(new IntegrationSignal('second'));
        self::assertSame(2, ConfiguredEventListener::$constructed);
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidEventConfigurationFailsDuringBoot(string $file): void
    {
        $this->project->write('Config/Events.php', $file);
        $app = new Application($this->project->path());
        $app->register(EventServiceProvider::class);
        $this->expectException(EventException::class);
        $app->bootstrap();
    }

    /** @return array<string, array{string}> */
    public static function invalidConfigurations(): array
    {
        $event = var_export(IntegrationSignal::class, true);
        $listener = var_export(ConfiguredEventListener::class, true);
        return [
            'not a map' => ['<?php return ["listeners" => "wrong"];'],
            'missing event' => ['<?php return ["listeners" => ["Missing\\Event" => [' . $listener . ']]];'],
            'missing empty event' => ['<?php return ["listeners" => ["Missing\\Event" => []]];'],
            'missing listener' => ['<?php return ["listeners" => [' . $event . ' => ["Missing\\Listener"]]];'],
            'bad priority' => ['<?php return ["listeners" => [' . $event
                . ' => [["listener" => ' . $listener . ', "priority" => "10"]]]];'],
            'null priority' => ['<?php return ["listeners" => [' . $event
                . ' => [["listener" => ' . $listener . ', "priority" => null]]]];'],
            'malformed entry' => ['<?php return ["listeners" => [' . $event
                . ' => [["priority" => 10]]]];'],
        ];
    }

    public function testApplicationInstancesAndCliStyleBootAreIndependent(): void
    {
        $first = new Application($this->project->path());
        $first->register(EventServiceProvider::class);
        $first->bootstrap();
        $firstDispatcher = $first->container()->make(EventDispatcher::class);
        $count = 0;
        $firstDispatcher->listen(IntegrationSignal::class, static function () use (&$count): void { ++$count; });
        $second = new Application($this->project->path());
        $second->register(EventServiceProvider::class);
        $second->bootstrap();
        self::assertNotSame($firstDispatcher, $second->container()->make(EventDispatcher::class));
        $second->container()->make(EventDispatcher::class)->emit(new IntegrationSignal('second'));
        self::assertSame(0, $count);
        $firstDispatcher->emit(new IntegrationSignal('first'));
        self::assertSame(1, $count);
        // Neither Application registered HTTP, Session, Database, Cache, or Logging.
        self::assertFalse($first->container()->has(DatabaseManager::class));
    }

    public function testHttpEventsResetMetricsAndListenerFailureLogsOnce(): void
    {
        $this->project->write('Config/Logging.php', '<?php return ["driver" => "array", "level" => "debug"];');
        $app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, EventServiceProvider::class,
            LoggingServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $dispatcher = $app->container()->make(EventDispatcher::class);
        $dispatcher->listen(IntegrationSignal::class, static function (IntegrationSignal $event): void {
            if ($event->message === 'fail') throw new RuntimeException('private-listener-token');
        });
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->add('GET', '/emit', static function (): Response {
            events()->emit(new IntegrationSignal('ok'));
            return new Response('done');
        });
        $routes->add('GET', '/fail', static function (): Response {
            events()->emit(new IntegrationSignal('fail'));
            return new Response('unreachable');
        });
        $kernel = $app->container()->make(Kernel::class);
        self::assertSame('done', $kernel->handle(new Request('GET', '/emit'))->content());
        $diagnostics = $app->container()->make(Diagnostics::class);
        self::assertSame(1, $diagnostics->snapshot()['events']['emitted']);
        self::assertSame(1, $diagnostics->snapshot()['events']['listener_invocations']);
        self::assertSame(0, $diagnostics->snapshot()['events']['failures']);
        $kernel->handle(new Request('GET', '/missing'));
        self::assertSame(0, $diagnostics->snapshot()['events']['emitted']);
        $failure = $kernel->handle(new Request('GET', '/fail'));
        self::assertSame(500, $failure->status());
        self::assertSame(1, $diagnostics->snapshot()['events']['failures']);
        self::assertStringNotContainsString('private-listener-token', json_encode($diagnostics->snapshot()));
        self::assertStringNotContainsString(IntegrationSignal::class, json_encode($diagnostics->snapshot()));
        self::assertCount(1, $app->container()->make(ArrayLogger::class)->records());
        self::assertStringNotContainsString('private-listener-token',
            json_encode($app->container()->make(ArrayLogger::class)->records()[0]->toArray()));
    }

    public function testRequestTimeRegistrationPersistsAcrossKernelRequests(): void
    {
        $app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, EventServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $observed = [];
        $registry = $app->container()->make(RouteRegistry::class);
        $registry->add('GET', '/register', static function () use (&$observed): Response {
            events()->listen(IntegrationSignal::class,
                static function (IntegrationSignal $event) use (&$observed): void {
                    $observed[] = $event->message;
                });
            return new Response('registered');
        });
        $registry->add('GET', '/emit', static function (): Response {
            events()->emit(new IntegrationSignal('next-request'));
            return new Response('emitted');
        });
        $kernel = $app->container()->make(Kernel::class);
        $kernel->handle(new Request('GET', '/register'));
        self::assertSame(0, $app->container()->make(Diagnostics::class)->snapshot()['events']['emitted']);
        $kernel->handle(new Request('GET', '/emit'));
        self::assertSame(['next-request'], $observed);
        self::assertSame(1, $app->container()->make(Diagnostics::class)->snapshot()['events']['emitted']);
        self::assertSame(1, $app->container()->make(Diagnostics::class)->snapshot()['events']['listener_invocations']);
    }

    public function testEventsExecuteInsideCallerOwnedSqliteTransaction(): void
    {
        $config = new Repository(['database' => ['default' => 'main', 'connections' => [
            'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]]]);
        $database = new DatabaseManager($config);
        $database->raw('CREATE TABLE event_rows (id INTEGER PRIMARY KEY, note TEXT NOT NULL)');
        $events = new EventDispatcher(new Container());
        $events->listen(IntegrationSignal::class, static function (IntegrationSignal $event) use ($database): void {
            $database->table('event_rows')->insert(['note' => 'listener:' . $event->message]);
        });
        $database->transaction(static function () use ($database, $events): void {
            $database->table('event_rows')->insert(['note' => 'caller']);
            $events->emit(new IntegrationSignal('committed'));
        });
        self::assertSame(2, $database->table('event_rows')->count());
        $events->listen(IntegrationSignal::class, static function (): never {
            throw new RuntimeException('rollback');
        });
        try {
            $database->transaction(static function () use ($database, $events): void {
                $database->table('event_rows')->insert(['note' => 'caller-rollback']);
                $events->emit(new IntegrationSignal('rolled-back'));
            });
            self::fail('Listener failure should leave rollback to the transaction wrapper.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertSame(2, $database->table('event_rows')->count());
    }
}

/** Plain application event used by provider, HTTP, and transaction tests. */
final class IntegrationSignal
{
    public function __construct(public string $message)
    {
    }
}

/** Configured class listener; construction remains lazy. */
final class ConfiguredEventListener
{
    public static int $constructed = 0;
    /** @var list<string> */
    public static array $calls = [];

    public function __construct()
    {
        ++self::$constructed;
    }

    public function handle(IntegrationSignal $event): void
    {
        self::$calls[] = 'configured:' . $event->message;
        EventOrder::$calls[] = 'configured:' . $event->message;
    }
}

/** A later provider can register package-style listeners during boot. */
final class EventProviderProbe extends ServiceProvider
{
    /** @var list<string> */
    public static array $calls = [];

    public function boot(): void
    {
        $this->app->container()->make(EventDispatcher::class)->listen(
            IntegrationSignal::class,
            static function (IntegrationSignal $event): void {
                self::$calls[] = 'provider:' . $event->message;
                EventOrder::$calls[] = 'provider:' . $event->message;
            }
        );
    }
}

/** Shared trace checks configuration before later provider registration. */
final class EventOrder
{
    /** @var list<string> */
    public static array $calls = [];
}
