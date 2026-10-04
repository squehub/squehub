<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\Connection;
use App\Database\ConnectionFactory;
use App\Database\Exception\InvalidIdentifierException;
use App\Database\Exception\QueryException;
use App\Database\QueryBuilder;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for QueryBuilder integration tests.');
        }

        $this->connection = new Connection('testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ], new ConnectionFactory());
        $this->connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, status TEXT, age INTEGER, role TEXT, deleted_at TEXT)');
        $this->connection->pdo()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT)');
    }

    public function testCompilationUsesSqueHubVocabularyAndKeepsValuesOutOfSql(): void
    {
        $query = $this->query('users')
            ->select(['id', 'name AS label'])
            ->filter('status', 'active')
            ->filter('age', '>=', 18)
            ->orFilter('role', 'admin')
            ->sort('created_at', 'desc')
            ->limit(20)
            ->skip(5);

        self::assertSame(
            'SELECT `id`, `name` AS `label` FROM `users` WHERE `status` = ? AND `age` >= ? OR `role` = ? ORDER BY `created_at` DESC LIMIT 20 OFFSET 5',
            $query->toSql()
        );
        self::assertSame(['active', 18, 'admin'], $query->bindings());
        self::assertStringNotContainsString('active', $query->toSql());
        self::assertSame('SELECT * FROM `users` LIMIT 9223372036854775807 OFFSET 2',
            $this->query('users')->skip(2)->toSql());
    }

    public function testSetRangeAndNullFiltersCompileWithCorrectBindings(): void
    {
        $query = $this->query('users')->filterIn('id', [1, 2, 3])
            ->filterNotIn('role', ['guest', 'robot'])
            ->filterBetween('age', 18, 64)
            ->filterNull('deleted_at')
            ->filterNotNull('name');

        self::assertSame(
            'SELECT * FROM `users` WHERE `id` IN (?, ?, ?) AND `role` NOT IN (?, ?) AND `age` BETWEEN ? AND ? AND `deleted_at` IS NULL AND `name` IS NOT NULL',
            $query->toSql()
        );
        self::assertSame([1, 2, 3, 'guest', 'robot', 18, 64], $query->bindings());
        self::assertSame('SELECT * FROM `users` WHERE 0 = 1', $this->query('users')->filterIn('id', [])->toSql());
        self::assertSame('SELECT * FROM `users` WHERE 1 = 1', $this->query('users')->filterNotIn('id', [])->toSql());
    }

    public function testJoinsGroupingHavingDistinctAndSortingCompileSafely(): void
    {
        $query = $this->query('posts')
            ->select(['users.status'])
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->group('users.status')
            ->having('users.status', 'active')
            ->sort('users.status');

        self::assertSame(
            'SELECT `users`.`status` FROM `posts` INNER JOIN `users` ON `users`.`id` = `posts`.`user_id` GROUP BY `users`.`status` HAVING `users`.`status` = ? ORDER BY `users`.`status` ASC',
            $query->toSql()
        );
        self::assertSame(['active'], $query->bindings());
        self::assertSame(
            'SELECT DISTINCT `u`.`name` FROM `posts` LEFT JOIN `users` AS `u` ON `u`.`id` = `posts`.`user_id`',
            $this->query('posts')->select(['u.name'])->distinct()
                ->leftJoin('users AS u', 'u.id', '=', 'posts.user_id')->toSql()
        );
    }

    public function testReadsExistenceAggregatesAndDistinctCountsUseSqlite(): void
    {
        $this->seedUsers();

        self::assertSame(['name' => 'Ada'], $this->query('users')->select(['name'])
            ->filter('status', 'active')->sort('id')->first());
        self::assertSame([['name' => 'Lin']], $this->query('users')->select(['name'])
            ->sort('id')->skip(1)->limit(1)->all());
        self::assertTrue($this->query('users')->filter('status', 'active')->exists());
        self::assertFalse($this->query('users')->filter('status', 'missing')->exists());
        self::assertNull($this->query('users')->filter('status', 'missing')->first());
        self::assertSame(2, $this->query('users')->filter('status', 'active')->count());
        self::assertSame(2, $this->query('users')->select(['status'])->distinct()->count());
        self::assertSame(2, $this->query('users')->select(['status'])->group('status')->count());
        self::assertEquals(90, $this->query('users')->sum('age'));
        self::assertEquals(30, $this->query('users')->avg('age'));
        self::assertEquals(20, $this->query('users')->min('age'));
        self::assertEquals(40, $this->query('users')->max('age'));
        self::assertNull($this->query('users')->filter('status', 'missing')->sum('age'));
    }

    public function testBoundInsertsUpdatesDeletesAndJoinReads(): void
    {
        $name = "Valentine'); DROP TABLE users; --";
        $id = $this->query('users')->insertId(['name' => $name, 'status' => 'active', 'age' => 25]);
        self::assertSame('1', $id);
        self::assertSame(1, $this->query('users')->insert(['name' => 'Lin', 'status' => 'active', 'age' => 30]));
        self::assertSame($name, $this->query('users')->filter('id', $id)->first()['name']);
        self::assertSame(1, $this->query('users')->filter('id', $id)->update(['status' => 'inactive']));
        self::assertSame('inactive', $this->query('users')->filter('id', $id)->first()['status']);

        $this->connection->pdo()->exec("INSERT INTO posts (user_id, title) VALUES (1, 'post')");
        self::assertSame([['title' => 'post', 'name' => $name]], $this->query('posts')
            ->select(['posts.title', 'users.name'])
            ->join('users', 'users.id', '=', 'posts.user_id')->all());
        $this->connection->pdo()->exec("INSERT INTO posts (user_id, title) VALUES (999, 'orphan')");
        self::assertSame([
            ['title' => 'post', 'name' => $name],
            ['title' => 'orphan', 'name' => null],
        ], $this->query('posts')->select(['posts.title', 'users.name'])
            ->leftJoin('users', 'users.id', '=', 'posts.user_id')->sort('posts.id')->all());
        self::assertSame(1, $this->query('users')->filter('id', $id)->delete());
        self::assertSame(1, $this->query('users')->count());
    }

    public function testUnfilteredWritesNeedExplicitIntent(): void
    {
        $this->seedUsers();
        foreach (['update', 'delete'] as $method) {
            try {
                if ($method === 'update') {
                    $this->query('users')->update(['status' => 'all']);
                } else {
                    $this->query('users')->delete();
                }
                self::fail('Unfiltered write should fail.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('allowAll', $exception->getMessage());
            }
        }

        $this->expectFailure(LogicException::class,
            fn () => $this->query('users')->filterNotIn('id', [])->delete());

        self::assertSame(3, $this->query('users')->allowAll()->update(['status' => 'all']));
        self::assertSame(3, $this->query('users')->allowAll()->delete());
    }

    public function testUnsafeIdentifiersOperatorsAndShapesAreRejected(): void
    {
        foreach (['users; DROP TABLE users', 'users name; --', 'users.name DESC'] as $identifier) {
            try {
                $this->query($identifier);
                self::fail('Unsafe table name accepted.');
            } catch (InvalidIdentifierException) {
                self::assertTrue(true);
            }
            try {
                $this->query('users')->sort($identifier);
                self::fail('Unsafe sort column accepted.');
            } catch (InvalidIdentifierException) {
                self::assertTrue(true);
            }
        }

        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->query('users')->filter('age', '= 1 OR 1=1', 18));
        $this->expectFailure(InvalidIdentifierException::class,
            fn () => $this->query('users')->insert(['name); DROP TABLE users' => 'unsafe']));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->query('users')->sort('age', 'DESC; DELETE'));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->query('users')->filter('name', null));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->query('users')->filterIn('id', [[1, 2]]));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->query('users')->select([]));
        $this->expectFailure(InvalidArgumentException::class,
            fn () => $this->query('users')->limit(-1));
        $this->expectFailure(LogicException::class,
            fn () => $this->query('users')->having('status', 'active'));
        $this->expectFailure(LogicException::class,
            fn () => $this->query('users')->join('posts', 'posts.user_id', '=', 'users.id')
                ->filter('users.id', 1)->delete());
    }

    public function testQueryErrorsDoNotExposeBindingValues(): void
    {
        $secret = 'private-token-123';
        try {
            $this->query('missing_table')->filter('name', $secret)->all();
            self::fail('Missing table query should fail.');
        } catch (QueryException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertSame('Database query failed.', $exception->getMessage());
        }
    }

    public function testReservedIdentifiersAreQuotedOnSqlite(): void
    {
        $this->connection->pdo()->exec('CREATE TABLE `order` (`group` TEXT)');
        self::assertSame(1, $this->query('order')->insert(['group' => 'alpha']));
        self::assertSame(['group' => 'alpha'], $this->query('order')->select(['group'])->first());
        self::assertSame('SELECT `group` FROM `order`', $this->query('order')->select(['group'])->toSql());
    }

    private function query(string $table): QueryBuilder
    {
        return new QueryBuilder($this->connection, $table);
    }

    private function seedUsers(): void
    {
        foreach ([
            ['Ada', 'active', 20], ['Lin', 'inactive', 30], ['Val', 'active', 40],
        ] as [$name, $status, $age]) {
            $this->query('users')->insert(['name' => $name, 'status' => $status, 'age' => $age]);
        }
    }

    private function expectFailure(string $class, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected ' . $class . '.');
        } catch (\PHPUnit\Framework\AssertionFailedError $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::assertInstanceOf($class, $exception);
        }
    }
}
