<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\DatabaseAdvanced;

use App\Database\Connection;
use App\Database\ConnectionFactory;
use App\Database\Exception\QueryException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/** Exercises bound query composition and backend-native writes on isolated SQLite. */
final class QueryBuilderAdvancedTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for advanced query tests.');
        }
        $this->connection = new Connection('testing', ['driver' => 'sqlite', 'database' => ':memory:'],
            new ConnectionFactory());
        $this->connection->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, name TEXT, active INTEGER)');
        $this->connection->pdo()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
        $this->connection->table('users')->insertMany([
            ['id' => 1, 'email' => 'a@example.test', 'name' => 'Ada', 'active' => 1],
            ['id' => 2, 'email' => 'b@example.test', 'name' => 'Bea', 'active' => 1],
            ['id' => 3, 'email' => 'c@example.test', 'name' => 'Cy', 'active' => 0],
        ]);
    }

    public function testGroupedFiltersAndCorrelatedSubqueriesStayBound(): void
    {
        $query = $this->connection->table('users')->filter('active', 1)
            ->filterGroup(static function ($group): void {
                $group->filter('email', 'a@example.test')->orFilter('name', 'Bea');
            });
        self::assertSame('SELECT * FROM `users` WHERE `active` = ? AND (`email` = ? OR `name` = ?)',
            $query->toSql());
        self::assertSame([1, 'a@example.test', 'Bea'], $query->bindings());
        self::assertSame([1, 2], array_map('intval', array_column($query->sort('id')->all(), 'id')));
        self::assertSame([1, 3], array_map('intval', array_column(
            $this->connection->table('users')->filter('id', 1)
                ->orFilterGroup(static function ($group): void {
                    $group->filter('active', 0)->filter('name', 'Cy');
                })->sort('id')->all(), 'id'
        )));

        $this->connection->table('posts')->insert(['user_id' => 2, 'title' => 'hello']);
        $subquery = $this->connection->table('posts')->select(['id'])
            ->filterColumn('posts.user_id', '=', 'users.id')->filter('title', 'hello');
        $hasPost = $this->connection->table('users')->filterExists($subquery);
        self::assertSame(['hello'], $hasPost->bindings());
        self::assertSame([2], array_map('intval', array_column($hasPost->all(), 'id')));
        self::assertSame([1, 3], array_map('intval', array_column(
            $this->connection->table('users')->filterNotExists($subquery)->sort('id')->all(), 'id'
        )));
        self::assertSame([2], array_map('intval', array_column(
            $this->connection->table('users')->filterInQuery('id',
                $this->connection->table('posts')->select(['user_id']))->all(), 'id'
        )));
        self::assertSame([1, 3], array_map('intval', array_column(
            $this->connection->table('users')->filterNotInQuery('id',
                $this->connection->table('posts')->select(['user_id']))->sort('id')->all(), 'id'
        )));
        self::assertSame([1, 2], array_map('intval', array_column(
            $this->connection->table('users')->filterSubquery('id', '<',
                $this->connection->table('users')->select(['id'])->sort('id', 'desc')->limit(1))
                ->sort('id')->all(), 'id'
        )));
        // A tautological identifier comparison never bypasses the write guard.
        $this->expectException(LogicException::class);
        $this->connection->table('users')->filterColumn('id', '=', 'id')->delete();
    }

    public function testUpsertAndBatchedInsertionAreAtomic(): void
    {
        self::assertSame(1, $this->connection->table('users')->upsert(
            ['id' => 4, 'email' => 'd@example.test', 'name' => 'Dee', 'active' => 1], ['email'], ['name']
        ));
        self::assertSame(1, $this->connection->table('users')->upsert(
            ['id' => 99, 'email' => 'd@example.test', 'name' => 'Updated', 'active' => 0], ['email'], ['name']
        ));
        self::assertSame('Updated', $this->connection->table('users')->filter('id', 4)->first()['name']);
        self::assertSame(1, $this->connection->table('users')->filter('id', 4)->first()['active']);

        $rows = [];
        for ($id = 10; $id < 930; $id++) {
            $rows[] = ['id' => $id];
        }
        self::assertSame(920, $this->connection->table('posts')->insertMany($rows));
        self::assertSame(920, $this->connection->table('posts')->count());
        $seen = 0;
        $largestBatch = 0;
        $batches = $this->connection->table('posts')->chunk(100,
            static function (array $batch) use (&$seen, &$largestBatch): void {
                $seen += count($batch);
                $largestBatch = max($largestBatch, count($batch));
            });
        self::assertSame(10, $batches);
        self::assertSame(920, $seen);
        self::assertSame(100, $largestBatch);

        $duplicate = [];
        for ($id = 1000; $id < 1900; $id++) {
            $duplicate[] = ['id' => $id];
        }
        $duplicate[] = ['id' => 10];
        try {
            $this->connection->table('posts')->insertMany($duplicate);
            self::fail('The second batch must fail on a duplicate key.');
        } catch (QueryException) {
            self::assertSame(920, $this->connection->table('posts')->count());
        }
    }

    public function testChunkUsesBoundedKeysetsAndSupportsEarlyStop(): void
    {
        $ids = [];
        $batches = $this->connection->table('users')->filter('active', 1)
            ->chunk(1, static function (array $rows, int $batch) use (&$ids): void {
                $ids[] = (int) $rows[0]['id'];
            });
        self::assertSame(2, $batches);
        self::assertSame([1, 2], $ids);
        self::assertSame(1, $this->connection->table('users')->chunk(1,
            static fn (array $rows, int $batch): bool => false));
    }

    public function testRowLocksRejectSqliteAndMissingMySqlTransaction(): void
    {
        try {
            $this->connection->table('users')->lockForUpdate()->toSql();
            self::fail('SQLite row locks must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('MySQL', $exception->getMessage());
        }
        try {
            $this->connection->table('users')->lockForUpdate()->insert(['id' => 4]);
            self::fail('A select lock cannot silently apply to an insert.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('INSERT', $exception->getMessage());
        }
        $mysql = new Connection('mysql-test', ['driver' => 'mysql'], new ConnectionFactory());
        try {
            $mysql->table('users')->lockShared()->toSql();
            self::fail('A lock without a managed transaction must be rejected.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('transaction', $exception->getMessage());
        }
        self::assertFalse($mysql->isConnected());
    }
}
