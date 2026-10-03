<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Database\Connection;
use App\Database\Database as DatabaseGateway;
use App\Database\DatabaseManager;
use App\Database\QueryBuilder;
use App\Database\Schema\Schema;
use PDOStatement;

/** Application database gateway; all calls use the current DatabaseManager. */
final class DB
{
    public static function manager(): DatabaseManager { return DatabaseGateway::manager(); }
    public static function connection(?string $name = null): Connection { return self::manager()->connection($name); }
    public static function table(string $table, ?string $connection = null): QueryBuilder
    {
        return self::manager()->table($table, $connection);
    }
    public static function schema(?string $connection = null): Schema
    {
        // PHP does not autoload aliases mentioned only in callback parameter types.
        // Load the public Table alias before Schema invokes an application callback.
        class_exists(Table::class);

        return self::manager()->schema($connection);
    }
    public static function transaction(callable $callback, ?string $connection = null): mixed
    {
        return self::manager()->transaction($callback, $connection);
    }
    /** @param array<int|string, mixed> $bindings */
    public static function raw(string $sql, array $bindings = [], ?string $connection = null): PDOStatement
    {
        return self::manager()->raw($sql, $bindings, $connection);
    }
}
