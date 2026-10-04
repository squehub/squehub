<?php

declare(strict_types=1);

namespace App\Storage;

use App\Diagnostics\Diagnostics;
use DateTimeImmutable;
use Throwable;

/** One configured drive; records aggregate attempts without retaining paths. */
final class StorageDrive implements StorageDriver
{
    public function __construct(private StorageDriver $driver, private ?Diagnostics $diagnostics = null)
    {
    }

    public function read(string $path): string { return $this->run('read', fn () => $this->driver->read($path), 'read'); }
    public function write(string $path, string $contents): void { $this->run('write', fn () => $this->driver->write($path, $contents), 'write', strlen($contents)); }
    public function exists(string $path): bool { return $this->run('check', fn () => $this->driver->exists($path)); }
    public function remove(string $path): bool { return $this->run('remove', fn () => $this->driver->remove($path)); }
    public function copy(string $source, string $destination, bool $overwrite = false): void { $this->run('copy', fn () => $this->driver->copy($source, $destination, $overwrite)); }
    public function move(string $source, string $destination, bool $overwrite = false): void { $this->run('move', fn () => $this->driver->move($source, $destination, $overwrite)); }
    public function size(string $path): int { return $this->run('check', fn () => $this->driver->size($path)); }
    public function modifiedAt(string $path): DateTimeImmutable { return $this->run('check', fn () => $this->driver->modifiedAt($path)); }
    public function mimeType(string $path): ?string { return $this->run('check', fn () => $this->driver->mimeType($path)); }
    public function files(string $directory = '', bool $recursive = false): array { return $this->run('list', fn () => $this->driver->files($directory, $recursive)); }
    public function directories(string $directory = '', bool $recursive = false): array { return $this->run('list', fn () => $this->driver->directories($directory, $recursive)); }
    public function makeDirectory(string $path): void { $this->run('write', fn () => $this->driver->makeDirectory($path)); }
    public function removeDirectory(string $path, bool $recursive = false): void { $this->run('remove', fn () => $this->driver->removeDirectory($path, $recursive)); }
    /** The caller closes the returned stream; subsequent reads are outside the lock and byte count. @return resource */
    public function readStream(string $path) { return $this->run('read', fn () => $this->driver->readStream($path)); }
    /** Input remains caller-owned and starts at its current cursor. @param resource $stream */
    public function writeStream(string $path, $stream): void
    {
        $this->run('write', fn () => $this->driver->writeStream($path, $stream), 'stream', $path);
    }

    private function run(string $operation, callable $callback, ?string $byteMode = null, mixed $byteSource = null): mixed
    {
        $started = hrtime(true);
        $failed = false;
        $result = null;
        $bytes = 0;
        try {
            $result = $callback();
            if ($byteMode === 'read' && is_string($result)) $bytes = strlen($result);
            elseif ($byteMode === 'write' && is_int($byteSource)) $bytes = $byteSource;
            elseif ($byteMode === 'stream' && is_string($byteSource)) {
                $bytes = $this->driver instanceof StreamWriteSize
                    ? ($this->driver->streamWriteSize() ?? 0)
                    : $this->driver->size($byteSource);
            }
            return $result;
        } catch (Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            try {
                $this->diagnostics?->storage($operation, (hrtime(true) - $started) / 1_000_000, $failed, $failed ? 0 : $bytes);
            } catch (Throwable) {
                // Observability cannot change a Storage result or exception.
            }
        }
    }
}
