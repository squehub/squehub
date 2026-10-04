<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Cursor;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Pagination\CursorPage;
use App\Database\Relations\HasMany;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** SQLite integration for keyset ordering, query isolation, and eager hydration. */
final class CursorPaginationTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for cursor pagination tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $pdo = $this->manager->connection()->pdo();
        $pdo->exec('CREATE TABLE cursor_users (id INTEGER PRIMARY KEY, name TEXT, rank_value INTEGER, status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE cursor_posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE cursor_codes (code TEXT PRIMARY KEY, rank_value INTEGER)');
        foreach ([
            [1, 'one', 1, 'active'], [2, 'two', 1, 'active'], [3, 'three', 2, 'active'],
            [4, 'four', 2, 'inactive'], [5, 'five', 3, 'active'],
        ] as [$id, $name, $rank, $status]) {
            $this->manager->table('cursor_users')->insert([
                'id' => $id, 'name' => $name, 'rank_value' => $rank, 'status' => $status,
            ]);
            $this->manager->table('cursor_posts')->insert(['user_id' => $id, 'title' => 'post-' . $id]);
        }
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testCompositeForwardPagesUseTieBreakerWithoutCountOrStateMutation(): void
    {
        $query = $this->manager->table('cursor_users')->sort('rank_value');
        $original = $query->toSql();
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CursorStatement::class]);
        CursorStatement::$executions = 0;

        $first = $query->cursorPage(2);
        self::assertInstanceOf(CursorPage::class, $first);
        self::assertSame([1, 2], array_map('intval', array_column($first->items(), 'id')));
        self::assertTrue($first->hasMore());
        self::assertNotNull($first->nextCursor());
        self::assertSame(1, CursorStatement::$executions);
        self::assertSame($original, $query->toSql());

        $second = $query->cursorPage(2, $first->nextCursor());
        self::assertSame([3, 4], array_map('intval', array_column($second->items(), 'id')));
        $third = $query->cursorPage(2, $second->nextCursor());
        self::assertSame([5], array_map('intval', array_column($third->items(), 'id')));
        self::assertFalse($third->hasMore());
        self::assertNull($third->nextCursor());
        self::assertSame(3, CursorStatement::$executions);
        self::assertSame($first->toArray(), json_decode($first->toJson(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['items', 'per_page', 'next_cursor', 'has_more'], array_keys($first->toArray()));
        self::assertInstanceOf(\App\Plugins\CursorPage::class, $first);
    }

    public function testDescendingDefaultAndInsertBetweenPages(): void
    {
        $descending = $this->manager->table('cursor_users')->sort('rank_value', 'desc');
        $first = $descending->cursorPage(2);
        self::assertSame([5, 3], array_map('intval', array_column($first->items(), 'id')));
        $second = $descending->cursorPage(2, $first->nextCursor());
        self::assertSame([4, 1], array_map('intval', array_column($second->items(), 'id')));
        $keyDescending = $this->manager->table('cursor_users')->sort('id', 'desc');
        $keyFirst = $keyDescending->cursorPage(2);
        self::assertSame([5, 4], array_map('intval', array_column($keyFirst->items(), 'id')));
        self::assertSame([3, 2], array_map('intval', array_column(
            $keyDescending->cursorPage(2, $keyFirst->nextCursor())->items(), 'id'
        )));

        $byId = $this->manager->table('cursor_users');
        $start = $byId->cursorPage(2);
        $this->manager->table('cursor_users')->insert(['id' => 6, 'name' => 'six', 'rank_value' => 4]);
        $next = $byId->cursorPage(2, $start->nextCursor());
        $last = $byId->cursorPage(2, $next->nextCursor());
        self::assertSame([3, 4, 5, 6], array_merge(
            array_map('intval', array_column($next->items(), 'id')),
            array_map('intval', array_column($last->items(), 'id'))
        ));
    }

    public function testInvalidReplayAndUnsupportedShapesFailBeforeSql(): void
    {
        $query = $this->manager->table('cursor_users')->filter('status', 'active')->sort('id');
        $cursor = $query->cursorPage(1)->nextCursor();
        self::assertNotNull($cursor);
        foreach (['not a cursor', str_repeat('A', 9000), substr($cursor, 0, -1)
                . (str_ends_with($cursor, '0') ? '1' : '0'),
            substr($cursor, 0, -1) . '!'] as $bad) {
            try {
                $query->cursorPage(1, $bad);
                self::fail('Malformed cursor was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->manager->table('cursor_users')->sort('id')->cursorPage(1, $cursor));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $query->cursorPage(2, $cursor));
        $this->expectFailure(LogicException::class,
            fn () => $query->select(['name'])->cursorPage(1));
        $this->expectFailure(LogicException::class,
            fn () => $this->manager->table('cursor_users')->skip(0)->cursorPage(2));
        $this->expectFailure(LogicException::class,
            fn () => $this->manager->table('cursor_users')->sort('id')->sort('id')->cursorPage(2));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->manager->table('cursor_users')->cursorPage(0));
    }

    public function testModelPagesRespectSoftDeletesAndBatchEagerLoading(): void
    {
        $this->manager->table('cursor_users')->filter('id', 2)->update(['deleted_at' => '2024-01-01 00:00:00']);
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CursorStatement::class]);
        CursorStatement::$executions = 0;
        $query = CursorUser::query()->with('posts')->sort('rank_value');
        $first = $query->cursorPage(2);
        self::assertInstanceOf(ModelCollection::class, $first->items());
        self::assertSame([1, 3], $first->items()->map(static fn (CursorUser $user): int => $user->id));
        $firstUser = $first->items()->first();
        self::assertInstanceOf(CursorUser::class, $firstUser);
        self::assertSame('post-1', $firstUser->posts->first()->getAttribute('title'));
        self::assertSame(2, CursorStatement::$executions);
        $next = $query->cursorPage(2, $first->nextCursor());
        self::assertSame([4, 5], $next->items()->map(static fn (CursorUser $user): int => $user->id));
        self::assertFalse($next->hasMore());
        self::assertSame(4, CursorStatement::$executions);
        self::assertSame([2], CursorUser::query()->onlyDeleted()->cursorPage(2)->items()
            ->map(static fn (CursorUser $user): int => $user->id));
        self::assertSame(5, count(CursorUser::query()->withDeleted()->cursorPage(10)->items()));
        self::assertSame([1, 3, 5], CursorUser::query()
            ->filterGroup(static function ($group): void {
                $group->filter('status', 'active')->orFilter('id', 2);
            })->cursorPage(10)->items()->map(static fn (CursorUser $user): int => $user->id));
    }

    public function testCustomStringKeyPartialProjectionAndModelChunks(): void
    {
        $this->manager->table('cursor_codes')->insertMany([
            ['code' => 'alpha', 'rank_value' => 1],
            ['code' => 'beta', 'rank_value' => 1],
            ['code' => 'gamma', 'rank_value' => 2],
        ]);
        $query = $this->manager->table('cursor_codes')->select(['code', 'rank_value'])->sort('rank_value');
        $first = $query->cursorPage(2, key: 'code');
        self::assertSame(['alpha', 'beta'], array_column($first->items(), 'code'));
        self::assertSame(['gamma'], array_column(
            $query->cursorPage(2, $first->nextCursor(), key: 'code')->items(), 'code'
        ));
        $modelFirst = CursorCode::query()->sort('rank_value')->cursorPage(2);
        $modelItems = $modelFirst->items();
        self::assertInstanceOf(ModelCollection::class, $modelItems);
        self::assertSame(['alpha', 'beta'], $modelItems->pluck('code'));
        $modelNext = CursorCode::query()->sort('rank_value')->cursorPage(2, $modelFirst->nextCursor())->items();
        self::assertInstanceOf(ModelCollection::class, $modelNext);
        self::assertSame(['gamma'], $modelNext->pluck('code'));

        $ids = [];
        $batches = CursorUser::query()->chunk(2, static function (ModelCollection $users) use (&$ids): void {
            array_push($ids, ...$users->map(static fn (CursorUser $user): int => $user->id));
        });
        self::assertSame(3, $batches);
        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testNullSortBoundaryFailsInsteadOfSkippingRows(): void
    {
        $this->manager->table('cursor_users')->insert(['id' => 0, 'name' => 'null', 'rank_value' => null]);
        $this->expectException(LogicException::class);
        $this->manager->table('cursor_users')->sort('rank_value')->cursorPage(1);
    }

    public function testCursorPredicateStaysOutsideCallerOrChain(): void
    {
        $query = $this->manager->table('cursor_users')->filter('id', 1)->orFilter('id', 4)->sort('id');
        $first = $query->cursorPage(1);
        self::assertSame([1], array_map('intval', array_column($first->items(), 'id')));
        $next = $query->cursorPage(1, $first->nextCursor());
        self::assertSame([4], array_map('intval', array_column($next->items(), 'id')));
        self::assertFalse($next->hasMore());
    }

    public function testDeferredQueryPinsItsTemporaryOriginForEagerRelations(): void
    {
        $temporary = new DatabaseManager(new Repository(['database' => [
            'default' => 'temporary',
            'connections' => ['temporary' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        $pdo = $temporary->connection()->pdo();
        $pdo->exec('CREATE TABLE cursor_users (id INTEGER PRIMARY KEY, name TEXT, rank_value INTEGER, status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE cursor_posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
        $temporary->table('cursor_users')->insert(['id' => 100, 'name' => 'temporary', 'rank_value' => 1]);
        $temporary->table('cursor_posts')->insert(['user_id' => 100, 'title' => 'origin-only']);
        $query = CursorUser::queryOn($temporary)->with('posts');
        unset($temporary, $pdo);
        gc_collect_cycles();

        $user = $query->cursorPage(2)->items()->first();
        self::assertInstanceOf(CursorUser::class, $user);
        self::assertSame(100, $user->id);
        self::assertSame('origin-only', $user->posts->first()->getAttribute('title'));
        self::assertSame(5, $this->manager->table('cursor_users')->count());
    }

    private function expectFailure(string $class, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected ' . $class . '.');
        } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            self::assertInstanceOf($class, $failure);
        }
    }
}

/**
 * A soft-deletable Model used to prove cursor hydration shares the normal path.
 *
 * @property int $id
 * @property ModelCollection $posts
 */
final class CursorUser extends Model
{
    protected string $table = 'cursor_users';
    protected bool $softDeletes = true;

    public function posts(): HasMany
    {
        return $this->hasMany(CursorPost::class, 'user_id');
    }
}

final class CursorPost extends Model
{
    protected string $table = 'cursor_posts';
}

final class CursorCode extends Model
{
    protected string $table = 'cursor_codes';
    protected string $primaryKey = 'code';
}

/** Counts executed statements, including any unwanted count query. */
final class CursorStatement extends PDOStatement
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
