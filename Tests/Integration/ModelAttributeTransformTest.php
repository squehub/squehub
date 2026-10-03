<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Attributes;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/** Verifies explicit read and assignment transforms against canonical SQLite state. */
final class ModelAttributeTransformTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for Model attribute tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->schema()->create('transform_records', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->unique('slug');
            $table->integer('score')->nullable();
            $table->datetime('deleted_at')->nullable();
        });
        TransformRecord::$mutations = 0;
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testAccessorTransformsReadsWithoutChangingCanonicalState(): void
    {
        $record = TransformRecord::create(['name' => ' Ada ', 'slug' => 'ada', 'score' => '10']);
        $loaded = TransformRecord::find($record->rawAttribute('id'));

        self::assertSame('ADA', $loaded->getAttribute('name'));
        self::assertSame('ADA', $loaded->__get('name'));
        self::assertTrue($loaded->__isset('name'));
        self::assertSame('Member Ada', $loaded->getAttribute('label'));
        self::assertSame('Member Ada', $loaded->__get('label'));
        self::assertTrue($loaded->__isset('label'));
        self::assertSame('Ada', $loaded->rawAttribute('name'));
        self::assertSame('Ada', $loaded->attributes()['name']);
        self::assertSame('Ada', $loaded->original('name'));
        self::assertSame('Score 10', $loaded->getAttribute('score'));
        self::assertSame('Score 10', $loaded->toArray()['score']);
        self::assertSame('ADA', $loaded->toArray()['name']);
        self::assertArrayNotHasKey('label', $loaded->toArray());
        self::assertFalse($loaded->changed());
        self::assertSame([], $loaded->changes());
        self::assertSame([], $loaded->savedChanges());
    }

    public function testMutatorRunsOnceOnExplicitAssignmentButNotHydrationOrInternalWrites(): void
    {
        $record = new TransformRecord(['name' => ' Ada ', 'slug' => 'ada', 'score' => 10]);
        self::assertSame(1, TransformRecord::$mutations);
        self::assertSame('Ada', $record->rawAttribute('name'));
        self::assertTrue($record->save());
        self::assertSame(1, TransformRecord::$mutations);
        self::assertSame('Ada', $this->manager->table('transform_records')->filter('id', 1)->first()['name']);

        $loaded = TransformRecord::find(1);
        self::assertSame(1, TransformRecord::$mutations);
        $loaded->fill(['name' => ' Bob ']);
        self::assertSame(2, TransformRecord::$mutations);
        self::assertSame('Bob', $loaded->rawAttribute('name'));
        $loaded->setAttribute('name', ' Carol ');
        self::assertSame(3, TransformRecord::$mutations);
        self::assertSame('Carol', $loaded->rawAttribute('name'));
        self::assertTrue($loaded->save());
        self::assertSame(3, TransformRecord::$mutations);
        self::assertSame('Carol', $this->manager->table('transform_records')->filter('id', 1)->first()['name']);
        self::assertSame($loaded, $loaded->refresh());
        self::assertSame(3, TransformRecord::$mutations);
        self::assertFalse($loaded->changed());
    }

    public function testSavedChangesAreDistinctFromDirtyStateAndSurviveFailedWrite(): void
    {
        $first = TransformRecord::create(['name' => ' Ada ', 'slug' => 'ada']);
        TransformRecord::create(['name' => ' Other ', 'slug' => 'other']);
        self::assertTrue($first->wasChanged());
        self::assertTrue($first->wasChanged('name'));
        self::assertSame('Ada', $first->savedChanges()['name']);
        self::assertFalse($first->changed());

        $first->setAttribute('name', ' Bob ');
        self::assertTrue($first->changed('name'));
        self::assertSame('Ada', $first->savedChanges()['name']);
        self::assertTrue($first->save());
        self::assertFalse($first->changed());
        self::assertSame(['name' => 'Bob'], $first->savedChanges());
        self::assertFalse($first->wasChanged('slug'));
        self::assertTrue($first->save());
        self::assertSame(['name' => 'Bob'], $first->savedChanges());

        $first->setAttribute('slug', 'other');
        try {
            $first->save();
            self::fail('The unique constraint must reject the duplicate slug.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertTrue($first->changed('slug'));
            self::assertSame(['name' => 'Bob'], $first->savedChanges());
        }
        $first->refresh();
        self::assertSame([], $first->savedChanges());
        self::assertFalse($first->wasChanged());
        self::assertFalse($first->changed());

        self::assertTrue($first->delete());
        self::assertTrue($first->wasChanged('deleted_at'));
        self::assertTrue($first->restore());
        self::assertArrayHasKey('deleted_at', $first->savedChanges());
        self::assertNull($first->savedChanges()['deleted_at']);
        self::assertTrue($first->forceDelete());
        self::assertSame([], $first->savedChanges());
    }

    public function testRecursiveAndInvalidDeclarationsFailClearly(): void
    {
        $accessor = new RecursiveAccessorRecord();
        try {
            $accessor->getAttribute('name');
            self::fail('A recursive accessor must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('accessor is recursive', $exception->getMessage());
        }
        $mutator = new RecursiveMutatorRecord();
        try {
            $mutator->setAttribute('name', 'Ada');
            self::fail('A recursive mutator must fail.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('mutator is recursive', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        new InvalidAccessorRecord();
    }

    public function testRejectedMutatorLeavesFillAndSavedStateUnchanged(): void
    {
        $record = TransformRecord::create(['name' => ' Ada ', 'slug' => 'ada']);
        $before = $record->attributes();
        $saved = $record->savedChanges();

        try {
            $record->fill(['slug' => 'other', 'name' => 'reject']);
            self::fail('The application mutator should reject the assignment.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Rejected by application mutator.', $exception->getMessage());
        }
        self::assertSame($before, $record->attributes());
        self::assertSame($saved, $record->savedChanges());
        self::assertFalse($record->changed());
    }
}

/** A transform declaration is explicit; it has no method-name magic. */
final class TransformRecord extends Model
{
    public static int $mutations = 0;
    protected string $table = 'transform_records';
    protected array $fillable = ['name', 'slug', 'score'];
    protected array $casts = ['score' => 'integer'];
    protected bool $softDeletes = true;

    protected static function accessors(): array
    {
        return [
            'name' => static fn (mixed $value): string => strtoupper((string) $value),
            'score' => static fn (mixed $value): string => 'Score ' . (string) $value,
            'label' => static fn (mixed $value, Model $model): string => 'Member ' . $model->rawAttribute('name'),
        ];
    }

    protected static function mutators(): array
    {
        return ['name' => static function (mixed $value): string {
            self::$mutations++;
            if ($value === 'reject') {
                throw new InvalidArgumentException('Rejected by application mutator.');
            }
            return trim((string) $value);
        }];
    }
}

final class RecursiveAccessorRecord extends Model
{
    protected static function accessors(): array
    {
        return ['name' => static fn (mixed $value, Model $model): mixed => $model->getAttribute('name')];
    }
}

final class RecursiveMutatorRecord extends Model
{
    protected static function mutators(): array
    {
        return ['name' => static fn (mixed $value, Model $model): mixed => $model->setAttribute('name', $value)];
    }
}

final class InvalidAccessorRecord extends Model
{
    protected static function accessors(): array
    {
        // The non-callable fixture deliberately violates the declaration contract.
        // @phpstan-ignore-next-line
        return ['name' => false];
    }
}
