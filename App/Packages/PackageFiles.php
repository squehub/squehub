<?php

declare(strict_types=1);

namespace App\Packages;

use App\Clis\PackageRemoval;
use App\Support\PhysicalPath;

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
        $physical = PhysicalPath::identity($directory);
        if ($physical === null) {
            throw new PackageException('Package path cannot be resolved.');
        }
        if (in_array($physical, $ancestors, true)) {
            throw new PackageException('Package View directory link is cyclic.');
        }
        $ancestors[] = $physical;
        foreach (self::entries($directory, $excludeGit) as $entry) {
            $path = $directory . '/' . $entry;
            $childIsView = $insideViews || ($viewRoot !== null
                && PhysicalPath::same($path, $viewRoot));
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
        $physical = PhysicalPath::identity($directory);
        if ($physical === null) {
            throw new PackageException('Package path cannot be resolved.');
        }
        if (in_array($physical, $ancestors, true)) {
            throw new PackageException('Package View directory link is cyclic.');
        }
        $ancestors[] = $physical;
        foreach (self::entries($directory, $excludeGit && $relative === '') as $entry) {
            $child = ltrim($relative . '/' . $entry, '/');
            $path = $root . '/' . $child;
            $childIsView = $insideViews || ($viewRoot !== null
                && PhysicalPath::same($path, $viewRoot));
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
                && !PhysicalPath::unlinked($path)) {
                $relativeTarget = PhysicalPath::relativeTo($resolved, $viewRoot);
                if ($relativeTarget === null || $relativeTarget === '') {
                    throw new PackageException('Package View link leaves its View root.');
                }
                $hash = hash('sha256', "view-link\0"
                    . $relativeTarget . "\0" . $hash);
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

    private static function isWithin(string $path, string $root): bool
    {
        return !PhysicalPath::same($path, $root) && PhysicalPath::within($path, $root);
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
        if (!is_dir($parent) || !is_dir($target)
            || !PhysicalPath::same(dirname($target), $parent)) {
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

    /** Reject symlinks, junctions, and redirected ancestor entries. */
    public static function assertPhysical(string $path): void
    {
        if (!PhysicalPath::unlinked($path)) {
            throw new PackageException('Package path is unavailable or linked.');
        }
    }
}
