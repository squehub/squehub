<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\RelationCountProjection;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\Relations\BelongsToMany;
use App\Database\Relations\HasMany;
use App\Database\Relations\RelationException;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Correlated relation counts stay in SQL and outside Model persistence state. */
final class RelationCountProjectionTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for relation count integration tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'other' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $pdo = $this->manager->connection()->pdo();
        $pdo->exec('CREATE TABLE count_users (id INTEGER PRIMARY KEY, name TEXT, rank INTEGER)');
        $pdo->exec('CREATE TABLE count_posts (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE count_roles (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE count_user_roles (user_id INTEGER, role_id INTEGER, enabled INTEGER)');
        $pdo->exec('CREATE TABLE count_conflicting_users (id INTEGER PRIMARY KEY, posts_count INTEGER)');
        $this->manager->connection('other')->pdo()->exec(
            'CREATE TABLE count_other_posts (id INTEGER PRIMARY KEY, user_id INTEGER)'
        );
        $this->manager->table('count_users')->insertMany([
            ['id' => 1, 'name' => 'Ada', 'rank' => 1],
            ['id' => 2, 'name' => 'Bea', 'rank' => 1],
            ['id' => 3, 'name' => 'Cy', 'rank' => 2],
        ]);
        $this->manager->table('count_posts')->insertMany([
            ['id' => 10, 'user_id' => 1, 'status' => 'approved', 'deleted_at' => null],
            ['id' => 11, 'user_id' => 1, 'status' => 'draft', 'deleted_at' => null],
            ['id' => 12, 'user_id' => 1, 'status' => 'approved', 'deleted_at' => '2024-01-01'],
            ['id' => 13, 'user_id' => 2, 'status' => 'approved', 'deleted_at' => null],
        ]);
        $this->manager->table('count_roles')->insertMany([
            ['id' => 1, 'name' => 'reader', 'deleted_at' => null],
            ['id' => 2, 'name' => 'archived', 'deleted_at' => '2024-01-01'],
        ]);
        $this->manager->table('count_user_roles')->insertMany([
            ['user_id' => 1, 'role_id' => 1, 'enabled' => 1],
            ['user_id' => 2, 'role_id' => 2, 'enabled' => 1],
            ['user_id' => 3, 'role_id' => 1, 'enabled' => 0],
        ]);
        $this->manager->table('count_conflicting_users')->insert([
            'id' => 1, 'posts_count' => 99,
        ]);
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testMultipleCountsUseOneRootSelectAndRespectSoftDeletesPivotAndScope(): void
    {
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RelationCountStatement::class]);
        RelationCountStatement::$executions = 0;
        $query = CountUser::query()->withCount(['posts', 'roles'])->sort('id');
        self::assertSame(0, RelationCountStatement::$executions);
        $rows = $query->all();
        self::assertSame(1, RelationCountStatement::$executions);
        self::assertSame([2, 1, 0], $rows->pluck('posts_count'));
        self::assertSame([1, 0, 0], $rows->pluck('roles_count'));
        self::assertSame(1, CountUser::query()->withCount(['posts' =>
            static fn (ModelQuery $posts): ModelQuery => $posts->scope('approved')])
            ->filter('id', 1)->first()?->getAttribute('posts_count'));
        self::assertSame(3, CountUser::query()->withCount(['posts' =>
            static fn (ModelQuery $posts): ModelQuery => $posts->withDeleted()])
            ->filter('id', 1)->first()?->getAttribute('posts_count'));
    }

    public function testCountIsVisibleButNeverDirtyOrPersisted(): void
    {
        $user = CountUser::query()->withCount('posts')->filter('id', 1)->first();
        self::assertInstanceOf(CountUser::class, $user);
        self::assertSame(2, $user->getAttribute('posts_count'));
        self::assertSame(2, $user->toArray()['posts_count']);
        self::assertFalse(array_key_exists('posts_count', $user->attributes()));
        self::assertFalse(array_key_exists('posts_count', $user->original()));
        self::assertFalse($user->changed());
        self::assertSame([], $user->changes());
        self::assertSame([], $user->savedChanges());
        try {
            $user->setAttribute('posts_count', 9);
            self::fail('A query projection must be read-only.');
        } catch (LogicException) {
            self::assertSame(2, $user->getAttribute('posts_count'));
        }
        $user->setAttribute('name', 'Ada updated');
        self::assertTrue($user->changed('name'));
        self::assertTrue($user->save());
        self::assertSame('Ada updated', $this->manager->table('count_users')->filter('id', 1)->first()['name']);
        self::assertSame(['name' => 'Ada updated'], $user->savedChanges());
        self::assertSame(2, $user->getAttribute('posts_count'));
        $user->refresh();
        self::assertNull($user->getAttribute('posts_count'));
        self::assertFalse(array_key_exists('posts_count', $user->toArray()));
    }

    public function testOffsetCountOmitsProjectionBindingsAndCursorUsesStableTieBreaker(): void
    {
        $query = CountUser::query()->withCount(['posts' =>
            static fn (ModelQuery $posts): ModelQuery => $posts->filter('status', 'approved')])
            ->filter('name', 'Ada')->sort('id');
        self::assertSame(1, $query->count());
        self::assertTrue($query->exists());
        $page = $query->page(1, 2);
        self::assertSame(1, $page->total());
        self::assertSame(1, $page->items()->count());
        self::assertSame(1, $page->items()->first()->getAttribute('posts_count'));
        $first = CountUser::query()->withCount('posts')->sort('rank')->cursorPage(1);
        self::assertSame(1, $first->items()->first()->getAttribute('id'));
        self::assertSame(2, $first->items()->first()->getAttribute('posts_count'));
        self::assertTrue($first->hasMore());
        $second = CountUser::query()->withCount('posts')->sort('rank')->cursorPage(1, $first->nextCursor());
        self::assertSame(2, $second->items()->first()->getAttribute('id'));
        self::assertSame(1, $second->items()->first()->getAttribute('posts_count'));
    }

    public function testInvalidCountAndAliasCollisionDoNotBecomePersistedState(): void
    {
        $query = CountUser::query()->filter('id', 1);
        $before = [$query->toSql(), $query->bindings()];
        foreach (['missing', 'posts;DELETE', 'posts.author'] as $name) {
            try {
                $query->withCount($name);
                self::fail('An invalid relation count must fail.');
            } catch (RelationException) {
                self::assertSame($before, [$query->toSql(), $query->bindings()]);
            }
        }
        $query->withCount('posts');
        try {
            $query->withCount('posts');
            self::fail('Duplicate count aliases must fail.');
        } catch (RelationException) {
            self::assertSame(2, $query->first()?->getAttribute('posts_count'));
        }
        $this->expectException(LogicException::class);
        CountConflictingUser::query()->withCount('posts')->first();
    }

    public function testCrossConnectionCountFailsBeforeQueryMutation(): void
    {
        $query = CountUser::query()->filter('id', 1);
        $before = [$query->toSql(), $query->bindings()];
        try {
            $query->withCount('otherPosts');
            self::fail('Counts must not silently cross connections.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('same database connection', $failure->getMessage());
            self::assertSame($before, [$query->toSql(), $query->bindings()]);
        }
    }

    public function testDeclaredAccessorCannotBeShadowedByCountProjection(): void
    {
        self::assertSame(777, CountAccessorUser::find(1)?->getAttribute('posts_count'));
        $this->expectException(LogicException::class);
        CountAccessorUser::query()->withCount('posts')->first();
    }

    public function testDeclaredMutatorCannotBeShadowedByCountProjection(): void
    {
        $this->expectException(LogicException::class);
        CountMutatorUser::query()->withCount('posts')->first();
    }
}

