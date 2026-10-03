<?php

declare(strict_types=1);

namespace App\Clis;

use RuntimeException;

/**
 * Removes only one package directory after a complete containment preflight.
 * Links and junctions are rejected before any entry is deleted.
 */
final class PackageRemoval
{
    public static function remove(string $target, string $basePath): void
    {
        $base = realpath($basePath);
        $parent = dirname($target);
        $resolvedParent = realpath($parent);
        if ($base === false || $resolvedParent === false || is_link($parent)
            || !self::same($resolvedParent, $parent)) {
            throw new RuntimeException('Package directory is not a safe project path.');
        }
        $canonicalBase = self::normalize($base);
        $canonicalParent = self::normalize($parent);
        $prefix = $canonicalBase . '/';
        $inside = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($canonicalParent), strtolower($prefix))
            : str_starts_with($canonicalParent, $prefix);
        $relative = $inside ? substr($canonicalParent, strlen($prefix)) : '';
        if (strtolower($relative) !== 'project/packages') {
            throw new RuntimeException('Package directory is outside Project/Packages.');
        }
        self::assertPhysical($target);
        if (!is_dir($target)) throw new RuntimeException('Package directory is unavailable.');

        $entries = [];
        self::collect($target, $entries);
        foreach ($entries as $entry) {
            if (is_dir($entry) ? !@rmdir($entry) : !@unlink($entry)) {
                throw new RuntimeException('Package could not be completely removed.');
            }
        }
        if (!@rmdir($target)) throw new RuntimeException('Package directory could not be removed.');
    }

    /** @param list<string> $entries */
    private static function collect(string $directory, array &$entries): void
    {
        $names = @scandir($directory);
        if ($names === false) throw new RuntimeException('Package directory cannot be read.');
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            self::assertPhysical($path);
            if (is_dir($path)) self::collect($path, $entries);
            $entries[] = $path;
        }
    }

    private static function assertPhysical(string $path): void
    {
        if (is_link($path) || !file_exists($path)) throw new RuntimeException('Package contains an unavailable or linked path.');
        $real = realpath($path);
        if ($real === false || !self::same($real, $path)) {
            throw new RuntimeException('Package contains a linked or reparsed path.');
        }
    }

    private static function same(string $a, string $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
