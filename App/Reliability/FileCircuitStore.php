<?php

declare(strict_types=1);

namespace App\Reliability;

use JsonException;
use Throwable;

/**
 * Single-server circuit state. A per-key flock covers the full state
 * transition; replacement never exposes a partly written JSON record.
 * Network filesystems and multiple servers are outside this contract.
 *
 * @internal Application code should resolve CircuitBreaker instead.
 */
final class FileCircuitStore
{
    private string $directory;

    public function __construct(private string $root, string $namespace)
    {
        $drive = strlen($root) >= 3 && ctype_alpha($root[0]) && $root[1] === ':'
            && in_array($root[2], ['/', '\\'], true);
        if ($root === '' || !(str_starts_with($root, '/') || $drive || str_starts_with($root, '\\\\'))) {
            throw new CircuitException('Circuit state root must be absolute.');
        }
        if ($namespace === '' || strlen($namespace) > 512 || preg_match('/[\x00-\x1F\x7F]/', $namespace)) {
            throw new CircuitException('Circuit state namespace is invalid.');
        }
        $this->directory = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $namespace);
    }

    /**
     * @template T
     * @param callable(?array):array{state:?array,result:T} $operation
     * @return T
     */
    public function mutate(string $fingerprint, callable $operation): mixed
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new CircuitException('Circuit state fingerprint is invalid.');
        }
        $this->ensureDirectory();
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . $fingerprint . '.lock';
        $recordPath = $this->directory . DIRECTORY_SEPARATOR . $fingerprint . '.json';
        $this->regular($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new CircuitException('Circuit state lock is unavailable.');
        try {
            if (!@flock($lock, LOCK_EX)) throw new CircuitException('Circuit state lock failed.');
            try {
                $this->ensureDirectory();
                $before = $this->read($recordPath);
                $decision = self::decision($operation($before));
                $after = $decision['state'];
                if ($after !== $before) {
                    if ($after !== null) self::validRecord($after);
                    $this->write($recordPath, $after);
                }
                return $decision['result'];
            } finally {
                @flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /** @return array{state:?array,result:mixed} */
    private static function decision(mixed $decision): array
    {
        if (!is_array($decision) || !array_key_exists('state', $decision)
            || !array_key_exists('result', $decision)
            || ($decision['state'] !== null && !is_array($decision['state']))) {
            throw new CircuitException('Circuit state transition is invalid.');
        }
        return $decision;
    }

    /** Operator recovery may remove a corrupt record without decoding it first. */
    public function clear(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new CircuitException('Circuit state fingerprint is invalid.');
        }
        $this->ensureDirectory();
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . $fingerprint . '.lock';
        $recordPath = $this->directory . DIRECTORY_SEPARATOR . $fingerprint . '.json';
        $this->regular($lockPath);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) throw new CircuitException('Circuit state lock is unavailable.');
        try {
            if (!@flock($lock, LOCK_EX)) throw new CircuitException('Circuit state lock failed.');
            try {
                $this->ensureDirectory();
                $this->write($recordPath, null);
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
            if (is_link($part)) {
                throw new CircuitException('Circuit state root contains a link.');
            }
        }
        if (is_link($this->root) || (!is_dir($this->root)
            && !@mkdir($this->root, 0700, true) && !is_dir($this->root))) {
            throw new CircuitException('Circuit state root cannot be created.');
        }
        $root = realpath($this->root);
        if ($root === false || !is_dir($root) || is_link($this->directory)) {
            throw new CircuitException('Circuit state root is unavailable.');
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700)
            && !is_dir($this->directory)) {
            throw new CircuitException('Circuit state namespace cannot be created.');
        }
        $directory = realpath($this->directory);
        if ($directory === false || dirname($directory) !== $root) {
            throw new CircuitException('Circuit state namespace is outside its root.');
        }
    }

    private function regular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new CircuitException('Circuit state entry path is invalid.');
        }
    }

    /** @return array<string,mixed>|null */
    private function read(string $path): ?array
    {
        $this->regular($path);
        if (!file_exists($path)) return null;
        $contents = @file_get_contents($path);
        if ($contents === false || strlen($contents) > 512) {
            throw new CircuitException('Circuit state record cannot be read.');
        }
        try {
            $record = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CircuitException('Circuit state record is corrupt.');
        }
        if (!is_array($record)) throw new CircuitException('Circuit state record is corrupt.');
        self::validRecord($record);
        return $record;
    }

    /** @param array<string,mixed> $record */
    private static function validRecord(array $record): void
    {
        if (array_keys($record) !== ['version', 'mode', 'epoch', 'failures',
                'reopen_at', 'probe_token', 'probe_until']
            || $record['version'] !== 1
            || !in_array($record['mode'], ['closed', 'open', 'half_open'], true)
            || !is_string($record['epoch'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $record['epoch']) !== 1
            || !is_int($record['failures']) || $record['failures'] < 0
            || $record['failures'] > 1000
            || !is_int($record['reopen_at']) || $record['reopen_at'] < 0
            || !is_int($record['probe_until']) || $record['probe_until'] < 0
            || ($record['probe_token'] !== null
                && (!is_string($record['probe_token'])
                    || preg_match('/\A[a-f0-9]{32}\z/D', $record['probe_token']) !== 1))
            || ($record['mode'] === 'closed'
                && ($record['reopen_at'] !== 0 || $record['probe_token'] !== null
                    || $record['probe_until'] !== 0))
            || ($record['mode'] === 'open'
                && ($record['reopen_at'] < 1 || $record['probe_token'] !== null
                    || $record['probe_until'] !== 0))
            || ($record['mode'] === 'half_open'
                && ($record['probe_token'] === null || $record['probe_until'] < 1))) {
            throw new CircuitException('Circuit state record is corrupt.');
        }
    }

    /** @param array<string,mixed>|null $record */
    private function write(string $path, ?array $record): void
    {
        $this->regular($path);
        if ($record === null) {
            if (file_exists($path) && !@unlink($path)) {
                throw new CircuitException('Circuit state record cannot be removed.');
            }
            return;
        }
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CircuitException('Circuit state record cannot be encoded.');
        }
        if (strlen($json) > 512) throw new CircuitException('Circuit state record is too large.');
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) throw new CircuitException('Circuit state record cannot be written.');
        try {
            @chmod($temporary, 0600);
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = @fwrite($stream, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new CircuitException('Circuit state record cannot be written.');
                }
                $offset += $written;
            }
            if (!@fflush($stream)) throw new CircuitException('Circuit state record cannot be flushed.');
        } catch (Throwable $failure) {
            fclose($stream);
            @unlink($temporary);
            throw $failure;
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new CircuitException('Circuit state record cannot be replaced.');
        }
    }
}
