<?php

declare(strict_types=1);

namespace App\Packages;

use App\Clis\PackageRemoval;

/**
 * Contained, physical Package trees and their owned file fingerprints.
 *
 * A Package source is executable code after activation. Discovery and copying
 * inspect bytes only; neither step includes PHP. Installed Package Views may
 * resolve links contained by their own View root, matching View rendering.
 */
final class PackageFiles
{
    /** Validate a tree for discovery without hashing every Package asset on each request. */
    public static function inspectTree(
        string $root,
        bool $excludeGit = false,
        bool $allowContainedViewLinks = false
    ): void
    {
        self::assertPhysical($root);
        if (!is_dir($root)) {
            throw new PackageException('Package source is not a directory.');
        }
        self::walkSafe($root, $excludeGit, self::viewRoot($root, $allowContainedViewLinks));
    }

    /** @param list<string> $ancestors */
    private static function walkSafe(
        string $directory,
        bool $excludeGit,
        ?string $viewRoot,
        bool $insideViews = false,
        array $ancestors = []
    ): void
    {
        $physical = self::physical($directory);
        if (in_array($physical, $ancestors, true)) {
            throw new PackageException('Package View directory link is cyclic.');
        }
        $ancestors[] = $physical;
        foreach (self::entries($directory, $excludeGit) as $entry) {
            $path = $directory . '/' . $entry;
            $childIsView = $insideViews || ($viewRoot !== null
                && self::physical($path) === $viewRoot);
            self::inspectEntry($path, $childIsView ? $viewRoot : null);
            if (is_dir($path)) {
                self::walkSafe($path, false, $viewRoot, $childIsView, $ancestors);
            } elseif (!is_file($path) || !is_readable($path)) {
                throw new PackageException('Package file cannot be inspected.');
            }
        }
    }

    /** @return array<string, string> Relative slash path => SHA-256. */
    public static function fingerprints(
        string $root,
        bool $excludeGit = false,
        bool $allowContainedViewLinks = false
    ): array
    {
        self::assertPhysical($root);
        if (!is_dir($root)) {
            throw new PackageException('Package source is not a directory.');
        }

        $files = [];
        self::walk($root, '', $files, $excludeGit,
            self::viewRoot($root, $allowContainedViewLinks));
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @param array<string, string> $files */
    /** @param list<string> $ancestors */
    private static function walk(
        string $root,
        string $relative,
        array &$files,
        bool $excludeGit,
        ?string $viewRoot,
        bool $insideViews = false,
        array $ancestors = []
    ): void
    {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $physical = self::physical($directory);
        if (in_array($physical, $ancestors, true)) {
            throw new PackageException('Package View directory link is cyclic.');
        }
        $ancestors[] = $physical;
        foreach (self::entries($directory, $excludeGit && $relative === '') as $entry) {
            $child = ltrim($relative . '/' . $entry, '/');
            $path = $root . '/' . $child;
            $childIsView = $insideViews || ($viewRoot !== null
                && self::physical($path) === $viewRoot);
            $resolved = self::inspectEntry($path, $childIsView ? $viewRoot : null);
            if (is_dir($path)) {
                self::walk($root, $child, $files, false, $viewRoot, $childIsView, $ancestors);
                continue;
            }
            if (!is_file($path)) {
                throw new PackageException('Package contains an unsupported filesystem entry.');
            }
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                throw new PackageException('Package file cannot be fingerprinted.');
            }
            // A link target is part of source identity even when two targets
            // have the same bytes. Store only its path relative to Views.
            if ($childIsView && $viewRoot !== null
                && $resolved !== self::lexical($path)) {
                $hash = hash('sha256', "view-link\0"
                    . substr($resolved, strlen($viewRoot) + 1) . "\0" . $hash);
            }
            $files[str_replace('\\', '/', $child)] = $hash;
        }
    }

    /** The Views directory itself must be physical; links are allowed only below it. */
    private static function viewRoot(string $root, bool $allow): ?string
    {
        if (!$allow) { return null; }
        foreach (['Views', 'views'] as $name) {
            $candidate = $root . '/' . $name;
            if (!file_exists($candidate) && !is_link($candidate)) { continue; }
            if (is_file($candidate) && !is_link($candidate)) { continue; }
            self::assertPhysical($candidate);
            if (!is_dir($candidate)) {
                throw new PackageException('Package View root is not a directory.');
            }
            return self::physical($candidate);
        }
        return null;
    }

    /**
     * Managed Package sources remain link-free. For an installed Package,
     * only children of its physical Views root may follow contained links.
     */
    private static function inspectEntry(string $path, ?string $viewRoot): string
    {
        try {
            self::assertPhysical($path);
        } catch (PackageException $exception) {
            if ($viewRoot === null) { throw $exception; }
            $resolved = self::physical($path);
            if (!self::isWithin($resolved, $viewRoot)) {
                throw new PackageException('Package View link leaves its View root.', 0, $exception);
            }
            if (!is_readable($path) || (!is_file($path) && !is_dir($path))) {
                throw new PackageException('Package View link is unavailable.', 0, $exception);
            }
            return $resolved;
        }
        return self::physical($path);
    }

