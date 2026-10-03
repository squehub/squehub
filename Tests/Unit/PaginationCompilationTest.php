<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Pagination;

use App\Database\Connection;
use App\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;

/** Compiles both pagination statements without opening a MySQL connection. */
final class PaginationCompilationTest extends TestCase
{
    public function testFilteredCountAndWindowSqlArePortable(): void
    {
        foreach (['sqlite', 'mysql'] as $driver) {
            $connection = new Connection('test', ['driver' => $driver], new ConnectionFactory());
            $query = $connection->table('users')->filter('status', 'active')->sort('id', 'desc');
            self::assertFalse($connection->isConnected());
            self::assertSame('SELECT COUNT(*) FROM `users` WHERE `status` = ?', $query->countSql());
            self::assertSame('SELECT * FROM `users` WHERE `status` = ? ORDER BY `id` DESC LIMIT 20 OFFSET 20',
                (clone $query)->limit(20)->skip(20)->toSql());
            self::assertSame(['active'], $query->bindings());
            self::assertFalse($connection->isConnected());
        }
    }
}
