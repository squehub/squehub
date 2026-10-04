<?php

declare(strict_types=1);

namespace App\Storage\Drivers;

use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Storage\StorageDriver;
use App\Storage\StorageException;
use App\Storage\StoragePath;
use App\Storage\StorageStream;
use DateTimeImmutable;
use DateTimeZone;

/** One drive's non-persistent virtual filesystem; no state is process-global. */
final class ArrayStorageDriver implements StorageDriver
{
    /** @var array<string, array{content:string,modified:DateTimeImmutable}> */
    private array $files = [];
    /** @var array<string, true> */
    private array $directories = ['' => true];

    public function __construct(private ?ModelClock $clock = null)
    {
        $this->clock ??= new SystemModelClock();
    }

    public function read(string $path): string
    {
        $path = StoragePath::validate($path);
        if (!isset($this->files[$path])) throw StorageException::forPath('read', $path);
        return $this->files[$path]['content'];
    }

    public function write(string $path, string $contents): void
    {
        $path = StoragePath::validate($path);
        if (isset($this->directories[$path])) throw StorageException::forPath('write', $path);
        $this->parents($path);
        $this->files[$path] = ['content' => $contents, 'modified' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))];
    }

    public function exists(string $path): bool
    {
        $path = StoragePath::validate($path);
        return isset($this->files[$path]) || isset($this->directories[$path]);
    }

    public function remove(string $path): bool
    {
        $path = StoragePath::validate($path);
        if (isset($this->directories[$path])) throw StorageException::forPath('remove directory', $path);
        if (!isset($this->files[$path])) return false;
        unset($this->files[$path]);
        return true;
    }

    public function copy(string $source, string $destination, bool $overwrite = false): void
    {
        $source = StoragePath::validate($source);
        $destination = StoragePath::validate($destination);
        if (!isset($this->files[$source]) || (!$overwrite && $this->exists($destination))
            || isset($this->directories[$destination]) || $source === $destination) {
            throw StorageException::forPath('copy', $destination);
        }
        $this->write($destination, $this->files[$source]['content']);
    }

    public function move(string $source, string $destination, bool $overwrite = false): void
    {
        $this->copy($source, $destination, $overwrite);
        unset($this->files[$source]);
    }

    public function size(string $path): int
    {
        return strlen($this->read($path));
    }

    public function modifiedAt(string $path): DateTimeImmutable
    {
        $path = StoragePath::validate($path);
        if (!isset($this->files[$path])) throw StorageException::forPath('modifiedAt', $path);
        return $this->files[$path]['modified'];
    }

    public function mimeType(string $path): ?string
    {
        $bytes = $this->read($path);
        if (!class_exists(\finfo::class)) return null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        return is_string($mime) ? $mime : null;
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        return $this->listing($directory, $recursive, array_keys($this->files));
    }

    public function directories(string $directory = '', bool $recursive = false): array
    {
        return $this->listing($directory, $recursive, array_keys($this->directories));
    }

    public function makeDirectory(string $path): void
    {
        $path = StoragePath::validate($path);
        if (isset($this->files[$path])) throw StorageException::forPath('makeDirectory', $path);
        $this->parents($path);
        $this->directories[$path] = true;
    }

    public function removeDirectory(string $path, bool $recursive = false): void
    {
        $path = StoragePath::validate($path);
        if (!isset($this->directories[$path])) throw StorageException::forPath('removeDirectory', $path);
        $prefix = $path . '/';
        $files = array_filter(array_keys($this->files), static fn (string $name): bool => str_starts_with($name, $prefix));
        $directories = array_filter(array_keys($this->directories), static fn (string $name): bool => str_starts_with($name, $prefix));
        if (!$recursive && ($files !== [] || $directories !== [])) throw StorageException::forPath('removeDirectory', $path);
        foreach ($files as $name) unset($this->files[$name]);
        foreach ($directories as $name) unset($this->directories[$name]);
        unset($this->directories[$path]);
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw StorageException::forPath('readStream', $path);
        try {
            $bytes = $this->read($path);
            if (fwrite($stream, $bytes) !== strlen($bytes)) throw StorageException::forPath('readStream', $path);
            rewind($stream);
            return $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    public function writeStream(string $path, $stream): void
    {
        StorageStream::readable($stream, $path);
        $bytes = stream_get_contents($stream);
        if ($bytes === false) throw StorageException::forPath('writeStream', $path);
        $this->write($path, $bytes);
    }

    private function parents(string $path): void
    {
        $parts = explode('/', $path);
        array_pop($parts);
        $current = '';
        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            if (isset($this->files[$current])) throw StorageException::forPath('create parent', $current);
            $this->directories[$current] = true;
        }
    }

    /** @param list<string> $names @return list<string> */
    private function listing(string $directory, bool $recursive, array $names): array
    {
        $directory = StoragePath::validate($directory, true);
        if (!isset($this->directories[$directory])) throw StorageException::forPath('list', $directory);
        $prefix = $directory === '' ? '' : $directory . '/';
        $result = [];
        foreach ($names as $name) {
            if ($name === $directory || !str_starts_with($name, $prefix)) continue;
            $relative = substr($name, strlen($prefix));
            if ($relative !== '' && ($recursive || !str_contains($relative, '/'))) $result[] = $name;
        }
        sort($result, SORT_STRING);
        return $result;
    }
}