    private static function physical(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new PackageException('Package path cannot be resolved.');
        }
        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private static function lexical(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function isWithin(string $path, string $root): bool
    {
        $prefix = $root . '/';
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : strncmp($path, $prefix, strlen($prefix)) === 0;
    }

    /** @return list<string> All physical directories relative to the Package root. */
    public static function directories(string $root): array
    {
        self::assertPhysical($root);
        $directories = [];
        self::walkDirectories($root, '', $directories);
        sort($directories, SORT_STRING);
        return $directories;
    }

    /** @param list<string> $directories */
    private static function walkDirectories(string $root, string $relative, array &$directories): void
    {
        $path = $relative === '' ? $root : $root . '/' . $relative;
        foreach (self::entries($path) as $entry) {
            $child = ltrim($relative . '/' . $entry, '/');
            $full = $root . '/' . $child;
            self::assertPhysical($full);
            if (is_dir($full)) {
                $directories[] = str_replace('\\', '/', $child);
                self::walkDirectories($root, $child, $directories);
            }
        }
    }

    /**
     * Reject sibling case collisions before hashing or copying a source tree.
     * A case-sensitive host may store both names, but a later Windows install
     * would silently collapse them into one path.
     *
     * @return list<string>
     */
    private static function entries(string $directory, bool $excludeGit = false): array
    {
        $names = @scandir($directory);
        if ($names === false) {
            throw new PackageException('Package directory cannot be read.');
        }
        $entries = [];
        $folded = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || ($excludeGit && $name === '.git')) { continue; }
            self::assertPortableName($name);
            $key = strtolower($name);
            if (isset($folded[$key]) && $folded[$key] !== $name) {
                throw new PackageException('Package contains case-colliding filenames.');
            }
            $folded[$key] = $name;
            $entries[] = $name;
        }
        return $entries;
    }

    private static function assertPortableName(string $name): void
    {
        if ($name === '' || preg_match('/[\x00-\x1F\x7F\\\\:<>"|?*]/', $name)
            || str_ends_with($name, '.') || str_ends_with($name, ' ')
            || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $name)) {
            throw new PackageException('Package contains a nonportable filename.');
        }
    }

    /**
     * Copies only files from an already validated tree into a new staging root.
     * Rechecking each source path narrows the race with external file changes.
     */
    /** @param array<string, string> $reviewedFiles Plan-approved relative paths and hashes. */
    public static function copy(string $source, string $destination, array $reviewedFiles): void
    {
        $files = self::fingerprints($source, true);
        if ($files !== $reviewedFiles) {
            throw new PackageException('Package source changed after review.');
        }
        if (file_exists($destination)) {
            throw new PackageException('Package staging destination already exists.');
        }
        if (!@mkdir($destination, 0775, true)) {
            throw new PackageException('Package staging directory could not be created.');
        }
        foreach ($reviewedFiles as $relative => $expected) {
            $from = $source . '/' . $relative;
            self::assertPhysical($from);
            $parent = dirname($destination . '/' . $relative);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new PackageException('Package staging directory could not be created.');
            }
            self::assertPhysical($parent);
            $to = $destination . '/' . $relative;
            $reader = @fopen($from, 'rb');
            $writer = @fopen($to, 'xb');
            if ($reader === false || $writer === false) {
                if (is_resource($reader)) { fclose($reader); }
                if (is_resource($writer)) { fclose($writer); }
                throw new PackageException('Package staging file could not be created safely.');
            }
            try {
                $copied = @stream_copy_to_stream($reader, $writer);
            } finally {
                fclose($reader);
                fclose($writer);
            }
            if ($copied === false || @hash_file('sha256', $to) !== $expected) {
                throw new PackageException('Package source changed while being staged.');
            }
        }
    }

    /** Delete only a preflighted tree directly below Project/Packages. */
    public static function remove(string $target, string $basePath): void
    {
        try {
            PackageRemoval::remove($target, $basePath);
        } catch (\Throwable $exception) {
            throw new PackageException('Package files could not be removed safely.', 0, $exception);
        }
    }

    /** Remove only a caller-created temporary tree immediately below its parent. */
    public static function removeTemporary(string $target, string $parent): void
    {
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false || !is_dir($target)
            || dirname(str_replace('\\', '/', $target)) !== str_replace('\\', '/', $parent)) {
            throw new PackageException('Temporary Package path is outside its expected parent.');
        }
        self::assertPhysical($target);
        $entries = [];
        self::collectTemporary($target, $entries);
        foreach (array_reverse($entries) as $entry) {
            if (is_link($entry) ? !@unlink($entry)
                : (is_dir($entry) ? !@rmdir($entry) : !@unlink($entry))) {
                throw new PackageException('Temporary Package files could not be removed.');
            }
        }
        if (!@rmdir($target)) {
            throw new PackageException('Temporary Package directory could not be removed.');
        }
    }

    /** @param list<string> $entries */
    private static function collectTemporary(string $directory, array &$entries): void
    {
        $names = @scandir($directory);
        if ($names === false) {
            throw new PackageException('Temporary Package directory cannot be read.');
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $path = $directory . '/' . $name;
            if (is_link($path)) {
                $entries[] = $path;
                continue;
            }
            self::assertPhysical($path);
            $entries[] = $path;
            if (is_dir($path)) { self::collectTemporary($path, $entries); }
        }
    }

    /** Reject symlinks, junctions, and paths whose physical location differs. */
    public static function assertPhysical(string $path): void
    {
        if (is_link($path) || !file_exists($path)) {
            throw new PackageException('Package path is unavailable or linked.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new PackageException('Package path cannot be resolved.');
        }
        $actual = rtrim(str_replace('\\', '/', $resolved), '/');
        $expected = rtrim(str_replace('\\', '/', $path), '/');
        $same = DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($actual, $expected) === 0
            : $actual === $expected;
        if (!$same) {
            throw new PackageException('Package path escapes through a linked or reparsed entry.');
        }
    }
}
