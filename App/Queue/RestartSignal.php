<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Project-local restart generation shared by cooperating Queue workers.
 * A lock protects readers from a partially written token; no Queue payload
 * or application identity is stored in this operational marker.
 */
final class RestartSignal
{
    public function __construct(private string $directory)
    {
    }

    public function current(): string
    {
        if (!is_file($this->directory . '/Restart.token')) return '';
        return $this->withLock(false, function (): string {
            $path = $this->directory . '/Restart.token';
            $value = @file_get_contents($path);
            if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/D', $value) !== 1) {
                throw new QueueException('Queue restart marker is invalid.');
            }
            return $value;
        });
    }

    public function mark(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new QueueException('Queue restart directory is unavailable.');
        }
        $this->withLock(true, function (): void {
            $path = $this->directory . '/Restart.token';
            if (@file_put_contents($path, bin2hex(random_bytes(16)), LOCK_EX) !== 32) {
                throw new QueueException('Queue restart marker could not be written.');
            }
        });
    }

    private function withLock(bool $exclusive, callable $action): mixed
    {
        $lock = @fopen($this->directory . '/Restart.lock', 'c+b');
        if ($lock === false) throw new QueueException('Queue restart lock is unavailable.');
        try {
            if (!flock($lock, $exclusive ? LOCK_EX : LOCK_SH)) {
                throw new QueueException('Queue restart lock could not be acquired.');
            }
            return $action($lock);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
