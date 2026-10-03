<?php

declare(strict_types=1);

namespace App\Storage\Drivers;

use App\Storage\Providers\S3ClientFailure;
use App\Storage\Providers\S3ObjectClient;
use App\Storage\StorageDriver;
use App\Storage\StorageException;
use App\Storage\StoragePath;
use App\Storage\StorageStream;
use App\Storage\StreamWriteSize;
use DateTimeImmutable;
use Throwable;

/**
 * SqueHub logical files over S3 keys. Directory markers and parent prefixes
 * emulate directory behavior; remote writes and copy-then-delete moves are not
 * filesystem-atomic or locked across processes.
 */
final class S3StorageDriver implements StorageDriver, StreamWriteSize
{
    private string $prefix;
    private ?int $streamBytes = null;

    public function __construct(private S3ObjectClient $client, string $prefix = '')
    {
        if ($prefix === '') {
            $this->prefix = '';
            return;
        }
        $logical = rtrim($prefix, '/');
        if ($logical === '' || ($prefix !== $logical && $prefix !== $logical . '/')) {
            throw new StorageException('Invalid S3 Storage prefix.');
        }
        StoragePath::validate($logical);
        if (preg_match('//u', $logical) !== 1 || strlen($prefix) >= 1024) {
            throw new StorageException('Invalid S3 Storage prefix.');
        }
        $this->prefix = $logical . '/';
    }

    public function read(string $path): string
    {
        $key = $this->key($path);
        return $this->call('read', $path, fn (): string => $this->client->read($key));
    }

    public function write(string $path, string $contents): void
    {
        $key = $this->key($path);
        $this->assertWritableFile($path);
        $this->call('write', $path, fn () => $this->client->write($key, $contents));
    }

    public function exists(string $path): bool
    {
        $this->key($path);
        return $this->fileHead($path) !== null || $this->directoryExists($path);
    }

    public function remove(string $path): bool
    {
        $key = $this->key($path);
        if ($this->fileHead($path) === null) {
            if ($this->directoryExists($path)) throw StorageException::forPath('remove directory', $path);
            return false;
        }
        $this->call('remove', $path, fn () => $this->client->delete($key));
        return true;
    }

    public function copy(string $source, string $destination, bool $overwrite = false): void
    {
        $sourceKey = $this->key($source);
        $destinationKey = $this->key($destination);
        if ($source === $destination || $this->fileHead($source) === null) {
            throw StorageException::forPath('copy', $source);
        }
        $this->assertDestination($destination, $overwrite);
        $this->call('copy', $destination, fn () => $this->client->copy($sourceKey, $destinationKey));
    }

    public function move(string $source, string $destination, bool $overwrite = false): void
    {
        $sourceKey = $this->key($source);
        $this->copy($source, $destination, $overwrite);
        // A failed delete can leave two copies. This is deliberately not called
        // an atomic rename; callers can reconcile both paths after failure.
        $this->call('move source removal', $source, fn () => $this->client->delete($sourceKey));
    }

    public function size(string $path): int
    {
        return $this->requiredHead($path, 'size')['size'];
    }

    public function modifiedAt(string $path): DateTimeImmutable
    {
        return $this->requiredHead($path, 'modifiedAt')['modified'];
    }

    public function mimeType(string $path): ?string
    {
        return $this->requiredHead($path, 'mimeType')['mime'];
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->listing($directory, $recursive, false);
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        return $this->listing($directory, $recursive, true);
    }

    public function makeDirectory(string $path): void
    {
        $marker = $this->marker($path);
        $this->assertParentsAreDirectories($path);
        if ($this->fileHead($path) !== null) throw StorageException::forPath('makeDirectory', $path);
        if ($this->call('check', $path, fn (): ?array => $this->client->head($marker)) !== null) return;
        $this->call('makeDirectory', $path, fn () => $this->client->write($marker, ''));
    }

    public function removeDirectory(string $path, bool $recursive = false): void
    {
        $marker = $this->marker($path); // The drive root cannot be removed.
        $page = $this->page($marker, null, 2, $path);
        if ($page['keys'] === []) throw StorageException::forPath('removeDirectory', $path);
        if (!$recursive) {
            if ($page['keys'] !== [$marker] || $page['next'] !== null) {
                throw StorageException::forPath('removeDirectory', $path);
            }
            $this->call('removeDirectory', $path, fn () => $this->client->delete($marker));
            return;
        }
        // Re-read the first page after each batch so deletion does not rely on
        // continuation tokens remaining stable while objects disappear.
        for ($batch = 0; $batch < 10000; ++$batch) {
            $page = $this->page($marker, null, 1000, $path);
            if ($page['keys'] === []) return;
            $keys = array_values(array_filter($page['keys'], static fn (string $key): bool => str_starts_with($key, $marker)));
            if ($keys === []) throw StorageException::forPath('removeDirectory', $path);
            $this->call('removeDirectory', $path, fn () => $this->client->deleteMany($keys));
        }
        throw StorageException::forPath('removeDirectory incomplete', $path);
    }

