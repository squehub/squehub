<?php

declare(strict_types=1);

namespace App\Storage\Drivers;

use App\Storage\StorageDriver;
use App\Storage\StorageException;
use App\Storage\StoragePath;
use App\Storage\StorageStream;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Application-controlled local files rooted at one physical directory.
 * Locks coordinate SqueHub callers, not hostile external filesystem writers.
 */
final class LocalStorageDriver implements StorageDriver
{
    private string $root;

    public function __construct(string $root)
    {
        // The manager resolves relative configuration against Application's
        // base path. Construction is deliberately free of filesystem writes.
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        if ($this->root === '' || !self::absolute($this->root)) throw new StorageException('Storage root must be absolute.');
        if (str_starts_with($this->root, '//')) throw new StorageException('UNC Storage roots are not supported.');
    }

    public function read(string $path): string
    {
        return $this->locked([$path], function () use ($path): string {
            $file = $this->file($path);
            $bytes = @file_get_contents($file);
            if ($bytes === false) throw StorageException::forPath('read', $path);
            return $bytes;
        });
    }

    public function write(string $path, string $contents): void
    {
        $this->locked([$path], function () use ($path, $contents): void {
            $this->replace($path, static function ($output) use ($contents, $path): void {
                $offset = 0;
                $length = strlen($contents);
                while ($offset < $length) {
                    $written = fwrite($output, substr($contents, $offset, 65536));
                    if ($written === false || $written === 0) throw StorageException::forPath('write', $path);
                    $offset += $written;
                }
            });
        });
    }

    public function exists(string $path): bool
    {
        return $this->locked([$path], fn (): bool => $this->entry($path) !== null);
    }

    public function remove(string $path): bool
    {
        return $this->locked([$path], function () use ($path): bool {
            $file = $this->entry($path);
            if ($file === null) return false;
            if (is_dir($file) || !@unlink($file)) throw StorageException::forPath('remove', $path);
            return true;
        });
    }

    public function copy(string $source, string $destination, bool $overwrite = false): void
    {
        $this->locked([$source, $destination], function () use ($source, $destination, $overwrite): void {
            $this->copyLocked($source, $destination, $overwrite);
        });
    }

    public function move(string $source, string $destination, bool $overwrite = false): void
    {
        $this->locked([$source, $destination], function () use ($source, $destination, $overwrite): void {
            $sourceFile = $this->file($source);
            $this->copyLocked($source, $destination, $overwrite);
            if (!@unlink($sourceFile)) throw StorageException::forPath('move source removal', $source);
        });
    }

    public function size(string $path): int
    {
        return $this->locked([$path], function () use ($path): int {
            $size = @filesize($this->file($path));
            if ($size === false) throw StorageException::forPath('size', $path);
            return $size;
        });
    }

    public function modifiedAt(string $path): DateTimeImmutable
    {
        return $this->locked([$path], function () use ($path): DateTimeImmutable {
            $time = @filemtime($this->file($path));
            if ($time === false) throw StorageException::forPath('modifiedAt', $path);
            return (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('UTC'));
        });
    }

    public function mimeType(string $path): ?string
    {
        return $this->locked([$path], function () use ($path): ?string {
            $file = $this->file($path);
            if (!class_exists(\finfo::class)) return null;
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
            return is_string($type) ? $type : null;
        });
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->locked([$directory], fn (): array => $this->listing($directory, $recursive, false));
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        return $this->locked([$directory], fn (): array => $this->listing($directory, $recursive, true));
    }

    public function makeDirectory(string $path): void
    {
        $this->locked([$path], function () use ($path): void {
            $this->parents($path, true);
        });
    }

    public function removeDirectory(string $path, bool $recursive = false): void
    {
        // The global exclusive lock prevents cooperating writes from entering
        // a subtree while it is inspected and removed. Root deletion is invalid.
        $path = StoragePath::validate($path);
        $this->locked([$path], function () use ($path, $recursive): void {
            $directory = $this->directory($path);
            $entries = $this->tree($directory);
            if (!$recursive && $entries !== []) throw StorageException::forPath('removeDirectory', $path);
            foreach ($entries as $entry) {
                if (is_dir($entry) ? !@rmdir($entry) : !@unlink($entry)) {
                    throw StorageException::forPath('removeDirectory', $path);
                }
            }
            if (!@rmdir($directory)) throw StorageException::forPath('removeDirectory', $path);
        });
    }

    public function readStream(string $path)
    {
        // The returned handle outlives this call, so the caller owns it. A
        // lock cannot be retained safely without a wrapped stream resource.
        return $this->locked([$path], function () use ($path) {
            $stream = @fopen($this->file($path), 'rb');
            if ($stream === false) throw StorageException::forPath('readStream', $path);
            return $stream;
        });
    }

