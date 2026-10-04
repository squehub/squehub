<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Storage\Storage as StorageGateway;
use App\Storage\StorageDrive;
use App\Storage\StorageManager;
use DateTimeImmutable;

/** Application storage facade; validation, drivers, and diagnostics stay in Storage. */
final class Storage
{
    public static function manager(): StorageManager { return StorageGateway::manager(); }
    public static function drive(string $name): StorageDrive { return self::manager()->drive($name); }
    public static function read(string $path): string { return self::manager()->read($path); }
    public static function write(string $path, string $contents): void { self::manager()->write($path, $contents); }
    public static function exists(string $path): bool { return self::manager()->exists($path); }
    public static function remove(string $path): bool { return self::manager()->remove($path); }
    public static function copy(string $source, string $destination, bool $overwrite = false): void
    {
        self::manager()->copy($source, $destination, $overwrite);
    }
    public static function move(string $source, string $destination, bool $overwrite = false): void
    {
        self::manager()->move($source, $destination, $overwrite);
    }
    public static function files(string $directory = '', bool $recursive = false): array
    {
        return self::manager()->files($directory, $recursive);
    }
    public static function size(string $path): int { return self::manager()->size($path); }
    public static function modifiedAt(string $path): DateTimeImmutable { return self::manager()->modifiedAt($path); }
}
