<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\Repository;
use App\Database\Database;
use App\Database\Collections\ModelCollection;
use App\Database\DatabaseManager;
use App\Database\Model;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ModelFoundationTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for Model integration tests.');
        }

        $this->manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'main',
                'connections' => [
                    'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                    'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
        ]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->connection('main')->pdo()->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, status TEXT)'
        );
        $this->manager->connection('archive')->pdo()->exec(
            'CREATE TABLE archived_users (slug TEXT PRIMARY KEY, name TEXT)'
        );
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testQueryUsesModelMetadataAndHydratesExistingObjects(): void
    {
        $this->manager->table('users')->insert(['name' => 'Ada', 'status' => 'active']);
        $this->manager->table('users')->insert(['name' => 'Lin', 'status' => 'inactive']);

        $query = UserRecord::query()->filter('status', 'active')->sort('name', 'desc');
        self::assertSame('SELECT * FROM `users` WHERE `status` = ? ORDER BY `name` DESC', $query->toSql());
        self::assertSame(['active'], $query->bindings());

        $users = $query->all();
        self::assertInstanceOf(ModelCollection::class, $users);
        self::assertInstanceOf(ModelCollection::class, UserRecord::query()->get());
        self::assertCount(1, $users);
        self::assertInstanceOf(UserRecord::class, $users[0]);
        self::assertTrue($users[0]->exists());
        self::assertSame('Ada', $users[0]->name);
        self::assertSame('Ada', $users[0]->original()['name']);
        self::assertFalse($users[0]->isDirty());
        self::assertSame('Ada', UserRecord::query()->filter('name', 'Ada')->first()->name);
        self::assertNull(UserRecord::query()->filter('name', 'missing')->first());
    }

    public function testConstructingModelQueryDoesNotOpenConnection(): void
    {
        $this->manager = new DatabaseManager(new Repository([
            'database' => [
                'default' => 'main',
                'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ]));

        $connection = $this->manager->connection();
        self::assertFalse($connection->isConnected());
        self::assertSame('SELECT * FROM `users` WHERE `status` = ?',
            UserRecord::query()->filter('status', 'active')->toSql());
        self::assertFalse($connection->isConnected());
    }

    public function testCreateFindUpdateDeleteAndSerialization(): void
    {
        $created = UserRecord::create(['name' => 'Valentine', 'status' => 'active']);
        self::assertTrue($created->exists());
        self::assertSame('1', $created->id);
        self::assertSame('Valentine', $created->name);
        self::assertSame(1, UserRecord::query()->filter('status', 'active')->count());
        self::assertTrue(UserRecord::query()->filter('status', 'active')->exists());
        self::assertSame($created->toArray(), $created->jsonSerialize());

        $found = UserRecord::find($created->id);
        self::assertInstanceOf(UserRecord::class, $found);
        self::assertSame('Valentine', $found->name);
        self::assertSame('Valentine', UserRecord::query()->find($created->id)->name);
        self::assertNull(UserRecord::find(999));

        $found->name = 'Val';
        self::assertTrue($found->isDirty('name'));
        self::assertTrue($found->save());
        self::assertFalse($found->isDirty());
        self::assertSame('Val', UserRecord::find($created->id)->name);
        self::assertTrue($found->delete());
        self::assertFalse($found->exists());
        self::assertFalse($found->delete());
        self::assertNull(UserRecord::find($created->id));
    }

    public function testMassAssignmentDeniesUnknownFieldsButDirectAssignmentIsExplicit(): void
    {
        $model = new UserRecord();
        try {
            $model->fill(['name' => 'Ada', 'is_admin' => true]);
            self::fail('Unknown mass-assigned field should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame([], $model->attributes());
        }

        $model->name = 'Ada';
        $model->status = 'active';
        self::assertTrue($model->isDirty());
        self::assertTrue($model->save());
        self::assertSame('Ada', UserRecord::find($model->id)->name);

        $this->expectException(InvalidArgumentException::class);
        LockedRecord::create(['name' => 'blocked']);
    }

    public function testCustomTablePrimaryKeyAndConnectionAreHonored(): void
    {
        $metadata = new ArchivedUserRecord();
        self::assertSame('archived_users', $metadata->tableName());
        self::assertSame('slug', $metadata->primaryKeyName());
        self::assertSame('archive', $metadata->connectionName());

        $record = ArchivedUserRecord::create(['slug' => 'ada', 'name' => 'Ada']);
        self::assertSame('ada', $record->slug);
        self::assertSame('Ada', ArchivedUserRecord::find('ada')->name);
        self::assertSame(1, $this->manager->table('archived_users', 'archive')->count());
        self::assertTrue($record->delete());
        self::assertNull(ArchivedUserRecord::find('ada'));
    }

    public function testHydrationBypassesFillableWithoutIssuingWrites(): void
    {
        $model = UserRecord::hydrate(['id' => 9, 'name' => 'Loaded', 'is_admin' => 1]);
        self::assertTrue($model->exists());
        self::assertSame(1, $model->is_admin);
        self::assertSame(0, $this->manager->table('users')->count());
        self::assertTrue($model->save()); // No changes, so this remains a no-op success.
    }

    public function testSerializationHidesCommonSecretsAndConfiguredFields(): void
    {
        $model = UserRecord::hydrate([
            'id' => 9,
            'name' => 'Loaded',
            'password_hash' => 'hashed-value',
            'apiToken' => 'private-token',
            'internal_note' => 'private-note',
        ]);
        self::assertSame('hashed-value', $model->getAttribute('password_hash'));
        self::assertSame('private-note', $model->attributes()['internal_note']);
        self::assertSame(['id' => 9, 'name' => 'Loaded'], $model->toArray());
        self::assertSame('{"id":9,"name":"Loaded"}', json_encode($model));
    }

    public function testExistingModelCannotWriteWithoutPrimaryKey(): void
    {
        $model = UserRecord::hydrate(['name' => 'Missing ID']);
        $model->name = 'New name';

        $this->expectException(LogicException::class);
        $model->save();
    }

    public function testSimpleTableInference(): void
    {
        self::assertSame('user_profiles', (new UserProfile())->tableName());
        self::assertSame('addresses', (new Address())->tableName());
        self::assertSame('categories', (new Category())->tableName());
        self::assertSame('statuses', (new Status())->tableName());
    }
}

final class UserRecord extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'status'];
    protected array $hidden = ['internal_note'];
}

final class ArchivedUserRecord extends Model
{
    protected string $table = 'archived_users';
    protected string $primaryKey = 'slug';
    protected ?string $connection = 'archive';
    protected array $fillable = ['slug', 'name'];
}

final class LockedRecord extends Model
{
    protected string $table = 'users';
}

final class UserProfile extends Model
{
}

final class Address extends Model
{
}

final class Category extends Model
{
}

final class Status extends Model
{
}