    public function readStream(string $path)
    {
        $key = $this->key($path);
        $stream = $this->call('readStream', $path, fn () => $this->client->readStream($key));
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw StorageException::forPath('readStream', $path);
        }
        return $stream;
    }

    public function writeStream(string $path, $stream): void
    {
        $key = $this->key($path);
        StorageStream::readable($stream, $path);
        $this->streamBytes = null;
        $this->assertWritableFile($path);
        $bytes = $this->call('writeStream', $path, fn (): int => $this->client->writeStream($key, $stream));
        if (!is_int($bytes) || $bytes < 0) throw StorageException::forPath('writeStream', $path);
        $this->streamBytes = $bytes;
    }

    public function streamWriteSize(): ?int { return $this->streamBytes; }

    private function key(string $path): string
    {
        StoragePath::validate($path);
        if (preg_match('//u', $path) !== 1) throw StorageException::forPath('S3 key encoding', $path);
        $key = $this->prefix . $path;
        if (strlen($key) > 1024) throw StorageException::forPath('S3 key length', $path);
        return $key;
    }

    private function marker(string $path): string
    {
        $key = $this->key($path) . '/';
        if (strlen($key) > 1024) throw StorageException::forPath('S3 key length', $path);
        return $key;
    }

    /** @return array{size:int,modified:DateTimeImmutable,mime:?string,etag:?string}|null */
    private function fileHead(string $path): ?array
    {
        $key = $this->key($path);
        return $this->call('check', $path, fn (): ?array => $this->client->head($key));
    }

    /** @return array{size:int,modified:DateTimeImmutable,mime:?string,etag:?string} */
    private function requiredHead(string $path, string $operation): array
    {
        $head = $this->fileHead($path);
        if ($head === null) throw StorageException::forPath($operation, $path);
        return $head;
    }

    private function directoryExists(string $path): bool
    {
        $marker = $this->marker($path);
        return $this->page($marker, null, 1, $path)['keys'] !== [];
    }

    private function assertWritableFile(string $path): void
    {
        $this->assertParentsAreDirectories($path);
        if ($this->directoryExists($path)) throw StorageException::forPath('write directory', $path);
    }

    private function assertDestination(string $path, bool $overwrite): void
    {
        // HEAD is a compatibility preflight, not a conditional server write.
        // Another writer can create the key before the subsequent copy.
        $this->assertParentsAreDirectories($path);
        if ($this->directoryExists($path) || (!$overwrite && $this->fileHead($path) !== null)) {
            throw StorageException::forPath('copy destination exists', $path);
        }
    }

    private function assertParentsAreDirectories(string $path): void
    {
        $parts = explode('/', $path);
        array_pop($parts);
        $parent = '';
        foreach ($parts as $part) {
            $parent = $parent === '' ? $part : $parent . '/' . $part;
            if ($this->fileHead($parent) !== null) throw StorageException::forPath('create parent', $parent);
        }
    }

    /** @return list<string> */
    private function listing(string $directory, bool $recursive, bool $directories): array
    {
        StoragePath::validate($directory, true);
        if ($directory !== '' && !$this->directoryExists($directory)) {
            throw StorageException::forPath('list', $directory);
        }
        $keyPrefix = $directory === '' ? $this->prefix : $this->marker($directory);
        $result = [];
        $continuation = null;
        $seen = [];
        do {
            $page = $this->page($keyPrefix, $continuation, 1000, $directory);
            foreach ($page['keys'] as $key) {
                if (!str_starts_with($key, $this->prefix) || !str_starts_with($key, $keyPrefix)) continue;
                $logical = substr($key, strlen($this->prefix));
                if ($logical === '') continue;
                $marker = str_ends_with($logical, '/');
                $logical = $marker ? substr($logical, 0, -1) : $logical;
                if (preg_match('//u', $logical) !== 1) continue;
                try { StoragePath::validate($logical); }
                catch (StorageException) { continue; } // Foreign keys are never projected as SqueHub paths.
                if ($directories) {
                    $parts = explode('/', $logical);
                    if (!$marker) array_pop($parts);
                    $parent = '';
                    foreach ($parts as $part) {
                        $parent = $parent === '' ? $part : $parent . '/' . $part;
                        if ($parent === $directory || ($directory !== '' && !str_starts_with($parent, $directory . '/'))) continue;
                        $relative = $directory === '' ? $parent : substr($parent, strlen($directory) + 1);
                        if ($recursive || !str_contains($relative, '/')) $result[$parent] = true;
                    }
                } elseif (!$marker && $logical !== $directory) {
                    $relative = $directory === '' ? $logical : substr($logical, strlen($directory) + 1);
                    if ($recursive || !str_contains($relative, '/')) $result[$logical] = true;
                }
            }
            $continuation = $page['next'];
            if ($continuation !== null) {
                if (isset($seen[$continuation])) throw StorageException::forPath('list pagination', $directory);
                $seen[$continuation] = true;
            }
        } while ($continuation !== null);
        $paths = array_keys($result);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array{keys:list<string>,next:?string} */
    private function page(string $prefix, ?string $continuation, int $limit, string $logicalPath): array
    {
        $page = $this->call('list', $logicalPath, fn (): array => $this->client->list($prefix, $continuation, $limit));
        if (!is_array($page) || !isset($page['keys']) || !is_array($page['keys'])
            || !array_is_list($page['keys']) || !array_key_exists('next', $page)
            || ($page['next'] !== null && (!is_string($page['next']) || $page['next'] === ''))) {
            throw StorageException::forPath('list response', $logicalPath);
        }
        foreach ($page['keys'] as $key) {
            if (!is_string($key) || !str_starts_with($key, $prefix)) {
                throw StorageException::forPath('list response', $logicalPath);
            }
        }
        return $page;
    }

    private function call(string $operation, string $path, callable $callback): mixed
    {
        try { return $callback(); }
        catch (S3ClientFailure $error) {
            throw StorageException::forPath($operation . ' (' . $error->reason() . ')', $path);
        } catch (Throwable) {
            throw StorageException::forPath($operation . ' (provider_failure)', $path);
        }
    }
}
