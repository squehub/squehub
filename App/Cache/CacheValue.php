<?php

declare(strict_types=1);

namespace App\Cache;

use ReflectionReference;

/** Keeps both drivers limited to portable scalar and array data. */
final class CacheValue
{
    public static function validate(mixed $value): void
    {
        $references = [];
        $visited = 0;
        self::walk($value, $references, $visited, 0);
    }

    /** A serialization round trip breaks caller-owned references in array entries. */
    public static function copy(mixed $value): mixed
    {
        self::validate($value);
        return unserialize(serialize($value), ['allowed_classes' => false]);
    }

    /** @param array<string, true> $references */
    private static function walk(mixed &$value, array &$references, int &$visited, int $depth): void
    {
        if (++$visited > 100000 || $depth > 128) {
            throw new CacheException('Cache value exceeds supported nesting or size.');
        }
        if (is_array($value)) {
            foreach ($value as $key => &$child) {
                $reference = ReflectionReference::fromArrayElement($value, $key);
                $id = $reference?->getId();
                if ($id !== null && isset($references[$id])) {
                    throw new CacheException('Recursive cache values are unsupported.');
                }
                if ($id !== null) $references[$id] = true;
                self::walk($child, $references, $visited, $depth + 1);
                if ($id !== null) unset($references[$id]);
            }
            unset($child);
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return;
        }
        throw new CacheException('Cache value type is unsupported.');
    }
}