/** A parent fixture with two counted relationship types. */
final class CountUser extends Model
{
    protected string $table = 'count_users';

    public function posts(): HasMany { return $this->hasMany(CountPost::class, 'user_id'); }
    public function otherPosts(): HasMany { return $this->hasMany(CountOtherPost::class, 'user_id'); }
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(CountRole::class, 'count_user_roles', 'user_id', 'role_id')
            ->pivotFilter('enabled', 1);
    }
}

final class CountConflictingUser extends Model
{
    protected string $table = 'count_conflicting_users';

    public function posts(): HasMany { return $this->hasMany(CountPost::class, 'user_id'); }
}

final class CountAccessorUser extends Model
{
    protected string $table = 'count_users';

    protected static function accessors(): array
    {
        return ['posts_count' => static fn (mixed $value): int => 777];
    }

    public function posts(): HasMany { return $this->hasMany(CountPost::class, 'user_id'); }
}

final class CountMutatorUser extends Model
{
    protected string $table = 'count_users';

    protected static function mutators(): array
    {
        return ['posts_count' => static fn (mixed $value): mixed => $value];
    }

    public function posts(): HasMany { return $this->hasMany(CountPost::class, 'user_id'); }
}

final class CountPost extends Model
{
    protected string $table = 'count_posts';
    protected bool $softDeletes = true;

    protected static function scopes(): array
    {
        return ['approved' => static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'approved')];
    }
}

final class CountOtherPost extends Model
{
    protected string $table = 'count_other_posts';
    protected ?string $connection = 'other';
}

final class CountRole extends Model
{
    protected string $table = 'count_roles';
    protected bool $softDeletes = true;
}

/** Counts executed statements without relying on elapsed-time diagnostics. */
final class RelationCountStatement extends PDOStatement
{
    public static int $executions = 0;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
