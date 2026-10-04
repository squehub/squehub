<?php

declare(strict_types=1);

namespace App\Clis;

/** Finds CLI source files when only the first letter's case differs. */
final class FileLookup
{
    /** Keep Seeder lookup inside the canonical application root, including linked ancestors. */
    public static function seederDirectory(string $basePath): ?string
    {
        $root = realpath($basePath);
        if ($root === false || !is_dir($root)) {
            return null;
        }
        $databasePath = $root . '/Database';
        $seederPath = $databasePath . '/Seeders';
        if (is_link($databasePath) || is_link($seederPath)) {
            return null;
        }
        $database = realpath($databasePath);
        $directory = realpath($seederPath);
        if ($database === false || $directory === false || !is_dir($directory)
            || dirname($database) !== $root || dirname($directory) !== $database) {
            return null;
        }
        return $directory;
    }

    /** Exact file casing avoids a Seeder that runs on Windows but fails on Linux. */
    public static function seeder(string $basePath, string $className): ?string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $className) !== 1) {
            return null;
        }
        $directory = self::seederDirectory($basePath);
        if ($directory === null) {
            return null;
        }
        $file = realpath($directory . '/' . $className . '.php');
        return $file !== false && is_file($file) && dirname($file) === $directory
            && basename($file) === $className . '.php' ? $file : null;
    }

    public static function migration(string $basePath, string $fileName): ?string
    {
        if (!self::validFileName($fileName)) {
            return null;
        }

        foreach (self::directories($basePath, 'Migrations') as $directory) {
            foreach (scandir($directory) ?: [] as $candidate) {
                if (!self::sameFirstLetter($candidate, $fileName)) {
                    continue;
                }
                $path = realpath($directory . DIRECTORY_SEPARATOR . $candidate);
                if ($path !== false && is_file($path) && dirname($path) === $directory) {
                    return $path;
                }
            }
        }
        return null;
    }

    public static function migrationDirectory(string $basePath): ?string
    {
        foreach (self::directories($basePath, 'Migrations') as $directory) {
            return $directory;
        }

        return null;
    }

    public static function sameFirstLetter(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (strlen($left) !== strlen($right)
            || preg_match('/[A-Za-z]/', $left, $leftMatch, PREG_OFFSET_CAPTURE) !== 1
            || preg_match('/[A-Za-z]/', $right, $rightMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }
        $position = $leftMatch[0][1];
        return $position === $rightMatch[0][1]
            && substr($left, 0, $position) === substr($right, 0, $position)
            && strtolower($left[$position]) === strtolower($right[$position])
            && substr($left, $position + 1) === substr($right, $position + 1);
    }

    private static function validFileName(string $fileName): bool
    {
        return strlen($fileName) <= 255
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\.php\z/D', $fileName) === 1
            && !str_contains($fileName, '..');
    }

    /** @return list<string> */
    private static function directories(string $basePath, string $subdirectory): array
    {
        $directories = [];
        foreach (['Database', 'database'] as $database) {
            foreach ([$subdirectory, lcfirst($subdirectory)] as $child) {
                $path = realpath($basePath . DIRECTORY_SEPARATOR . $database . DIRECTORY_SEPARATOR . $child);
                if ($path !== false && is_dir($path) && !in_array($path, $directories, true)) {
                    $directories[] = $path;
                }
            }
        }

        return $directories;
    }
}
