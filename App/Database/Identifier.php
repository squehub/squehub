<?php

declare(strict_types=1);

namespace App\Database;

use App\Database\Exception\InvalidIdentifierException;

/**
 * Validates names that PDO cannot represent with value placeholders.
 */
final class Identifier
{
    private const SEGMENT = '[A-Za-z_][A-Za-z0-9_]*';

    public static function simple(string $name): string
    {
        if (preg_match('/^' . self::SEGMENT . '$/D', $name) !== 1) {
            throw new InvalidIdentifierException('Invalid database identifier.');
        }

        return $name;
    }

    public static function table(string $name, bool $allowAlias = false): string
    {
        return self::quoted(self::validate($name, false, $allowAlias));
    }

    public static function column(string $name, bool $allowWildcard = false, bool $allowAlias = false): string
    {
        return self::quoted(self::validate($name, $allowWildcard, $allowAlias));
    }

    private static function validate(string $name, bool $allowWildcard, bool $allowAlias): string
    {
        $qualified = self::SEGMENT . '(?:\.' . self::SEGMENT . ')*';
        $expression = $qualified;
        if ($allowWildcard) {
            $expression = '(?:\*|' . $qualified . '(?:\.\*)?)';
        }
        if ($allowAlias) {
            $expression .= '(?:[ ]+(?:AS[ ]+)?' . self::SEGMENT . ')?';
        }

        if (preg_match('/^' . $expression . '$/iD', $name) !== 1) {
            throw new InvalidIdentifierException('Invalid database identifier.');
        }

        return $name;
    }

    private static function quoted(string $name): string
    {
        $parts = preg_split('/\s+(?:AS\s+)?/i', $name, 2);
        $segments = explode('.', $parts[0]);
        $quoted = implode('.', array_map(
            static fn (string $segment): string => $segment === '*' ? '*' : '`' . $segment . '`',
            $segments
        ));
        return isset($parts[1]) ? $quoted . ' AS `' . $parts[1] . '`' : $quoted;
    }
}