    public function writeStream(string $path, $stream): void
    {
        StorageStream::readable($stream, $path);
        $this->locked([$path], function () use ($path, $stream): void {
            // Read from the caller's current cursor in bounded chunks. The
            // input belongs to the caller and remains open on every outcome.
            $this->replace($path, static function ($output) use ($path, $stream): void {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) throw StorageException::forPath('writeStream', $path);
                    $length = strlen($chunk);
                    for ($offset = 0; $offset < $length;) {
                        $written = fwrite($output, substr($chunk, $offset));
                        if ($written === false || $written === 0) throw StorageException::forPath('writeStream', $path);
                        $offset += $written;
                    }
                }
            });
        });
    }

    private function copyLocked(string $source, string $destination, bool $overwrite): void
    {
        if ($source === $destination) throw StorageException::forPath('copy', $destination);
        $file = $this->file($source);
        if (!$overwrite && $this->entry($destination) !== null) throw StorageException::forPath('copy destination exists', $destination);
        $input = @fopen($file, 'rb');
        if ($input === false) throw StorageException::forPath('copy', $source);
        try {
            $this->replace($destination, static function ($output) use ($input, $source): void {
                $copied = stream_copy_to_stream($input, $output);
                if ($copied === false) throw StorageException::forPath('copy', $source);
            });
        } finally {
            fclose($input);
        }
    }

    /** Temporary and backup names are reserved and hidden from listings. */
    private function replace(string $path, callable $writer): void
    {
        $destination = $this->entry($path);
        if ($destination !== null && is_dir($destination)) throw StorageException::forPath('write', $path);
        $this->parents($path);
        $destination = $this->physical($path);
        $temporary = dirname($destination) . '/.squehub-tmp-' . bin2hex(random_bytes(16));
        $backup = dirname($destination) . '/.squehub-tmp-' . bin2hex(random_bytes(16));
        $output = @fopen($temporary, 'x+b');
        if ($output === false) throw StorageException::forPath('write', $path);
        try {
            try {
                $writer($output);
                if (!fflush($output)) throw StorageException::forPath('write', $path);
            } finally {
                fclose($output);
            }
            // Windows may refuse rename over an existing target. Readers using
            // this driver wait on the lock through the backup/replace interval.
            if (file_exists($destination)) {
                if (!@rename($destination, $backup)) throw StorageException::forPath('write replacement', $path);
            }
            if (!@rename($temporary, $destination)) {
                if (file_exists($backup)) @rename($backup, $destination);
                throw StorageException::forPath('write replacement', $path);
            }
            if (file_exists($backup)) @unlink($backup);
        } finally {
            if (file_exists($temporary)) @unlink($temporary);
        }
    }

    /** Global namespace lock and sorted path locks prevent inverse-operation deadlocks. */
    private function locked(array $paths, callable $callback): mixed
    {
        foreach ($paths as $path) StoragePath::validate($path, true);
        $this->ready();
        $lockDirectory = $this->root . '/.squehub/locks';
        $this->internalDirectory($lockDirectory);
        $names = ['global'];
        foreach ($paths as $path) $names[] = hash('sha256', $path);
        sort($names, SORT_STRING);
        $names = array_unique($names);
        $handles = [];
        try {
            foreach ($names as $name) {
                $handle = @fopen($lockDirectory . '/' . $name . '.lock', 'c+b');
                if ($handle === false || !@flock($handle, LOCK_EX)) throw new StorageException('Storage lock acquisition failed.');
                $handles[] = $handle;
            }
            return $callback();
        } finally {
            foreach (array_reverse($handles) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function ready(): void
    {
        $this->checkRootComponents();
        if (!is_dir($this->root) && !@mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            throw new StorageException('Storage root is unavailable.');
        }
        if (!is_dir($this->root)) throw new StorageException('Storage root is not a directory.');
        $this->checkRootComponents();
    }

    /** Reject linked or reparsed root ancestors before recursive root creation. */
    private function checkRootComponents(): void
    {
        $path = $this->root;
        $current = str_starts_with($path, '/') ? '/' : substr($path, 0, 3);
        $tail = str_starts_with($path, '/') ? ltrim($path, '/') : substr($path, 3);
        foreach (explode('/', $tail) as $part) {
            if ($part === '') continue;
            $current = rtrim($current, '/') . '/' . $part;
            if (is_link($current)) throw new StorageException('Storage root cannot contain a symbolic link.');
            if (!file_exists($current)) continue;
            $real = realpath($current);
            if ($real === false || !self::samePath(str_replace('\\', '/', $real), $current)) {
                throw new StorageException('Storage root contains a reparse or linked path.');
            }
        }
    }

    private function internalDirectory(string $directory): void
    {
        $relative = substr($directory, strlen($this->root) + 1);
        // Check every existing parent before mkdir: an attacker-controlled
        // .squehub link must not cause internal locks to be created outside.
        $this->physical($relative, true);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new StorageException('Storage lock directory is unavailable.');
        }
        $this->physical($relative, true);
    }

    /**
     * Check every existing component before use. realpath catches junctions
     * that PHP may not classify as links; new children inherit the checked
     * nearest existing parent. Portable PHP cannot close external swap races.
     */
    private function physical(string $path, bool $internal = false): string
    {
        if (!$internal) StoragePath::validate($path, true);
        $current = $this->root;
        $root = str_replace('\\', '/', realpath($this->root) ?: $this->root);
        foreach (explode('/', $path) as $part) {
            if ($part === '') continue;
            $current .= '/' . $part;
            if (is_link($current)) throw StorageException::forPath('link rejected', $path);
            if (file_exists($current)) {
                $resolved = realpath($current);
                if ($resolved === false) throw StorageException::forPath('resolve', $path);
                $resolved = str_replace('\\', '/', $resolved);
                if (!self::contained($resolved, $root)) {
                    throw StorageException::forPath('containment', $path);
                }
                if (!self::samePath($resolved, $current)) {
                    throw StorageException::forPath('link rejected', $path);
                }
            }
        }
        return $current;
    }

    private function entry(string $path, bool $allowRoot = false): ?string
    {
        StoragePath::validate($path, $allowRoot);
        $physical = $this->physical($path);
        return file_exists($physical) ? $physical : null;
    }

    private function file(string $path): string
    {
        $file = $this->entry($path);
        if ($file === null || !is_file($file)) throw StorageException::forPath('file required', $path);
        return $file;
    }

    private function directory(string $path): string
    {
        $directory = $this->entry($path, true);
        if ($directory === null || !is_dir($directory)) throw StorageException::forPath('directory required', $path);
        return $directory;
    }

    private function parents(string $path, bool $includeLast = false): void
    {
        $parts = explode('/', $path);
        if (!$includeLast) array_pop($parts);
        $logical = '';
        foreach ($parts as $part) {
            $logical = $logical === '' ? $part : $logical . '/' . $part;
            $directory = $this->physical($logical);
            if (!is_dir($directory) && !@mkdir($directory) && !is_dir($directory)) {
                throw StorageException::forPath('create directory', $logical);
            }
        }
    }

    /** @return list<string> */
    private function listing(string $directory, bool $recursive, bool $wantDirectories): array
    {
        $base = $this->directory($directory);
        $result = [];
        $walk = function (string $physical, string $logical) use (&$walk, &$result, $recursive, $wantDirectories): void {
            $entries = @scandir($physical);
            if ($entries === false) throw StorageException::forPath('list', $logical);
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || strcasecmp($name, '.squehub') === 0
                    || str_starts_with(strtolower($name), '.squehub-tmp-')) continue;
                $child = $logical === '' ? $name : $logical . '/' . $name;
                $location = $this->physical($child);
                if (is_dir($location)) {
                    if ($wantDirectories) $result[] = $child;
                    if ($recursive) $walk($location, $child);
                } elseif (!$wantDirectories) {
                    $result[] = $child;
                }
            }
        };
        $walk($base, $directory);
        sort($result, SORT_STRING);
        return $result;
    }

    /** Inspect the full subtree before unlinking anything, so links never lead traversal. @return list<string> */
    private function tree(string $directory): array
    {
        $result = [];
        $walk = function (string $physical) use (&$walk, &$result): void {
            $entries = @scandir($physical);
            if ($entries === false) throw new StorageException('Storage directory scan failed.');
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') continue;
                $child = $physical . '/' . $name;
                $logical = substr($child, strlen($this->root) + 1);
                $this->physical($logical);
                if (is_dir($child)) $walk($child);
                $result[] = $child;
            }
        };
        $walk($directory);
        return $result;
    }

    private static function absolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '//');
    }

    private static function samePath(string $first, string $second): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($first, $second) === 0 : $first === $second;
    }

    private static function contained(string $path, string $root): bool
    {
        $prefix = rtrim($root, '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') return str_starts_with(strtolower($path . '/'), strtolower($prefix));
        return str_starts_with($path . '/', $prefix);
    }
}
