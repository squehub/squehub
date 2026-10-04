<?php

declare(strict_types=1);

namespace App\Core;

use App\Database\Database as DatabaseBridge;
use PDO;
use PDOStatement;

/** Legacy static API backed by the Application's database connection. */
class Database
{
    public static function connect(): PDO
    {
        try {
            return DatabaseBridge::manager()->connection()->pdo();
        } catch (\LogicException $exception) {
            // Direct legacy calls may occur before the Application has booted.
            require __DIR__ . '/../../Bootstrap/App.php';
            return DatabaseBridge::manager()->connection()->pdo();
        }
    }

    public static function getInstance(): PDO
    {
        return self::connect();
    }

    /** @param array<int|string, mixed>|mixed $params */
    public static function query(string $sql, mixed $params = []): PDOStatement
    {
        self::connect();
        if (!is_array($params)) {
            $params = [$params];
        }
        // Preserve the v1 array-to-comma-string behavior for direct legacy calls.
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = implode(',', $value);
            }
        }
        return DatabaseBridge::manager()->raw($sql, $params);
    }
}
