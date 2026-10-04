<?php

declare(strict_types=1);

namespace App\Database\Schema;

/** Quotes the limited identifiers and literal defaults accepted by the schema API. */
final class Sql
{
    public static function name(string $name): string
    {
        return '`' . Table::name($name) . '`';
    }

    /** @param list<string> $names */
    public static function names(array $names): string
    {
        return implode(', ', array_map(self::name(...), $names));
    }

    public static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            if (preg_match('/[\x00-\x1F\\\\]/', $value) === 1) {
                throw new SchemaException('A schema default literal contains unsupported characters.');
            }
            return "'" . str_replace("'", "''", $value) . "'";
        }
        throw new SchemaException('A schema default literal is unsupported.');
    }
}
