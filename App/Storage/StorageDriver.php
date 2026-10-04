<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;

/** Byte and directory contract shared by local and in-memory drives. */
interface StorageDriver
{
    public function read(string $path): string;
    public function write(string $path, string $contents): void;
    public function exists(string $path): bool;
    public function remove(string $path): bool;
    public function copy(string $source, string $destination, bool $overwrite = false): void;
    public function move(string $source, string $destination, bool $overwrite = false): void;
    public function size(string $path): int;
    public function modifiedAt(string $path): DateTimeImmutable;
    public function mimeType(string $path): ?string;
    /** @return list<string> */
    public function files(string $directory = '', bool $recursive = false): array;
    /** @return list<string> */
    public function directories(string $directory = '', bool $recursive = false): array;
    public function makeDirectory(string $path): void;
    public function removeDirectory(string $path, bool $recursive = false): void;
    /** Caller owns and closes the returned readable stream. @return resource */
    public function readStream(string $path);
    /** Reads from the current cursor. Caller retains and closes the input stream. @param resource $stream */
    public function writeStream(string $path, $stream): void;
}
