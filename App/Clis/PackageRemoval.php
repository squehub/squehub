<?php

declare(strict_types=1);

namespace App\Clis;

use App\Support\PhysicalPath;
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
        if ($base === false || !PhysicalPath::unlinked($parent)
            || !PhysicalPath::same($parent, $base . '/Project/Packages')) {
            throw new RuntimeException('Package directory is not a safe project path.');
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
        if (!PhysicalPath::unlinked($path)) {
            throw new RuntimeException('Package contains a linked or reparsed path.');
        }
    }
}
