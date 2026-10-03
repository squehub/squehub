<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Container\Container;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Database\Lifecycle\ModelLifecycleEvent;
use App\Database\Lifecycle\ModelObserverRegistry;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Events\EventDispatcher;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Proves lifecycle order, write boundaries, isolation, and preserved Model state. */
final class ModelLifecycleTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for isolated lifecycle tests.');
        }
        $config = new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]);
        $this->manager = new DatabaseManager($config);
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->schema()->create('lifecycle_records', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->unique('name');
            $table->datetime('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testCreateUpdateNoopSoftDeleteRestoreAndForceDeleteOrder(): void
    {
        $events = [];
        foreach (['saving', 'creating', 'created', 'saved', 'updating', 'updated',
            'deleting', 'deleted', 'restoring', 'restored'] as $phase) {
            $this->manager->modelObservers()->listen(LifecycleRecord::class, $phase,
                static function (Model $model, ModelLifecycleEvent $event) use (&$events): void {
                    $events[] = [$event->phase, $event->operation, $model->exists()];
                });
        }

        $record = LifecycleRecord::create(['name' => 'Ada']);
        self::assertSame([['saving', 'create', false], ['creating', 'create', false],
            ['created', 'create', true], ['saved', 'create', true]], $events);
        $events = [];
        self::assertTrue($record->save());
        self::assertSame([], $events, 'No-op saves do not emit write events.');

        $record->setAttribute('name', 'Grace');
        self::assertTrue($record->save());
        self::assertSame([['saving', 'update', true], ['updating', 'update', true],
            ['updated', 'update', true], ['saved', 'update', true]], $events);
        $events = [];
        self::assertTrue($record->delete());
        self::assertTrue($record->isDeleted());
        self::assertSame([['deleting', 'delete', true], ['deleted', 'delete', true]], $events);
        $events = [];
        self::assertFalse($record->delete());
        self::assertSame([], $events);
        self::assertTrue($record->restore());
        self::assertSame([['restoring', 'restore', true], ['restored', 'restore', true]], $events);
        $events = [];
        self::assertTrue($record->forceDelete());
        self::assertSame([['deleting', 'forceDelete', true], ['deleted', 'forceDelete', false]], $events);
    }

    public function testBeforeFailurePreventsSqlAndAfterFailureRetainsSuccessfulWrite(): void
    {
        $this->manager->modelObservers()->listen(LifecycleRecord::class, 'creating',
            static function (): never { throw new RuntimeException('pre'); });
        $record = new LifecycleRecord(['name' => 'Ada']);
        try {
            $record->save();
            self::fail('The before observer must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('pre', $exception->getMessage());
        }
        self::assertSame(0, $this->manager->table('lifecycle_records')->count());
        self::assertFalse($record->exists());

        $other = $this->newManager();
        Database::setResolver(fn (): DatabaseManager => $other);
        $other->modelObservers()->listen(LifecycleRecord::class, 'created',
            static function (): never { throw new RuntimeException('post'); });
        $saved = new LifecycleRecord(['name' => 'Lin']);
        try {
            $saved->save();
            self::fail('The after observer must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('post', $exception->getMessage());
        }
        self::assertTrue($saved->exists());
        self::assertFalse($saved->changed());
        self::assertSame(1, $other->table('lifecycle_records')->count());
    }

    public function testClassObserverUsesContainerAndRetainedModelKeepsOriginalRegistry(): void
    {
        $container = new Container();
        $sink = new LifecycleSink();
        $container->instance(LifecycleSink::class, $sink);
        $registry = new ModelObserverRegistry($container);
        $registry->observe(LifecycleRecord::class, LifecycleClassObserver::class);
        $first = $this->newManager($registry);
        Database::setResolver(fn (): DatabaseManager => $first);
        $record = LifecycleRecord::create(['name' => 'Ada']);
        self::assertSame(['created'], $sink->phases);

        $second = $this->newManager();
        $otherEvents = [];
        $second->modelObservers()->listen(LifecycleRecord::class, 'updated',
            static function () use (&$otherEvents): void { $otherEvents[] = 'updated'; });
        Database::setResolver(fn (): DatabaseManager => $second);
        $record->setAttribute('name', 'Grace');
        self::assertTrue($record->save());
        self::assertSame(['created', 'updated'], $sink->phases);
        self::assertSame([], $otherEvents);
        self::assertSame('Grace', $first->table('lifecycle_records')->first()['name']);
        self::assertSame(0, $second->table('lifecycle_records')->count());
    }

    public function testFailedUpdateAndSoftDeleteDoNotEmitAfterEventsOrPublishState(): void
    {
        $a = LifecycleRecord::create(['name' => 'Ada']);
        LifecycleRecord::create(['name' => 'Lin']);
        $phases = [];
        foreach (['updating', 'updated', 'deleting', 'deleted'] as $phase) {
            $this->manager->modelObservers()->listen(LifecycleRecord::class, $phase,
                static function (Model $model, ModelLifecycleEvent $event) use (&$phases): void {
                    $phases[] = $event->phase;
                });
        }

        $a->setAttribute('name', 'Lin');
        try {
            $a->save();
            self::fail('The unique constraint should reject this update.');
        } catch (QueryException) {
            self::assertSame(['updating'], $phases);
            self::assertSame('Ada', $a->original('name'));
            self::assertTrue($a->changed('name'));
        }

        $a->setAttribute('name', 'Ada');
        $this->manager->raw("CREATE TRIGGER reject_lifecycle_delete BEFORE UPDATE OF deleted_at ON lifecycle_records "
            . "BEGIN SELECT RAISE(FAIL, 'blocked'); END");
        try {
            $a->delete();
            self::fail('The trigger should reject the soft deletion.');
        } catch (QueryException) {
            self::assertSame(['updating', 'deleting'], $phases);
            self::assertFalse($a->isDeleted());
            self::assertNull($a->original('deleted_at'));
            self::assertSame(1, $this->manager->table('lifecycle_records')
                ->filter('id', $a->getAttribute('id'))->count());
        }
    }

    public function testRecursiveSameInstanceWriteIsRejectedAndRegistryRecovers(): void
    {
        $this->manager->modelObservers()->listen(LifecycleRecord::class, 'saving',
            static fn (Model $model): bool => $model->save());
        $record = new LifecycleRecord(['name' => 'Ada']);
        $this->expectException(LogicException::class);
        $record->save();
    }

    public function testExistingApplicationEventsDispatcherReceivesLifecycleContext(): void
    {
        $container = new Container();
        $dispatcher = new EventDispatcher($container);
        $seen = [];
        $dispatcher->listen(ModelLifecycleEvent::class,
            static function (ModelLifecycleEvent $event) use (&$seen): void {
                $seen[] = [$event->phase, $event->operation, $event->model::class];
            });
        $manager = $this->newManager(new ModelObserverRegistry($container, $dispatcher));
        Database::setResolver(fn (): DatabaseManager => $manager);
        LifecycleRecord::create(['name' => 'Ada']);
        self::assertSame([
            ['saving', 'create', LifecycleRecord::class],
            ['creating', 'create', LifecycleRecord::class],
            ['created', 'create', LifecycleRecord::class],
            ['saved', 'create', LifecycleRecord::class],
        ], $seen);
    }

    public function testLifecycleEventsDescribeSuccessfulStatementsInsideCallerTransaction(): void
    {
        $seen = [];
        $this->manager->modelObservers()->listen(LifecycleRecord::class, 'created',
            static function (Model $model, ModelLifecycleEvent $event) use (&$seen): void {
                $seen[] = [$event->phase, $model->exists()];
            });
        $connection = $this->manager->connection();
        $connection->begin();
        try {
            $record = LifecycleRecord::create(['name' => 'Transient']);
            self::assertSame([['created', true]], $seen);
            self::assertTrue($record->exists());
            self::assertSame(1, $this->manager->table('lifecycle_records')->count());
        } finally {
            $connection->rollback();
        }

        // Model snapshots describe the successful local statement. They do not
        // track a later caller-owned transaction rollback or emit undo events.
        self::assertSame(0, $this->manager->table('lifecycle_records')->count());
        self::assertTrue($record->exists());
        self::assertSame([['created', true]], $seen);
    }

    private function newManager(?ModelObserverRegistry $registry = null): DatabaseManager
    {
        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]), null, null, null, $registry);
        $manager->schema()->create('lifecycle_records', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->unique('name');
            $table->datetime('deleted_at')->nullable();
        });
        return $manager;
    }
}

/** A soft-deletable fixture with only the columns needed for transition tests. */
final class LifecycleRecord extends Model
{
    protected string $table = 'lifecycle_records';
    protected array $fillable = ['name'];
    protected array $guarded = [];
    protected bool $softDeletes = true;
}

/** Container-injected observer dependency for Application ownership tests. */
final class LifecycleSink
{
    /** @var list<string> */
    public array $phases = [];
}

/** Explicit observer methods are resolved only when the matching transition fires. */
final class LifecycleClassObserver
{
    public function __construct(private LifecycleSink $sink)
    {
    }

    public function created(Model $model): void
    {
        $this->sink->phases[] = 'created';
    }

    public function updated(Model $model): void
    {
        $this->sink->phases[] = 'updated';
    }
}
