<?php

declare(strict_types=1);

namespace App\Storage;

use DateTimeImmutable;

/** Shared public API: the manager delegates to its default drive. */
trait StorageOperations
{
    abstract protected function backend(): StorageDriver;

    public function read(string $path): string { return $this->backend()->read($path); }
    public function write(string $path, string $contents): void { $this->backend()->write($path, $contents); }
    public function exists(string $path): bool { return $this->backend()->exists($path); }
    public function remove(string $path): bool { return $this->backend()->remove($path); }
    public function copy(string $source, string $destination, bool $overwrite = false): void { $this->backend()->copy($source, $destination, $overwrite); }
    public function move(string $source, string $destination, bool $overwrite = false): void { $this->backend()->move($source, $destination, $overwrite); }
    public function size(string $path): int { return $this->backend()->size($path); }
    public function modifiedAt(string $path): DateTimeImmutable { return $this->backend()->modifiedAt($path); }
    public function mimeType(string $path): ?string { return $this->backend()->mimeType($path); }
    /** @return list<string> */
    public function files(string $directory = '', bool $recursive = false): array { return $this->backend()->files($directory, $recursive); }
    /** @return list<string> */
    public function directories(string $directory = '', bool $recursive = false): array { return $this->backend()->directories($directory, $recursive); }
    public function makeDirectory(string $path): void { $this->backend()->makeDirectory($path); }
    public function removeDirectory(string $path, bool $recursive = false): void { $this->backend()->removeDirectory($path, $recursive); }
    /** Caller closes the returned stream. @return resource */
    public function readStream(string $path) { return $this->backend()->readStream($path); }
    /** Caller owns and closes the input stream; reading starts at its current cursor. @param resource $stream */
    public function writeStream(string $path, $stream): void { $this->backend()->writeStream($path, $stream); }
}
