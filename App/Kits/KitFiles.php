<?php

declare(strict_types=1);

namespace App\Kits;

use App\Packages\PackageException;
use App\Packages\PackageFiles;

/** Contained file publication for Kit definitions and normal application files. */
final class KitFiles
{
    /** @return array<string,string> Kit-relative path to SHA-256. */
    public static function fingerprints(string $root, bool $excludeGit = false): array
    {
        try {
            return PackageFiles::fingerprints($root, $excludeGit);
        } catch (PackageException $exception) {
            throw new KitException('Kit source cannot be fingerprinted safely.', 0, $exception);
        }
    }

    /** Reject linked ancestors, casing ambiguities, and targets outside the allowlist. */
    public static function target(string $basePath, string $kit, string $relative): string
    {
        KitManifest::requireRelative($relative);
        if (KitManifest::targetKind($kit, $relative) === null) {
            throw new KitException('Kit publication target is unsupported.');
        }
        $base = realpath($basePath);
        if ($base === false || !is_dir($base)) { throw new KitException('Application root is unavailable.'); }
        $current = $base;
        foreach (explode('/', $relative) as $part) {
            if (is_dir($current)) {
                $entries = @scandir($current);
                if ($entries === false) { throw new KitException('Kit target cannot be inspected.'); }
                foreach ($entries as $entry) {
                    if (strcasecmp($entry, $part) === 0 && $entry !== $part) {
                        throw new KitException('Kit target conflicts by casing.');
                    }
                }
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) { throw new KitException('Kit target contains a linked path.'); }
            if (file_exists($current)) {
                try {
                    PackageFiles::assertPhysical($current);
                } catch (PackageException $exception) {
                    throw new KitException('Kit target contains a linked or reparsed path.', 0, $exception);
                }
            }
        }
        return $current;
    }

    public static function fingerprint(string $basePath, string $kit, string $relative): ?string
    {
        $path = self::target($basePath, $kit, $relative);
        if (!file_exists($path)) { return null; }
        if (!is_file($path)) { throw new KitException('Kit target is not a regular file.'); }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) { throw new KitException('Kit target cannot be fingerprinted.'); }
        return $hash;
    }

    /** Source bytes stay private to the operation; the ChangePlan has hashes only. */
    public static function sourceBytes(string $root, string $relative, string $expected): string
    {
        KitManifest::requireRelative($relative);
        $path = $root . '/' . $relative;
        try {
            PackageFiles::assertPhysical($path);
        } catch (PackageException $exception) {
            throw new KitException('Kit source path is unsafe.', 0, $exception);
        }
        if (!is_file($path)) { throw new KitException('Kit source file is unavailable.'); }
        $size = @filesize($path);
        if ($size === false || $size > 4194304) {
            throw new KitException('Kit source file exceeds the publication limit.');
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || hash('sha256', $bytes) !== $expected) {
            throw new KitException('Kit source changed after review.');
        }
        return $bytes;
    }

    /** Create only missing physical parents within the checked application root. */
    public static function createParents(string $basePath, string $kit, string $relative): void
    {
        $parts = explode('/', $relative);
        array_pop($parts);
        $prefix = '';
        foreach ($parts as $part) {
            $prefix = ltrim($prefix . '/' . $part, '/');
            $path = $basePath . '/' . $prefix;
            if (is_link($path)) { throw new KitException('Kit target parent is linked.'); }
            if (!is_dir($path) && !@mkdir($path, 0775) && !is_dir($path)) {
                throw new KitException('Kit target directory could not be created.');
            }
            // Re-check from the root after each mkdir; junctions and case-only
            // collisions must never turn a logical target into an outside path.
            self::target($basePath, $kit, $relative);
        }
    }

    /** Same-directory staging keeps readers away from partially written PHP. */
    public static function publish(string $basePath, string $kit, string $relative,
        string $bytes, ?string $expectedBefore): void
    {
        self::createParents($basePath, $kit, $relative);
        $path = self::target($basePath, $kit, $relative);
        if (self::fingerprint($basePath, $kit, $relative) !== $expectedBefore) {
            throw new KitException('Kit target changed after review.');
        }
        $temporary = @tempnam(dirname($path), '.SqueHub-Kit-');
        if ($temporary === false) { throw new KitException('Kit target could not be staged.'); }
        try {
            if (@file_put_contents($temporary, $bytes) !== strlen($bytes)
                || !@chmod($temporary, 0666 & ~umask())) {
                throw new KitException('Kit target could not be staged.');
            }
            if ($expectedBefore === null) {
                // An atomic create-if-absent link prevents replacing a file
                // another writer created after our last inspection.
                if (!@link($temporary, $path)) {
                    throw new KitException('Kit target appeared during publication.');
                }
            } elseif (!@rename($temporary, $path)) {
                throw new KitException('Kit target could not be replaced.');
            }
            if (self::fingerprint($basePath, $kit, $relative) !== hash('sha256', $bytes)) {
                throw new KitException('Kit target could not be verified after publication.');
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    public static function delete(string $basePath, string $kit, string $relative, string $expected): void
    {
        $path = self::target($basePath, $kit, $relative);
        if (self::fingerprint($basePath, $kit, $relative) !== $expected) {
            throw new KitException('Kit-owned file changed after review.');
        }
        if (!@unlink($path)) { throw new KitException('Kit-owned file could not be removed.'); }
    }
}
