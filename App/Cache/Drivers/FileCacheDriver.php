<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

use App\Cache\CacheDriver;
use App\Cache\CacheEntry;
use App\Cache\CacheException;
use App\Cache\CacheResolution;
use App\Cache\CacheValue;
use InvalidArgumentException;
use Throwable;

/**
 * Local file cache. Namespace locks serialize clear against key operations;
 * per-key locks keep unrelated cache computations independent. Runtime files
 * are mutable data and must never be treated as authorization authority.
 */
final class FileCacheDriver implements CacheDriver
{
    private const VERSION = 1;
    private string $directory;

    public function __construct(private string $root, string $namespace)
    {
        $drivePath = strlen($root) >= 3 && ctype_alpha($root[0])
            && $root[1] === ':' && in_array($root[2], ['/', '\\'], true);
        if ($root === '' || !(str_starts_with($root, '/') || $drivePath || str_starts_with($root, '\\\\'))) {
            throw new InvalidArgumentException('Cache root must be absolute.');
        }
        $this->directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $namespace);
    }

    public function fetch(string $hash, callable $now): ?CacheEntry
    {
        return $this->withinNamespace(LOCK_SH, function () use ($hash, $now): ?CacheEntry {
            $entry = $this->withinKey($hash, LOCK_SH,
                fn (): array => $this->readRecord($this->dataPath($hash), $now()));
            if ($entry['stale']) {
                // Recheck under an exclusive lock: another writer may have
                // replaced the expired or corrupt record after our read.
                $entry = $this->withinKey($hash, LOCK_EX, function () use ($hash, $now): array {
                    $current = $this->readRecord($this->dataPath($hash), $now());
                    if ($current['stale']) $this->discard($this->dataPath($hash));
                    return $current;
                });
            }
            return $entry['entry'];
        });
    }

    public function put(string $hash, CacheEntry $entry): void
    {
        $this->withinNamespace(LOCK_SH, function () use ($hash, $entry): void {
            $this->withinKey($hash, LOCK_EX,
                fn () => $this->writeRecord($this->dataPath($hash), $entry));
        });
    }

    public function remove(string $hash, callable $now): bool
    {
        return $this->withinNamespace(LOCK_SH, function () use ($hash, $now): bool {
            return $this->withinKey($hash, LOCK_EX, function () use ($hash, $now): bool {
                $path = $this->dataPath($hash);
                $found = $this->readRecord($path, $now())['entry'] !== null;
                $this->deleteRecord($path);
                return $found;
            });
        });
    }

    public function take(string $hash, callable $now): ?CacheEntry
    {
        return $this->withinNamespace(LOCK_SH, function () use ($hash, $now): ?CacheEntry {
            return $this->withinKey($hash, LOCK_EX, function () use ($hash, $now): ?CacheEntry {
                $path = $this->dataPath($hash);
                $entry = $this->readRecord($path, $now())['entry'];
                $this->deleteRecord($path);
                return $entry;
            });
        });
    }

    public function remember(string $hash, callable $now, callable $producer): CacheResolution
    {
        return $this->withinNamespace(LOCK_SH, function () use ($hash, $now, $producer): CacheResolution {
            return $this->withinKey($hash, LOCK_EX, function () use ($hash, $now, $producer): CacheResolution {
                $path = $this->dataPath($hash);
                $current = $this->readRecord($path, $now())['entry'];
                if ($current !== null) return new CacheResolution($current, false);
                // Application exceptions propagate unchanged; finally blocks
                // release both locks without publishing a partial value.
                $entry = $producer();
                $this->writeRecord($path, $entry);
                return new CacheResolution($entry, true);
            });
        });
    }

    public function clear(): void
    {
        $this->withinNamespace(LOCK_EX, function (): void {
            $entries = @scandir($this->directory);
            if ($entries === false) throw new CacheException('Cache namespace cannot be listed.');
            foreach ($entries as $name) {
                // The exclusive namespace lock protects this nonrecursive,
                // allowlisted deletion from cooperating readers and writers.
                if (!preg_match('/\A[a-f0-9]{64}\.(cache|lock)\z/D', $name)) continue;
                $path = $this->directory . DIRECTORY_SEPARATOR . $name;
                if (is_link($path) || !is_file($path) || !@unlink($path)) {
                    throw new CacheException('Cache namespace could not be cleared.');
                }
            }
        });
    }

    /** @template T @param callable():T $operation @return T */
    private function withinNamespace(int $mode, callable $operation): mixed
    {
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . '.namespace.lock';
        $this->assertRegularPath($path);
        $lock = @fopen($path, 'c+b');
        if ($lock === false) throw new CacheException('Cache namespace lock cannot be opened.');
        try {
            if (!@flock($lock, $mode)) throw new CacheException('Cache namespace lock failed.');
            try {
                $this->ensureDirectory();
                return $operation();
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /** @template T @param callable():T $operation @return T */
    private function withinKey(string $hash, int $mode, callable $operation): mixed
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $hash . '.lock';
        $this->assertRegularPath($path);
        $lock = @fopen($path, 'c+b');
        if ($lock === false) throw new CacheException('Cache entry lock cannot be opened.');
        try {
            if (!@flock($lock, $mode)) throw new CacheException('Cache entry lock failed.');
            try {
                return $operation();
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new CacheException('Cache root cannot be created.');
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root)) throw new CacheException('Cache root is unavailable.');
        if (is_link($this->directory)) throw new CacheException('Cache namespace is not a directory.');
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770) && !is_dir($this->directory)) {
            throw new CacheException('Cache namespace cannot be created.');
        }
        $directory = realpath($this->directory);
        // Recheck containment after creation and before opening entry files;
        // a namespace link must not redirect clear or writes outside the root.
        if ($directory === false || !is_dir($directory) || dirname($directory) !== $root) {
            throw new CacheException('Cache namespace is outside its root.');
        }
    }

    private function dataPath(string $hash): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $hash . '.cache';
    }

    private function assertRegularPath(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new CacheException('Cache entry path is invalid.');
        }
    }

    /** @return array{entry:?CacheEntry,stale:bool} */
    private function readRecord(string $path, int $now): array
    {
        $this->assertRegularPath($path);
        if (!file_exists($path)) return ['entry' => null, 'stale' => false];
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new CacheException('Cache entry cannot be read.');
        try {
            $record = @stream_get_contents($stream);
            if ($record === false) throw new CacheException('Cache entry cannot be read.');
        } finally {
            fclose($stream);
        }
        // Never instantiate a class from a mutable runtime cache file.
        try {
            $envelope = @unserialize($record, ['allowed_classes' => false]);
        } catch (Throwable) {
            return ['entry' => null, 'stale' => true];
        }
        if (!is_array($envelope) || ($envelope['version'] ?? null) !== self::VERSION
            || !array_key_exists('expires_at', $envelope)
            || (!is_int($envelope['expires_at']) && $envelope['expires_at'] !== null)
            || !isset($envelope['payload']) || !is_string($envelope['payload'])) {
            return ['entry' => null, 'stale' => true];
        }
        $payload = $envelope['payload'];
        try {
            $value = @unserialize($payload, ['allowed_classes' => false]);
        } catch (Throwable) {
            return ['entry' => null, 'stale' => true];
        }
        if ($value === false && $payload !== 'b:0;') return ['entry' => null, 'stale' => true];
        try {
            CacheValue::validate($value);
        } catch (Throwable) {
            // Damaged or class-bearing payloads are invalid cache entries,
            // unlike real filesystem errors, which remain CacheException.
            return ['entry' => null, 'stale' => true];
        }
        $entry = new CacheEntry($value, $envelope['expires_at']);
        return $entry->expired($now)
            ? ['entry' => null, 'stale' => true] : ['entry' => $entry, 'stale' => false];
    }

    private function writeRecord(string $path, CacheEntry $entry): void
    {
        $this->assertRegularPath($path);
        CacheValue::validate($entry->value);
        $record = serialize(['version' => self::VERSION,
            'expires_at' => $entry->expiresAt, 'payload' => serialize($entry->value)]);
        $stream = @fopen($path, 'c+b');
        if ($stream === false) throw new CacheException('Cache entry cannot be written.');
        try {
            if (!@ftruncate($stream, 0) || !@rewind($stream)) {
                throw new CacheException('Cache entry cannot be written.');
            }
            $offset = 0;
            while ($offset < strlen($record)) {
                $written = @fwrite($stream, substr($record, $offset));
                if ($written === false || $written === 0) throw new CacheException('Cache entry cannot be written.');
                $offset += $written;
            }
            if (!@fflush($stream)) throw new CacheException('Cache entry cannot be written.');
        } finally {
            fclose($stream);
        }
    }

    private function discard(string $path): void
    {
        // Corruption cleanup is best effort; the miss remains usable.
        if (is_file($path) && !is_link($path)) @unlink($path);
    }

    private function deleteRecord(string $path): void
    {
        $this->assertRegularPath($path);
        if (file_exists($path) && !@unlink($path)) {
            throw new CacheException('Cache entry cannot be removed.');
        }
    }
}
