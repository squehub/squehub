<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ManyToMany;

use App\Config\Repository;
use App\Database\Database;
use App\Database\Collections\ModelCollection;
use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use App\Database\Model;
use App\Database\Relations\BelongsToMany;
use App\Database\Relations\RelationException;
use App\Database\Schema\Table;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Exercises pivot constraints, edge isolation, and bounded eager loading on real SQLite tables. */
final class BelongsToManyTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for pivot integration tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'other' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $schema = $this->manager->schema();
        $schema->create('users', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('roles', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active');
        });
        $schema->create('user_roles', static function (Table $table): void {
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->string('assigned_by')->nullable();
            $table->unique(['user_id', 'role_id']);
            $table->foreign('user_id', 'users', 'id');
            $table->foreign('role_id', 'roles', 'id');
        });
        $schema->create('accounts', static function (Table $table): void {
            $table->string('uuid');
            $table->primary('uuid');
            $table->string('name');
        });
        $schema->create('permissions', static function (Table $table): void {
            $table->string('code');
            $table->primary('code');
            $table->string('name');
        });
        $schema->create('account_permissions', static function (Table $table): void {
            $table->string('account_uuid');
            $table->string('permission_code');
            $table->string('source');
            $table->unique(['account_uuid', 'permission_code']);
        });
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testLazyQueriesPivotIsolationAndPropertyCache(): void
    {
        $this->seed();
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 1, 'assigned_by' => 'admin-a']);
        $this->manager->table('user_roles')->insert(['user_id' => 2, 'role_id' => 1, 'assigned_by' => 'admin-b']);
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 2]);

        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PivotCountingStatement::class]);
        PivotCountingStatement::$executions = 0;
        $a = User::find(1);
        $b = User::find(2);
        $before = PivotCountingStatement::$executions;
        self::assertInstanceOf(ModelCollection::class, $a->roles);
        $cachedRoles = $a->roles;
        self::assertCount(2, $cachedRoles);
        self::assertSame($before + 2, PivotCountingStatement::$executions);
        self::assertCount(2, $a->roles);
        self::assertSame($cachedRoles, $a->roles);
        self::assertSame($before + 2, PivotCountingStatement::$executions);
        self::assertSame('admin-a', $a->roles[0]->pivot()['assigned_by']);
        self::assertSame('admin-b', $b->roles[0]->pivot()['assigned_by']);
        self::assertNotSame($a->roles[0], $b->roles[0]);
        self::assertArrayNotHasKey('assigned_by', $a->roles[0]->attributes());
        self::assertFalse($a->roles[0]->changed());
        self::assertArrayNotHasKey('pivot', $a->roles[0]->toArray());
        self::assertArrayNotHasKey('assigned_by', $a->roles[0]->jsonSerialize());
        self::assertTrue(User::find(3)->roles->isEmpty());
        self::assertCount(1, $a->roles()->filter('active', true)->get());
        self::assertSame('editor', $a->roles()->pivotFilter('assigned_by', null)->first()->name);
        self::assertSame('admin-a', $a->roles()->first()->pivot()['assigned_by']);
        self::assertSame(['user_id' => 1, 'role_id' => 1], $a->plainRoles()->first()->pivot());
        self::assertTrue($a->roles[0]->save());
        self::assertSame(3, $this->manager->table('user_roles')->count());
        $a->load('roles');
        self::assertCount(2, $a->roles);
    }

    public function testNestedEagerLoadingPreservesPerEdgePivotModelsAndBoundedQueries(): void
    {
        $this->seed();
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 1, 'assigned_by' => 'A']);
        $this->manager->table('user_roles')->insert(['user_id' => 2, 'role_id' => 1, 'assigned_by' => 'B']);
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 2, 'assigned_by' => 'C']);
        PivotCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [PivotCountingStatement::class]);

        $users = User::query()->with('roles.users')->sort('id')->get();
        self::assertSame(5, PivotCountingStatement::$executions);
        self::assertSame('A', $users[0]->roles[0]->pivot()['assigned_by']);
        self::assertSame('B', $users[1]->roles[0]->pivot()['assigned_by']);
        self::assertNotSame($users[0]->roles[0], $users[1]->roles[0]);
        self::assertTrue($users[0]->roles[0]->relationLoaded('users'));
        self::assertTrue($users[1]->roles[0]->relationLoaded('users'));
        self::assertCount(2, $users[0]->roles[0]->users);
        self::assertSame(5, PivotCountingStatement::$executions);
    }

    public function testAttachDetachSyncAndRealConstraints(): void
    {
        $this->seed();
        $user = User::find(1);
        $role = Role::find(1);
        self::assertSame(1, $user->roles()->attach($role, ['assigned_by' => 'owner']));
        self::assertSame(0, $user->roles()->attach(1));
        self::assertSame(2, $user->roles()->attach([2, 3]));
        self::assertCount(3, $user->roles);
        self::assertSame(1, $user->roles()->detach([2, 99]));
        self::assertFalse($user->relationLoaded('roles'));
        self::assertSame(0, $user->roles()->detach([]));
        self::assertSame(3, $this->manager->table('roles')->count());
        self::assertSame(['attached' => 1, 'detached' => 1], $user->roles()->sync([1, 2, 2]));
        self::assertSame('owner', $this->manager->table('user_roles')->filter('role_id', 1)->first()['assigned_by']);
        self::assertSame(['attached' => 0, 'detached' => 0], $user->roles()->sync([1, 2]));
        self::assertSame(['attached' => 0, 'detached' => 2], $user->roles()->sync([]));
        self::assertSame(0, $user->roles()->detachAll());
        self::assertSame(1, $user->roles()->attach(1));
        self::assertSame(1, $user->roles()->detachAll());
        self::assertSame(0, $this->manager->table('user_roles')->count());
        self::assertSame(3, $this->manager->table('roles')->count());

        $this->expectException(QueryException::class);
        $user->roles()->attach(999); // The SQLite foreign key rejects a missing role.
    }

    public function testFailuresPreserveCacheAndSyncRollsBackItsOwnTransaction(): void
    {
        $this->seed();
        $user = User::find(1);
        $user->roles()->attach([1, 2]);
        $cached = $user->roles;
        try {
            $user->roles()->attach(3, ['user_id' => 2]);
            self::fail('Pivot keys must not be overridable.');
        } catch (RelationException) {
            self::assertSame($cached, $user->roles);
        }
        try {
            $user->roles()->sync([2, 3, 999]);
            self::fail('The foreign key must reject a missing related row.');
        } catch (QueryException) {
            self::assertTrue($user->relationLoaded('roles'));
            self::assertSame($cached, $user->roles);
            self::assertSame([1, 2], array_map('intval', array_column(
                $this->manager->table('user_roles')->select(['role_id'])->sort('role_id')->all(), 'role_id'
            )));
        }
        $pdo = $this->manager->connection()->pdo();
        self::assertFalse($pdo->inTransaction());
        self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->manager->begin();
        try {
            $user->roles()->sync([2, 3, 999]);
            self::fail('The caller-owned transaction must still report the foreign-key error.');
        } catch (QueryException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame($cached, $user->roles);
        }
        $this->manager->rollback();
        self::assertSame([1, 2], array_map('intval', array_column(
            $this->manager->table('user_roles')->select(['role_id'])->sort('role_id')->all(), 'role_id'
        )));
        $this->manager->begin();
        self::assertSame(['attached' => 1, 'detached' => 1], $user->roles()->sync([2, 3]));
        self::assertTrue($pdo->inTransaction());
        $this->manager->rollback();
        $user->load('roles');
        self::assertSame([1, 2], $user->roles->map(static fn (Role $role): int => (int) $role->id));
    }

    public function testCompositeUniqueConstraintRejectsDirectDuplicate(): void
    {
        $this->seed();
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        $this->expectException(QueryException::class);
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
    }

    public function testIdentityValidationCustomKeysPartialRecordsAndConnectionPolicy(): void
    {
        $this->seed();
        $account = new Account(['uuid' => 'acct-01', 'name' => 'A']);
        $account->save();
        $permission = new Permission(['code' => 'read', 'name' => 'Read']);
        $permission->save();
        self::assertSame(1, $account->permissions()->attach($permission, ['source' => 'manual']));
        self::assertSame('read', $account->permissions[0]->code);
        self::assertSame('manual', $account->permissions[0]->pivot()['source']);
        self::assertSame(['attached' => 0, 'detached' => 0], $account->permissions()->sync(['read', 'read']));
        self::assertSame(1, $account->permissions()->detach('read'));
        self::assertTrue($account->permissions->isEmpty());

        $partial = User::query()->select(['name'])->with('roles')->first();
        self::assertTrue($partial->roles->isEmpty());
        $partial->load('roles');
        self::assertTrue($partial->roles->isEmpty());
        $this->expectException(RelationException::class);
        $partial->roles()->attach(1);
    }

    public function testRejectedInputsAndCrossConnection(): void
    {
        $this->seed();
        $user = User::find(1);
        foreach ([
            static fn () => (new User())->roles()->attach(1),
            static fn () => $user->roles()->attach(new Role()),
            static fn () => $user->roles()->attach(1, ['role_id' => 2]),
            static fn () => $user->roles()->sync([1 => ['assigned_by' => 'x']]),
            static fn () => $user->roles()->attach([1, [2]]),
            fn () => $user->roles()->attach(Role::hydrate(
                ['id' => 1], $this->manager->connection('other')
            )),
            static fn () => $user->otherRoles(),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Invalid relationship input should fail.');
            } catch (RelationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLargeEagerLoadAndLargeSyncStayBounded(): void
    {
        $this->seed();
        for ($id = 1; $id <= 501; $id++) {
            if ($id > 3) {
                $this->manager->table('users')->insert(['name' => 'U' . $id]);
            }
            $this->manager->table('user_roles')->insert([
                'user_id' => $id, 'role_id' => 1, 'assigned_by' => 'U' . $id,
            ]);
        }
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PivotCountingStatement::class]);
        PivotCountingStatement::$executions = 0;
        $users = User::query()->with('roles')->all();
        self::assertCount(501, $users);
        self::assertLessThanOrEqual(5, PivotCountingStatement::$executions);
        self::assertSame('U1', $users[0]->roles[0]->pivot()['assigned_by']);
        self::assertSame('U501', $users[500]->roles[0]->pivot()['assigned_by']);
        self::assertNotSame($users[0]->roles[0], $users[500]->roles[0]);
        self::assertSame(4, PivotCountingStatement::$executions);

        for ($id = 4; $id <= 520; $id++) {
            $this->manager->table('roles')->insert(['name' => 'R' . $id, 'active' => true]);
        }
        $result = $users[0]->roles()->sync(range(1, 520));
        self::assertSame(['attached' => 519, 'detached' => 0], $result);
        self::assertSame(520, $this->manager->table('user_roles')->filter('user_id', 1)->count());
    }

    private function seed(): void
    {
        foreach (['Ada', 'Bob', 'Cy'] as $name) {
            $this->manager->table('users')->insert(['name' => $name]);
        }
        foreach ([['admin', true], ['editor', false], ['viewer', true]] as [$name, $active]) {
            $this->manager->table('roles')->insert(['name' => $name, 'active' => $active]);
        }
    }
}

final class User extends Model
{
    protected string $table = 'users';
    protected array $guarded = [];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->pivot(['assigned_by']);
    }

    public function plainRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function otherRoles(): BelongsToMany
    {
        return $this->belongsToMany(OtherRole::class, 'user_roles');
    }
}

final class Role extends Model
{
    protected string $table = 'roles';
    protected array $guarded = [];
    protected array $hidden = ['active'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}

final class OtherRole extends Model
{
    protected string $table = 'roles';
    protected ?string $connection = 'other';
}

final class Account extends Model
{
    protected string $table = 'accounts';
    protected string $primaryKey = 'uuid';
    protected array $guarded = [];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class, 'account_permissions', 'account_uuid', 'permission_code', 'uuid', 'code'
        )->pivot(['source']);
    }
}

final class Permission extends Model
{
    protected string $table = 'permissions';
    protected string $primaryKey = 'code';
    protected array $guarded = [];
}

/** Counts executed PDO statements for the large eager-loading regression. */
final class PivotCountingStatement extends PDOStatement
{
    public static int $executions = 0;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
