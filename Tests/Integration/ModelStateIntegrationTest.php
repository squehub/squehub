<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use DateTime;
use DateTimeImmutable;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/** Exercises v2 model state against disposable SQLite tables made by the schema builder. */
final class ModelStateIntegrationTest extends TestCase
{
    private DatabaseManager $manager;
    private Repository $config;
    private FixedModelClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for isolated model tests.');
        }

        $this->clock = new FixedModelClock('2024-01-02T03:04:05+00:00');
        $this->config = new Repository([
            'database' => [
                'default' => 'main',
                'connections' => [
                    'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]);
        $this->manager = new DatabaseManager($this->config, null, $this->clock);
        Database::setResolver(fn (): DatabaseManager => $this->manager);

        $this->manager->schema()->create('model_records', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unique('code');
            $table->string('status')->nullable();
            $table->string('note')->default('db-default');
            $table->boolean('active')->nullable();
            $table->boolean('secret_active')->nullable();
            $table->integer('score')->nullable();
            $table->string('ratio')->nullable();
            $table->text('prefs')->nullable();
            $table->string('birthday')->nullable();
            $table->datetime('due_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();
        });
        $this->manager->schema('archive')->create('named_records', static function (Table $table): void {
            $table->string('slug');
            $table->string('name');
            $table->primary('slug');
        });
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testHydrationSnapshotComparisonAndIndependentInstances(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada', 'score' => 10, 'active' => true]);
        $first = StateRecord::find(1);
        $second = StateRecord::find(1);
        self::assertInstanceOf(StateRecord::class, $first);
        self::assertInstanceOf(StateRecord::class, $second);
        self::assertTrue($first->exists());
        self::assertFalse($first->changed());
        self::assertSame([], $first->changes());
        self::assertSame(10, $first->score);
        self::assertSame(10, $first->original('score'));
        self::assertSame($first->original()['name'], 'Ada');

        // The database may return numeric text; a declared cast compares its meaning.
        $first->score = '10';
        $first->active = 1;
        self::assertFalse($first->changed());
        $first->name = 'Grace';
        self::assertTrue($first->changed('name'));
        self::assertSame(['name' => 'Grace'], $first->changes());
        self::assertSame('Ada', $first->original('name'));
        self::assertFalse($second->changed());
        $first->name = 'Ada';
        self::assertFalse($first->changed());
        $first->name = 'Lin';
        self::assertSame(['name' => 'Lin'], $first->changes());
    }

    public function testAbsentNullAndStrictUncastValuesRemainDistinct(): void
    {
        $partial = StateRecord::hydrate(['id' => 1, 'name' => 'Ada']);
        self::assertFalse($partial->changed());
        $partial->status = null;
        self::assertTrue($partial->changed('status'));
        self::assertArrayHasKey('status', $partial->changes());
        self::assertNull($partial->changes()['status']);

        $withNull = StateRecord::hydrate(['id' => 1, 'name' => 'Ada', 'status' => null]);
        $withNull->status = null;
        self::assertFalse($withNull->changed('status'));
        $withNull->status = '';
        self::assertTrue($withNull->changed('status'));
        $withNull->status = null;
        self::assertFalse($withNull->changed());

        $strict = StateRecord::hydrate(['id' => 1, 'name' => 'Ada', 'note' => 0]);
        $strict->note = false;
        self::assertTrue($strict->changed('note'));
    }

    public function testSaveInsertsOnceUpdatesOnlyPendingColumnsAndNoopIsSilent(): void
    {
        $this->manager->schema()->create('write_audit', static function (Table $table): void {
            $table->id();
            $table->string('kind');
        });
        $this->manager->raw("CREATE TRIGGER audit_all_updates AFTER UPDATE ON model_records BEGIN INSERT INTO write_audit(kind) VALUES ('update'); END");
        $this->manager->raw("CREATE TRIGGER audit_note_updates AFTER UPDATE OF note ON model_records BEGIN INSERT INTO write_audit(kind) VALUES ('note'); END");

        $record = new StateRecord(['name' => 'Ada', 'code' => 'ada']);
        self::assertFalse($record->exists());
        self::assertTrue($record->changed());
        self::assertTrue($record->save());
        self::assertTrue($record->exists());
        self::assertFalse($record->changed());
        self::assertSame(1, $this->manager->table('model_records')->count());
        self::assertTrue($record->save());
        self::assertSame(0, $this->manager->table('write_audit')->count());

        $record->name = 'Grace';
        self::assertTrue($record->save());
        self::assertSame(['update'], array_column($this->manager->table('write_audit')->all(), 'kind'));
        self::assertSame('db-default', $this->manager->table('model_records')->first()['note']);
        self::assertSame(1, $this->manager->table('model_records')->count());
        self::assertTrue($record->save());
        self::assertSame(1, $this->manager->table('write_audit')->count());
        $this->manager->connection()->disconnect();
        self::assertTrue($record->save());
        self::assertFalse($this->manager->connection()->isConnected());
    }

    public function testFailedInsertAndUpdateRetainLocalState(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada', 'code' => 'taken']);
        $fresh = new StateRecord(['name' => 'Lin', 'code' => 'taken']);
        try {
            $fresh->save();
            self::fail('The unique constraint should reject the insert.');
        } catch (QueryException) {
            self::assertFalse($fresh->exists());
            self::assertNull($fresh->id);
            self::assertTrue($fresh->changed());
            self::assertSame([], $fresh->original());
        }

        $this->manager->table('model_records')->insert(['name' => 'Bob', 'code' => 'available']);
        $stored = StateRecord::find(2);
        $stored->name = 'Robert';
        $stored->code = 'taken';
        try {
            $stored->save();
            self::fail('The unique constraint should reject the update.');
        } catch (QueryException) {
            self::assertTrue($stored->exists());
            self::assertSame('Bob', $stored->original('name'));
            self::assertSame('available', $stored->original('code'));
            self::assertSame(['name' => 'Robert', 'code' => 'taken'], $stored->changes());
            self::assertSame('Bob', StateRecord::find(2)->name);
        }
    }

    public function testPartialHydrationDoesNotOverwriteUnselectedFields(): void
    {
        $this->manager->table('model_records')->insert([
            'name' => 'Ada', 'code' => 'ada', 'status' => 'active', 'note' => 'preserve',
        ]);
        $partial = StateRecord::query()->select(['id', 'name'])->first();
        self::assertTrue($partial->exists());
        self::assertSame(['id', 'name'], array_keys($partial->attributes()));
        $partial->name = 'Grace';
        self::assertTrue($partial->save());
        $stored = $this->manager->table('model_records')->first();
        self::assertSame('Grace', $stored['name']);
        self::assertSame('active', $stored['status']);
        self::assertSame('preserve', $stored['note']);

        $withoutIdForDelete = StateRecord::query()->select(['name'])->first();
        try {
            $withoutIdForDelete->delete();
            self::fail('Deleting a partial row without its key must be rejected.');
        } catch (LogicException) {
            self::assertSame(1, $this->manager->table('model_records')->count());
        }
        $withoutId = StateRecord::query()->select(['name'])->first();
        $withoutId->name = 'Cannot save';
        $this->expectException(LogicException::class);
        $withoutId->save();
    }

    public function testFailedWriteInsideOuterTransactionLeavesTransactionAndModelForCaller(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada', 'code' => 'taken']);
        $this->manager->table('model_records')->insert(['name' => 'Bob', 'code' => 'available']);
        $record = StateRecord::find(2);
        $connection = $this->manager->connection();
        $connection->begin();
        $record->code = 'taken';
        try {
            $record->save();
            self::fail('The unique constraint should reject this write.');
        } catch (QueryException) {
            self::assertTrue($connection->pdo()->inTransaction());
            self::assertSame('available', $record->original('code'));
            self::assertSame('taken', $record->changes()['code']);
            $connection->rollback();
            self::assertSame('available', StateRecord::find(2)->code);
        }
    }

    public function testZeroAffectedUpdateChecksSameConnectionForExistingAndMissingRows(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada']);
        $record = StateRecord::find(1);
        $this->manager->raw(
            "CREATE TRIGGER ignore_model_update BEFORE UPDATE ON model_records BEGIN SELECT RAISE(IGNORE); END"
        );
        $record->name = 'Grace';
        self::assertTrue($record->save());
        self::assertFalse($record->changed());
        self::assertSame('Ada', StateRecord::find(1)->name);
        $this->manager->raw('DROP TRIGGER ignore_model_update');

        $record->name = 'Lin';
        $this->manager->table('model_records')->filter('id', 1)->delete();
        self::assertFalse($record->save());
        self::assertTrue($record->changed('name'));
        self::assertTrue($record->exists());
    }

    public function testPersistedIdentityCannotBeEditedAndStringKeysUseNamedConnection(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada']);
        $record = StateRecord::find(1);
        $record->id = 99;
        try {
            $record->save();
            self::fail('Editing a persisted primary key should be rejected.');
        } catch (LogicException) {
            self::assertSame(1, $this->manager->table('model_records')->count());
            self::assertSame('Ada', StateRecord::find(1)->name);
        }

        $named = NamedStateRecord::create(['slug' => 'alpha-01', 'name' => 'first']);
        self::assertSame('alpha-01', $named->slug);
        self::assertFalse($this->manager->schema()->hasTable('named_records'));
        self::assertSame(1, $this->manager->table('named_records', 'archive')->count());
        $loaded = NamedStateRecord::find('alpha-01');
        $loaded->name = 'second';
        self::assertTrue($loaded->save());
        self::assertSame('second', $this->manager->table('named_records', 'archive')->first()['name']);
    }

    public function testHydratedDefaultConnectionRemainsPinnedAfterConfigurationChanges(): void
    {
        $this->manager->table('model_records', 'main')->insert(['name' => 'Main']);
        $record = StateRecord::find(1);
        $this->manager->schema('archive')->create('model_records', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $this->manager->table('model_records', 'archive')->insert(['name' => 'Archive']);
        $this->config->set('database.default', 'archive');

        $record->name = 'Main changed';
        self::assertTrue($record->save());
        self::assertSame('Main changed', $this->manager->table('model_records', 'main')->first()['name']);
        self::assertSame('Archive', $this->manager->table('model_records', 'archive')->first()['name']);
        $this->manager->table('model_records', 'main')->filter('id', 1)->update(['name' => 'Main external']);
        $record->refresh();
        self::assertSame('Main external', $record->name);
    }

    public function testDeleteFailureMissingRowAndNoResurrection(): void
    {
        $this->manager->schema()->create('model_children', static function (Table $table): void {
            $table->id();
            $table->foreignId('parent_id');
            $table->foreign('parent_id', 'model_records', 'id')->onDelete('restrict');
        });
        $this->manager->table('model_records')->insert(['name' => 'Ada']);
        $record = StateRecord::find(1);
        $this->manager->table('model_children')->insert(['parent_id' => 1]);
        try {
            $record->delete();
            self::fail('The foreign key should reject deletion.');
        } catch (QueryException) {
            self::assertTrue($record->exists());
            self::assertSame(1, $this->manager->table('model_records')->count());
        }

        $this->manager->table('model_children')->filter('parent_id', 1)->delete();
        self::assertTrue($record->delete());
        self::assertFalse($record->exists());
        self::assertFalse($record->delete());
        try {
            $record->save();
            self::fail('A deleted instance must not silently reinsert its row.');
        } catch (LogicException) {
            self::assertSame(0, $this->manager->table('model_records')->count());
        }

        $this->manager->table('model_records')->insert(['name' => 'Missing']);
        $stale = StateRecord::find(2);
        $this->manager->table('model_records')->filter('id', 2)->delete();
        self::assertFalse($stale->delete());
        self::assertTrue($stale->exists());
    }

    public function testRefreshReloadsDefaultsAndDiscardsEditsOnlyAfterSuccess(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada']);
        $partial = StateRecord::hydrate(['id' => 1, 'name' => 'Ada']);
        $partial->name = 'unsaved';
        $this->manager->table('model_records')->filter('id', 1)->update(['name' => 'database']);
        $partial->refresh();
        self::assertSame('database', $partial->name);
        self::assertSame('db-default', $partial->note);
        self::assertFalse($partial->changed());
        self::assertSame('database', $partial->original('name'));

        $partial->name = 'keep pending';
        $this->manager->table('model_records')->filter('id', 1)->update(['score' => 'bad-integer']);
        $failure = null;
        try {
            $partial->refresh();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Invalid stored cast data should reject refresh.');
        self::assertSame('keep pending', $partial->name);
        self::assertSame('database', $partial->original('name'));
        self::assertTrue($partial->changed('name'));

        $this->manager->table('model_records')->filter('id', 1)->delete();
        $failure = null;
        try {
            $partial->refresh();
        } catch (LogicException $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Refreshing a missing row must fail.');
        self::assertSame('keep pending', $partial->name);
        self::assertSame('database', $partial->original('name'));

        $this->expectException(LogicException::class);
        (new StateRecord(['name' => 'new']))->refresh();
    }

    public function testCastedSerializationHidesSecretsAndNeverOpensConnection(): void
    {
        $model = StateRecord::hydrate([
            'id' => 8,
            'name' => 'Ada',
            'active' => 'false',
            'score' => '10',
            'prefs' => '{"theme":"light","count":"001"}',
            'birthday' => '2001-02-03',
            'due_at' => '2024-01-02 03:04:05',
            'password_hash' => 'never expose',
            'private_note' => 'also hidden',
            'secret_active' => '1',
        ]);
        $this->manager->connection()->disconnect();
        $result = $model->toArray();
        self::assertFalse($this->manager->connection()->isConnected());
        self::assertFalse($model->changed());
        self::assertSame(false, $result['active']);
        self::assertSame(10, $result['score']);
        self::assertSame(['theme' => 'light', 'count' => '001'], $result['prefs']);
        self::assertSame('2001-02-03', $result['birthday']);
        self::assertMatchesRegularExpression('/(Z|\\+00:00)$/', $result['due_at']);
        self::assertArrayNotHasKey('password_hash', $result);
        self::assertArrayNotHasKey('private_note', $result);
        self::assertArrayNotHasKey('secret_active', $result);
        self::assertArrayNotHasKey('original', $result);
        self::assertArrayNotHasKey('changes', $result);
        self::assertSame($result, json_decode((string) json_encode($model), true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($this->manager->connection()->isConnected());
    }

    public function testScalarCastsNormalizeSupportedValuesAndRejectUnsafeOnes(): void
    {
        $model = StateRecord::hydrate([
            'id' => 8, 'name' => 'Ada', 'score' => '10', 'ratio' => '1.5',
            'active' => 'false', 'code' => 42,
        ]);
        self::assertSame(10, $model->score);
        self::assertSame(1.5, $model->ratio);
        self::assertFalse($model->active);
        self::assertSame('42', $model->code);
        self::assertFalse($model->changed());
        $model->active = 0;
        $model->score = 10;
        $model->ratio = 1.5;
        self::assertFalse($model->changed());
        $model->active = true;
        self::assertSame(['active' => true], $model->changes());

        foreach ([
            ['score', '10.2'],
            ['score', '9223372036854775808'],
            ['score', 'private-overflow-999999999999999999999'],
            ['ratio', INF],
            ['ratio', NAN],
            ['active', 'private-boolean-value'],
            ['code', ['private', 'array']],
        ] as [$attribute, $value]) {
            $failure = null;
            try {
                $model->setAttribute($attribute, $value);
                $model->getAttribute($attribute);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, "Invalid {$attribute} input must be rejected.");
            self::assertStringContainsString($attribute, $failure->getMessage());
            self::assertStringNotContainsString('private-', $failure->getMessage());
        }

        $fresh = new StateRecord(['name' => 'before']);
        $failure = null;
        try {
            $fresh->fill(['name' => 'after', 'score' => 'invalid-score']);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertSame(['name' => 'before'], $fresh->attributes());
    }

    public function testArrayCastRoundTripsAndInvalidStoredJsonIsReported(): void
    {
        $nested = ['label' => 'café', 'numbers' => ['001', 2], 'nested' => ['enabled' => true]];
        $saved = StateRecord::create(['name' => 'Ada', 'prefs' => $nested]);
        self::assertSame($nested, $saved->prefs);
        $loaded = StateRecord::find($saved->id);
        self::assertSame($nested, $loaded->prefs);
        self::assertFalse($loaded->changed());
        self::assertSame($nested, $loaded->toArray()['prefs']);
        $copy = $loaded->prefs;
        $copy['nested']['enabled'] = false;
        self::assertFalse($loaded->changed());
        $loaded->prefs = $copy;
        self::assertTrue($loaded->changed('prefs'));
        self::assertTrue($loaded->save());
        self::assertSame($copy, StateRecord::find($saved->id)->prefs);

        $this->manager->table('model_records')->insert([
            'name' => 'JSON with large integer', 'prefs' => '{"big":9223372036854775808}',
        ]);
        self::assertSame('9223372036854775808', StateRecord::find(2)->prefs['big']);
        $this->manager->table('model_records')->insert(['name' => 'bad JSON', 'prefs' => 'private-invalid-json{']);
        $failure = null;
        try {
            $invalid = StateRecord::find(3);
            $invalid->prefs;
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('prefs', $failure->getMessage());
        self::assertStringNotContainsString('private-invalid-json', $failure->getMessage());
    }

    public function testDateCastsUseImmutableUtcValuesAndStrictCalendarParsing(): void
    {
        $record = StateRecord::hydrate([
            'id' => 7, 'name' => 'Ada', 'birthday' => '2000-02-29',
            'due_at' => '2024-01-02 03:04:05',
        ]);
        self::assertInstanceOf(DateTimeImmutable::class, $record->birthday);
        self::assertInstanceOf(DateTimeImmutable::class, $record->due_at);
        self::assertSame('2000-02-29', $record->birthday->format('Y-m-d'));
        self::assertSame('2024-01-02 03:04:05 +00:00', $record->due_at->format('Y-m-d H:i:s P'));
        self::assertFalse($record->changed());

        $mutable = new DateTime('2024-01-02T08:34:05+05:30');
        $record->due_at = $mutable;
        $mutable->modify('+1 day');
        self::assertSame('2024-01-02 03:04:05 +00:00', $record->due_at->format('Y-m-d H:i:s P'));
        self::assertFalse($record->changed('due_at'));

        $saved = StateRecord::create([
            'name' => 'dates',
            'birthday' => '2000-02-29',
            'due_at' => '2024-01-02T08:34:05+05:30',
        ]);
        $row = $this->manager->table('model_records')->filter('id', $saved->id)->first();
        self::assertSame('2000-02-29', $row['birthday']);
        self::assertSame('2024-01-02 03:04:05', $row['due_at']);
        self::assertSame('2024-01-02 03:04:05 +00:00', StateRecord::find($saved->id)->due_at->format('Y-m-d H:i:s P'));

        foreach ([['birthday', '2024-02-30'], ['due_at', '2024-13-01 03:04:05'],
            ['due_at', '2024-01-02 03:04:05 trailing'],
            ['due_at', '2024-01-02T03:04:05+15:00']] as [$attribute, $value]) {
            $failure = null;
            try {
                $record->setAttribute($attribute, $value);
                $record->getAttribute($attribute);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, "Invalid {$attribute} must fail.");
            self::assertStringContainsString($attribute, $failure->getMessage());
        }
    }

    public function testNullCastsUnknownDeclarationsAndRejectedJsonStructures(): void
    {
        $empty = StateRecord::hydrate([
            'id' => 3, 'name' => 'nulls', 'active' => null, 'score' => null,
            'ratio' => null, 'code' => null, 'prefs' => null, 'birthday' => null, 'due_at' => null,
        ]);
        foreach (['active', 'score', 'ratio', 'code', 'prefs', 'birthday', 'due_at'] as $attribute) {
            self::assertNull($empty->getAttribute($attribute));
            self::assertNull($empty->toArray()[$attribute]);
        }
        self::assertFalse($empty->changed());

        $failure = null;
        try {
            $unknown = InvalidCastStateRecord::hydrate(['id' => 4, 'name' => 'unknown']);
            $unknown->name;
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Unknown cast declarations must fail clearly.');
        self::assertStringContainsString('name', $failure->getMessage());

        $recursive = [];
        $recursive['self'] = &$recursive;
        $new = new StateRecord(['name' => 'recursive']);
        $failure = null;
        try {
            $new->prefs = $recursive;
            $new->save();
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure, 'Recursive JSON data must be rejected.');
        self::assertFalse($new->exists());
        self::assertSame(0, $this->manager->table('model_records')->count());
    }

    public function testOptInTimestampsUseControlledUtcClockAndPreserveExplicitValues(): void
    {
        $withoutTimestamps = StateRecord::create(['name' => 'plain']);
        $plainRow = $this->manager->table('model_records')->filter('id', $withoutTimestamps->id)->first();
        self::assertNull($plainRow['created_at']);
        self::assertNull($plainRow['updated_at']);

        $this->clock->set('2024-01-02T08:34:05+05:30');
        $timed = TimedStateRecord::create(['name' => 'timed']);
        $first = $this->manager->table('model_records')->filter('id', $timed->id)->first();
        self::assertSame('2024-01-02 03:04:05', $first['created_at']);
        self::assertSame($first['created_at'], $first['updated_at']);
        self::assertFalse($timed->changed());

        $this->clock->set('2024-01-03T00:00:00+00:00');
        self::assertTrue($timed->save());
        self::assertSame($first['updated_at'], $this->manager->table('model_records')->filter('id', $timed->id)->first()['updated_at']);
        $timed->name = 'renamed';
        self::assertTrue($timed->save());
        $updated = $this->manager->table('model_records')->filter('id', $timed->id)->first();
        self::assertSame($first['created_at'], $updated['created_at']);
        self::assertSame('2024-01-03 00:00:00', $updated['updated_at']);

        $imported = TimedStateRecord::create([
            'name' => 'import',
            'created_at' => '2001-02-03 04:05:06',
            'updated_at' => '2001-02-04 05:06:07',
        ]);
        $importRow = $this->manager->table('model_records')->filter('id', $imported->id)->first();
        self::assertSame('2001-02-03 04:05:06', $importRow['created_at']);
        self::assertSame('2001-02-04 05:06:07', $importRow['updated_at']);
        $imported->name = 'updated import';
        $imported->updated_at = '2002-03-04 05:06:07';
        self::assertTrue($imported->save());
        self::assertSame('2002-03-04 05:06:07', $this->manager->table('model_records')->filter('id', $imported->id)->first()['updated_at']);
    }

    public function testCustomTimestampColumnsAndFailedWriteDoNotPublishClockValues(): void
    {
        $this->manager->schema()->create('custom_timed_records', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->datetime('born_on')->nullable();
            $table->datetime('touched_on')->nullable();
        });
        $custom = CustomTimedRecord::create(['name' => 'custom']);
        $row = $this->manager->table('custom_timed_records')->first();
        self::assertSame('2024-01-02 03:04:05', $row['born_on']);
        self::assertSame($row['born_on'], $row['touched_on']);
        $this->clock->set('2024-01-02T04:00:00+00:00');
        $custom->name = 'changed';
        self::assertTrue($custom->save());
        $row = $this->manager->table('custom_timed_records')->first();
        self::assertSame('2024-01-02 03:04:05', $row['born_on']);
        self::assertSame('2024-01-02 04:00:00', $row['touched_on']);

        TimedStateRecord::create(['name' => 'first', 'code' => 'taken']);
        $failed = new TimedStateRecord(['name' => 'second', 'code' => 'taken']);
        $before = $failed->attributes();
        try {
            $failed->save();
            self::fail('The unique constraint should reject this timestamped insert.');
        } catch (QueryException) {
            self::assertFalse($failed->exists());
            self::assertSame($before, $failed->attributes());
            self::assertTrue($failed->changed());
        }

        $stored = TimedStateRecord::create(['name' => 'updating', 'code' => 'available']);
        $beforeUpdate = $stored->attributes();
        $stored->code = 'taken';
        $stored->name = 'attempted';
        $this->clock->set('2024-01-03T00:00:00+00:00');
        try {
            $stored->save();
            self::fail('The unique constraint should reject this timestamped update.');
        } catch (QueryException) {
            self::assertSame($beforeUpdate['updated_at'], $stored->updated_at);
            self::assertSame('attempted', $stored->changes()['name']);
            self::assertSame('updating', $stored->original('name'));
        }
        $stored->updated_at = '2000-01-02 03:04:05';
        try {
            $stored->save();
            self::fail('The unique constraint should still reject the update.');
        } catch (QueryException) {
            self::assertSame('2000-01-02 03:04:05', $stored->updated_at);
            self::assertSame('2000-01-02 03:04:05', $stored->changes()['updated_at']);
            self::assertSame($beforeUpdate['updated_at'], $stored->original('updated_at'));
        }
    }

    public function testEitherManagedTimestampColumnCanBeDisabled(): void
    {
        $createdOnly = CreatedOnlyStateRecord::create(['name' => 'one']);
        $row = $this->manager->table('model_records')->filter('id', $createdOnly->id)->first();
        self::assertSame('2024-01-02 03:04:05', $row['created_at']);
        self::assertNull($row['updated_at']);

        $this->clock->set('2024-01-03T00:00:00+00:00');
        $createdOnly->name = 'two';
        self::assertTrue($createdOnly->save());
        $row = $this->manager->table('model_records')->filter('id', $createdOnly->id)->first();
        self::assertSame('2024-01-02 03:04:05', $row['created_at']);
        self::assertNull($row['updated_at']);
    }

    public function testOuterTransactionRollbackLeavesLocalSnapshotStaleUntilRefresh(): void
    {
        $this->manager->table('model_records')->insert(['name' => 'Ada']);
        $record = StateRecord::find(1);
        $connection = $this->manager->connection();
        $connection->begin();
        $record->name = 'temporary';
        self::assertTrue($record->save());
        self::assertFalse($record->changed());
        $connection->rollback();
        self::assertSame('temporary', $record->name);
        self::assertSame('Ada', StateRecord::find(1)->name);
        $record->refresh();
        self::assertSame('Ada', $record->name);
        self::assertFalse($record->changed());

        $connection->begin();
        $inserted = StateRecord::create(['name' => 'rolled-back']);
        self::assertTrue($inserted->exists());
        $connection->rollback();
        self::assertNull(StateRecord::find($inserted->id));
        // A transaction-local model is discarded after rollback; its ID is not proof of a row.
        $this->expectException(LogicException::class);
        $inserted->refresh();
    }
}

class StateRecord extends Model
{
    protected string $table = 'model_records';
    protected array $fillable = ['name', 'code', 'status', 'note', 'active', 'score', 'ratio', 'prefs', 'birthday', 'due_at', 'created_at', 'updated_at'];
    protected array $hidden = ['private_note', 'secret_active'];
    protected array $casts = [
        'active' => 'boolean',
        'secret_active' => 'boolean',
        'score' => 'integer',
        'ratio' => 'float',
        'code' => 'string',
        'prefs' => 'array',
        'birthday' => 'date',
        'due_at' => 'datetime',
    ];
}

final class TimedStateRecord extends StateRecord
{
    protected bool $timestamps = true;
}

final class CreatedOnlyStateRecord extends StateRecord
{
    protected bool $timestamps = true;
    protected ?string $updatedAtColumn = null;
}

final class InvalidCastStateRecord extends Model
{
    protected string $table = 'model_records';
    protected array $casts = ['name' => 'unknown'];
}

final class CustomTimedRecord extends Model
{
    protected string $table = 'custom_timed_records';
    protected array $fillable = ['name'];
    protected bool $timestamps = true;
    protected ?string $createdAtColumn = 'born_on';
    protected ?string $updatedAtColumn = 'touched_on';
}

/** Mutable test clock; each test gets a fresh DatabaseManager and clock instance. */
final class FixedModelClock implements ModelClock
{
    private DateTimeImmutable $instant;

    public function __construct(string $instant)
    {
        $this->set($instant);
    }

    public function set(string $instant): void
    {
        $this->instant = new DateTimeImmutable($instant);
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}

final class NamedStateRecord extends Model
{
    protected string $table = 'named_records';
    protected string $primaryKey = 'slug';
    protected ?string $connection = 'archive';
    protected array $fillable = ['slug', 'name'];
}
