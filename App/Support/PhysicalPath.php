<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Physical entry identity and link-free path inspection across host filesystems.
 *
 * Windows may return a DOS 8.3 spelling for an ordinary entry after realpath().
 * File IDs recognize that spelling without treating a link to the same target
 * as a link-free path.
 */
final class PhysicalPath
{
    /** A key for an existing physical entry, including equivalent path aliases. */
    public static function identity(string $path): ?string
    {
        $resolved = @realpath($path);
        if ($resolved === false) {
            return null;
        }

        $stat = @stat($resolved);
        if ($stat !== false && $stat['ino'] !== 0) {
            return 'id:' . $stat['dev'] . ':' . $stat['ino'];
        }

        $canonical = rtrim(str_replace('\\', '/', $resolved), '/');
        return 'path:' . (DIRECTORY_SEPARATOR === '\\' ? strtolower($canonical) : $canonical);
    }

    /** Whether two existing names refer to the same physical entry. */
    public static function same(string $left, string $right): bool
    {
        $leftIdentity = self::identity($left);
        return $leftIdentity !== null && $leftIdentity === self::identity($right);
    }

    /** Return the physical path below a root, independent of DOS 8.3 spelling. */
    public static function relativeTo(string $path, string $root): ?string
    {
        $rootIdentity = self::identity($root);
        $resolved = @realpath($path);
        if ($rootIdentity === null || $resolved === false) {
            return null;
        }
        $current = str_replace('\\', '/', $resolved);
        $components = [];
        while (true) {
            if (self::identity($current) === $rootIdentity) {
                return implode('/', array_reverse($components));
            }
            $parent = str_replace('\\', '/', dirname($current));
            if ($parent === $current) {
                return null;
            }
            $components[] = basename($current);
            $current = $parent;
        }
    }

    /** An existing entry is within a physical directory, including that root. */
    public static function within(string $path, string $root): bool
    {
        return self::relativeTo($path, $root) !== null;
    }

    /**
     * Require every component to be a normal file or directory, never a link.
     * Windows junctions may not satisfy is_link(). Their lstat type is not a
     * normal file or directory; comparing parent identities also rejects
     * redirected entries. readlink() is unsuitable here because PHP can return
     * an ordinary directory's own path on Windows.
     */
    public static function unlinked(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (!self::absolute($path) || preg_match('~(?:\A|/)(?:\.|\.\.)(?:/|\z)~D', $path) === 1) {
            return false;
        }

        $current = rtrim($path, '/');
        if (preg_match('~\A[A-Za-z]:\z~D', $current) === 1) {
            $current .= '/';
        } elseif ($current === '') {
            $current = '/';
        }

        while (true) {
            if (is_link($current) || !file_exists($current)) {
                return false;
            }
            $entry = @lstat($current);
            $target = @stat($current);
            if ($entry === false || $target === false
                || !in_array($entry['mode'] & 0170000, [0100000, 0040000], true)
                || $entry['dev'] !== $target['dev'] || $entry['ino'] !== $target['ino']) {
                return false;
            }

            if (self::root($current)) {
                return true;
            }
            $parent = str_replace('\\', '/', dirname($current));
            if ($parent === $current) {
                return false;
            }
            $resolved = @realpath($current);
            if ($resolved === false || !self::same($parent, dirname($resolved))) {
                return false;
            }
            $current = $parent;
        }
    }

    private static function absolute(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match('~\A[A-Za-z]:/~D', $path) === 1
                || preg_match('~\A//[^/]+/[^/]+(?:/|\z)~D', $path) === 1;
        }
        return str_starts_with($path, '/');
    }

    private static function root(string $path): bool
    {
        if ($path === '/') {
            return true;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return (preg_match('~\A[A-Za-z]:/~D', $path) === 1 && strlen($path) === 3)
                || preg_match('~\A//[^/]+/[^/]+/?\z~D', $path) === 1;
        }
        return false;
    }
}
