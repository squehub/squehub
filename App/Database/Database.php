<?php

declare(strict_types=1);

namespace App\Database;

use LogicException;

/** Developer-facing helper bridge to the Application-owned manager. */
final class Database
{
    /** @var (callable(): DatabaseManager)|null */
    private static $resolver = null;

    /** @param (callable(): DatabaseManager)|null $resolver */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function manager(): DatabaseManager
    {
        if (self::$resolver === null) {
            throw new LogicException('Database is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
