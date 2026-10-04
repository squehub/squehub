<?php

declare(strict_types=1);

namespace App\Idempotency\Stores;

use App\Idempotency\ClaimResult;
use App\Idempotency\IdempotencyException;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Record;
use App\Idempotency\ResponseSnapshot;
use DirectoryIterator;
use SplMaxHeap;

/** Bounded striped flock and atomic replacement on one local server only. */
final class FileIdempotencyStore implements IdempotencyStore
{
    private string $directory;

    public function __construct(private string $root, string $namespace)
    {
        $drive = strlen($root) >= 3 && ctype_alpha($root[0]) && $root[1] === ':'
            && in_array($root[2], ['/', '\\'], true);
        if ($root === '' || !(str_starts_with($root, '/') || $drive || str_starts_with($root, '\\\\'))) {
            throw new IdempotencyException('Idempotency file root must be absolute.');
        }
        $this->directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $namespace);
    }

    public function claim(string $scope, string $fingerprint, string $owner, int $now,
        int $leaseSeconds, int $retentionSeconds): ClaimResult
    {
        $fresh = Record::fresh($fingerprint, $owner, $now, $leaseSeconds, $retentionSeconds);
        return $this->locked($scope, function (string $path) use ($fresh, $fingerprint, $now): ClaimResult {
            $old = $this->read($path);
            if ($old === null || $old['expires_at'] <= $now
                || ($old['state'] === 'processing' && $old['lease_until'] <= $now
                    && hash_equals($old['fingerprint'], $fingerprint))) {
                $this->write($path, $fresh);
                return new ClaimResult('claimed');
            }
            return ClaimResult::fromRecord($old, $fingerprint);
        });
    }

    public function complete(string $scope, string $owner, int $now, ?ResponseSnapshot $snapshot): bool
    {
        return $this->locked($scope, function (string $path) use ($owner, $now, $snapshot): bool {
            $old = $this->read($path);
            if ($old === null || $old['state'] !== 'processing' || $old['owner'] !== $owner
                || $old['lease_until'] <= $now || $old['expires_at'] <= $now) return false;
            $old['state'] = 'complete';
            $old['owner'] = null;
            $old['lease_until'] = 0;
            $old['snapshot'] = $snapshot?->toArray();
            $this->write($path, $old);
            return true;
        });
    }

    public function abandon(string $scope, string $owner): bool
    {
        return $this->locked($scope, function (string $path) use ($owner): bool {
            $old = $this->read($path);
            if ($old === null || $old['state'] !== 'processing' || $old['owner'] !== $owner) return false;
            if (!@unlink($path)) throw new IdempotencyException('Idempotency record cannot be removed.');
            return true;
        });
    }

    public function prune(int $now, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) throw new IdempotencyException('Invalid idempotency prune limit.');
        $this->ensureDirectory();
        $cursorPath = $this->directory . DIRECTORY_SEPARATOR . '.prune.cursor';
        $this->regular($cursorPath);
        $cursorFile = @fopen($cursorPath, 'c+b');
        if ($cursorFile === false) throw new IdempotencyException('Idempotency prune cursor cannot be opened.');
        try {
            @chmod($cursorPath, 0600);
            if (!@flock($cursorFile, LOCK_EX)) throw new IdempotencyException('Idempotency prune cursor lock failed.');
            try {
                $stored = @stream_get_contents($cursorFile, 80);
                if ($stored === false) throw new IdempotencyException('Idempotency prune cursor cannot be read.');
                // A torn cursor write only restarts cleanup; it cannot affect claims.
                $cursor = preg_match('/\A[a-f0-9]{64}\.json\z/D', $stored) ? $stored : '';
                $names = $this->nextPruneNames($cursor, $limit);
                $removed = 0;
                foreach ($names as $name) {
                    $removed += $this->locked(substr($name, 0, 64), function (string $path) use ($now): int {
                        $record = $this->read($path);
                        if ($record === null || $record['expires_at'] > $now) return 0;
                        if (!@unlink($path)) throw new IdempotencyException('Idempotency record cannot be removed.');
                        return 1;
                    });
                }
                if ($names !== [] || $stored !== $cursor) {
                    $this->writePruneCursor($cursorFile,
                        $names !== [] ? $names[count($names) - 1] : $cursor);
                }
                return $removed;
            } finally {
                @flock($cursorFile, LOCK_UN);
            }
        } finally {
            fclose($cursorFile);
        }
    }

    /** @return list<string> */
    private function nextPruneNames(string $cursor, int $limit): array
    {
        // Keep only the next $limit names on each side of the cursor. This
        // bounds record reads and memory while preserving lexical progress.
        $after = new SplMaxHeap();
        $before = new SplMaxHeap();
        foreach (new DirectoryIterator($this->directory) as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if (!preg_match('/\A[a-f0-9]{64}\.json\z/D', $name)) continue;
            $heap = strcmp($name, $cursor) > 0 ? $after : $before;
            $heap->insert($name);
            if ($heap->count() > $limit) $heap->extract();
        }
        $next = $this->ascending($after);
        if (count($next) < $limit) {
            $next = array_merge($next, array_slice($this->ascending($before), 0, $limit - count($next)));
        }
        return $next;
    }

    /** @return list<string> */
    private function ascending(SplMaxHeap $heap): array
    {
        $names = [];
        while (!$heap->isEmpty()) $names[] = (string) $heap->extract();
        return array_reverse($names);
    }

    /** @param resource $stream */
    private function writePruneCursor($stream, string $cursor): void
    {
        if (@fseek($stream, 0) !== 0 || !@ftruncate($stream, 0)) {
            throw new IdempotencyException('Idempotency prune cursor cannot be written.');
        }
        $offset = 0;
        while ($offset < strlen($cursor)) {
            $written = @fwrite($stream, substr($cursor, $offset));
            if ($written === false || $written === 0) {
                throw new IdempotencyException('Idempotency prune cursor cannot be written.');
            }
            $offset += $written;
        }
        if (!@fflush($stream)) throw new IdempotencyException('Idempotency prune cursor cannot be flushed.');
    }

    /**
     * @template T
     * @param callable(string):T $action
     * @return T
     */
    private function locked(string $scope, callable $action): mixed
    {
        Record::scope($scope);
        $this->ensureDirectory();
        // A fixed 256-lock stripe set bounds persistent sidecar files. Each
        // scope still serializes its complete read/decision/write sequence.
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . substr($scope, 0, 2) . '.lock';
        $this->regular($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new IdempotencyException('Idempotency lock file cannot be opened.');
        try {
            if (!@flock($lock, LOCK_EX)) throw new IdempotencyException('Idempotency file lock failed.');
            try {
                $this->ensureDirectory();
                return $action($this->directory . DIRECTORY_SEPARATOR . $scope . '.json');
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        for ($part = $this->root; $part !== dirname($part); $part = dirname($part)) {
            if (is_link($part)) throw new IdempotencyException('Idempotency file root contains a link.');
        }
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new IdempotencyException('Idempotency file root cannot be created.');
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root) || is_link($this->directory)) {
            throw new IdempotencyException('Idempotency file root is unavailable.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new IdempotencyException('Idempotency namespace cannot be created.');
        }
        $directory = realpath($this->directory);
        if ($directory === false || dirname($directory) !== $root) {
            throw new IdempotencyException('Idempotency namespace left its root.');
        }
    }

    private function regular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new IdempotencyException('Invalid idempotency file entry.');
        }
    }

    private function read(string $path): ?array
    {
        $this->regular($path);
        if (!file_exists($path)) return null;
        $contents = @file_get_contents($path);
        if ($contents === false) throw new IdempotencyException('Idempotency record cannot be read.');
        return Record::decode($contents);
    }

    private function write(string $path, array $record): void
    {
        $this->regular($path);
        $json = Record::encode($record);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) throw new IdempotencyException('Idempotency record cannot be written.');
        try {
            @chmod($temporary, 0600);
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = @fwrite($stream, substr($json, $offset));
                if ($written === false || $written === 0) throw new IdempotencyException('Idempotency record cannot be written.');
                $offset += $written;
            }
            if (!@fflush($stream)) throw new IdempotencyException('Idempotency record cannot be flushed.');
            fclose($stream);
            $stream = null;
            if (!@rename($temporary, $path)) throw new IdempotencyException('Idempotency record cannot be replaced.');
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (file_exists($temporary)) @unlink($temporary);
        }
    }
}
