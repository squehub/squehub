<?php

declare(strict_types=1);

namespace App\RateLimit\Stores;

use App\RateLimit\FixedWindow;
use App\RateLimit\RateLimitException;
use App\RateLimit\RateLimitResult;
use App\RateLimit\RateLimitStore;
use DateTimeImmutable;
use JsonException;

/**
 * Local/cooperating-process store. A per-key flock covers read, decision, and
 * replacement; network filesystems and multiple servers have no such guarantee.
 * Malformed state fails closed rather than silently granting a fresh window.
 */
final class FileRateLimitStore implements RateLimitStore
{
    private string $directory;

    public function __construct(private string $root, string $prefix)
    {
        $drive = strlen($root) >= 3 && ctype_alpha($root[0]) && $root[1] === ':'
            && in_array($root[2], ['/', '\\'], true);
        if ($root === '' || !(str_starts_with($root, '/') || $drive || str_starts_with($root, '\\\\'))) {
            throw new RateLimitException('Rate-limit root must be absolute.');
        }
        $this->directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $prefix);
    }

    public function consume(string $fingerprint, int $maxAttempts, int $windowSeconds, DateTimeImmutable $now): RateLimitResult
    {
        return $this->locked($fingerprint, function (string $path) use ($maxAttempts, $windowSeconds, $now): RateLimitResult {
            [$record, $result] = FixedWindow::consume($this->read($path), $maxAttempts, $windowSeconds, $now);
            $this->write($path, $record);
            return $result;
        });
    }

    public function clear(string $fingerprint): bool
    {
        return $this->locked($fingerprint, function (string $path): bool {
            $this->regular($path);
            if (!file_exists($path)) return false;
            if (!@unlink($path)) throw new RateLimitException('Rate-limit record cannot be removed.');
            return true;
        });
    }

    /**
     * @template T
     * @param callable(string):T $operation
     * @return T
     */
    private function locked(string $fingerprint, callable $operation): mixed
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new RateLimitException('Invalid rate-limit fingerprint.');
        }
        $this->ensureDirectory();
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . $fingerprint . '.lock';
        $this->regular($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new RateLimitException('Rate-limit lock cannot be opened.');
        try {
            if (!@flock($lock, LOCK_EX)) throw new RateLimitException('Rate-limit lock cannot be acquired.');
            try {
                $this->ensureDirectory();
                return $operation($this->directory . DIRECTORY_SEPARATOR . $fingerprint . '.json');
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_link($this->root) || (!is_dir($this->root) && !@mkdir($this->root, 0770, true) && !is_dir($this->root))) {
            throw new RateLimitException('Rate-limit root cannot be created.');
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root) || is_link($this->directory)) {
            throw new RateLimitException('Rate-limit root is unavailable.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770) && !is_dir($this->directory)) {
            throw new RateLimitException('Rate-limit namespace cannot be created.');
        }
        $directory = realpath($this->directory);
        if ($directory === false || dirname($directory) !== $root) {
            throw new RateLimitException('Rate-limit namespace is outside its root.');
        }
    }

    private function regular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RateLimitException('Invalid rate-limit entry path.');
        }
    }

    /** @return array{version:int,count:int,limit:int,window_seconds:int,resets_at:int}|null */
    private function read(string $path): ?array
    {
        $this->regular($path);
        if (!file_exists($path)) return null;
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) > 1024) throw new RateLimitException('Rate-limit record cannot be read.');
        try {
            $record = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RateLimitException('Rate-limit record is corrupt.', 0, $failure);
        }
        if (!is_array($record) || array_keys($record) !== ['version', 'count', 'limit', 'window_seconds', 'resets_at']
            || $record['version'] !== 1 || !is_int($record['count']) || !is_int($record['limit'])
            || !is_int($record['window_seconds']) || !is_int($record['resets_at'])
            || $record['limit'] < 1 || $record['limit'] >= PHP_INT_MAX
            || $record['window_seconds'] < 1 || $record['count'] < 1
            || $record['count'] > $record['limit'] + 1 || $record['resets_at'] < 1) {
            throw new RateLimitException('Rate-limit record is corrupt.');
        }
        return $record;
    }

    /** @param array{version:int,count:int,limit:int,window_seconds:int,resets_at:int} $record */
    private function write(string $path, array $record): void
    {
        $this->regular($path);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) throw new RateLimitException('Rate-limit record cannot be written.');
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = @fwrite($stream, substr($json, $offset));
                if ($written === false || $written === 0) throw new RateLimitException('Rate-limit record cannot be written.');
                $offset += $written;
            }
            if (!@fflush($stream)) throw new RateLimitException('Rate-limit record cannot be flushed.');
        } catch (\Throwable $failure) {
            // A failed write never replaces the last good counter. Discard its
            // private temporary file while the lock still belongs to us.
            fclose($stream);
            @unlink($temporary);
            throw $failure;
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        // Replacement is attempted without deleting the old record. If the OS
        // refuses it, the previous counter survives and the operation fails.
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RateLimitException('Rate-limit record cannot be replaced.');
        }
    }
}
