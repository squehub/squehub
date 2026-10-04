<?php

declare(strict_types=1);

namespace App\Locks\Stores;

use App\Locks\LockBackendException;
use App\Locks\LockConfigurationException;
use App\Locks\LockOwnershipException;
use App\Locks\LockStorageException;
use App\Locks\LockStore;

/**
 * A single-server lease store. One of 256 persistent hash-stripe flocks
 * protects each short read/replace/delete transition; the lease survives a
 * process crash until its bounded expiry. Mutex files are never unlinked
 * during normal use, so distinct application keys cannot grow their count.
 */
final class FileLockStore implements LockStore
{
    private string $directory;

    public function __construct(private string $root, string $namespace)
    {
        $drive = strlen($root) >= 3 && ctype_alpha($root[0]) && $root[1] === ':'
            && in_array($root[2], ['/', '\\'], true);
        if ($root === '' || !(str_starts_with($root, '/') || $drive || str_starts_with($root, '\\\\'))) {
            throw new LockConfigurationException('Lock file path must be absolute.');
        }
        $this->directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $namespace);
    }

    public function acquire(string $hash, string $token, int $now, int $expiresAt): bool
    {
        return $this->withMutex($hash, function () use ($hash, $token, $now, $expiresAt): bool {
            $path = $this->leasePath($hash);
            $lease = $this->readLease($path);
            if ($lease !== null && $lease['expires'] > $now) return false;
            $this->writeLease($path, $token, $expiresAt);
            return true;
        });
    }

    public function release(string $hash, string $token, int $now): bool
    {
        return $this->withMutex($hash, function () use ($hash, $token, $now): bool {
            $path = $this->leasePath($hash);
            $lease = $this->readLease($path);
            if ($lease === null) return false;
            if ($lease['expires'] <= $now) {
                $this->deleteLease($path);
                return false;
            }
            if (!hash_equals($lease['token'], $token)) {
                throw new LockOwnershipException('Lock belongs to another owner.');
            }
            $this->deleteLease($path);
            return true;
        });
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function withMutex(string $hash, callable $operation): mixed
    {
        self::validateHash($hash);
        $this->ensureDirectory();
        // The first digest byte is a stable stripe across cooperating PHP
        // processes. Different keys may serialize briefly, but no two
        // processes can transition the same lease under different mutexes.
        $path = $this->directory . DIRECTORY_SEPARATOR . substr($hash, 0, 2) . '.mutex';
        $this->assertRegularPath($path);
        $stream = @fopen($path, 'c+b');
        if ($stream === false) throw new LockBackendException('Lock file mutex is unavailable.');
        try {
            $deadline = hrtime(true) + 1_000_000_000;
            while (!@flock($stream, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new LockBackendException('Lock file mutex timed out.');
                }
                usleep(10_000);
            }
            try {
                $this->ensureDirectory();
                return $operation();
            } finally {
                @flock($stream, LOCK_UN);
            }
        } finally {
            fclose($stream);
        }
    }

    private function ensureDirectory(): void
    {
        for ($part = $this->root; $part !== dirname($part); $part = dirname($part)) {
            if (is_link($part)) throw new LockBackendException('Lock file root contains a link.');
        }
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new LockBackendException('Lock file root is unavailable.');
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root) || is_link($this->directory)) {
            throw new LockBackendException('Lock file root is invalid.');
        }
        if (!is_dir($this->directory)
            && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new LockBackendException('Lock file namespace is unavailable.');
        }
        $directory = realpath($this->directory);
        if ($directory === false || !is_dir($directory)
            || !self::samePath(dirname($directory), $root)) {
            throw new LockBackendException('Lock file namespace is invalid.');
        }
    }

    private function leasePath(string $hash): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $hash . '.lease';
    }

    /** @return array{token:string,expires:int}|null */
    private function readLease(string $path): ?array
    {
        $this->assertRegularPath($path);
        if (!file_exists($path)) return null;
        $stream = @fopen($path, 'rb');
        if ($stream === false) throw new LockBackendException('Lock lease cannot be read.');
        try {
            $data = @fread($stream, 513);
            if ($data === false) throw new LockBackendException('Lock lease cannot be read.');
            if (strlen($data) > 512 || !feof($stream)) {
                throw new LockStorageException('Lock lease record is corrupt.');
            }
        } finally {
            fclose($stream);
        }
        $record = json_decode($data, true);
        if (!is_array($record) || ($record['version'] ?? null) !== 1
            || !is_string($record['token'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $record['token']) !== 1
            || !is_int($record['expires'] ?? null) || $record['expires'] < 0) {
            throw new LockStorageException('Lock lease record is corrupt.');
        }
        return ['token' => $record['token'], 'expires' => $record['expires']];
    }

    private function writeLease(string $path, string $token, int $expiresAt): void
    {
        $this->assertRegularPath($path);
        $data = json_encode(['version' => 1, 'token' => $token, 'expires' => $expiresAt],
            JSON_THROW_ON_ERROR);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) throw new LockBackendException('Lock lease cannot be written.');
        try {
            try {
                @chmod($temporary, 0600);
                $offset = 0;
                while ($offset < strlen($data)) {
                    $written = @fwrite($stream, substr($data, $offset));
                    if ($written === false || $written === 0) {
                        throw new LockBackendException('Lock lease cannot be written.');
                    }
                    $offset += $written;
                }
                if (!@fflush($stream)) throw new LockBackendException('Lock lease cannot be written.');
            } finally {
                fclose($stream);
            }
            // Windows cannot always rename over an existing file. Under the
            // mutex, removing only an expired record before rename is safe.
            if (file_exists($path)) $this->deleteLease($path);
            if (!@rename($temporary, $path)) {
                throw new LockBackendException('Lock lease cannot be published.');
            }
        } finally {
            if (is_file($temporary) && !is_link($temporary)) @unlink($temporary);
        }
    }

    private function deleteLease(string $path): void
    {
        $this->assertRegularPath($path);
        if (file_exists($path) && !@unlink($path)) {
            throw new LockBackendException('Lock lease cannot be removed.');
        }
    }

    private function assertRegularPath(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new LockStorageException('Lock file path is invalid.');
        }
    }

    private static function validateHash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new LockConfigurationException('Lock key digest is invalid.');
        }
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = str_replace('\\', '/', $left);
        $right = str_replace('\\', '/', $right);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
