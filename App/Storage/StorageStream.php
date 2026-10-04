<?php

declare(strict_types=1);

namespace App\Storage;

/** Validates caller-owned readable streams shared by both drive implementations. */
final class StorageStream
{
    /** @param mixed $stream */
    public static function readable($stream, string $path): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw StorageException::forPath('writeStream', $path);
        }
        $mode = stream_get_meta_data($stream)['mode'] ?? '';
        if (!str_contains($mode, '+') && !str_starts_with($mode, 'r')) {
            throw StorageException::forPath('writeStream', $path);
        }
    }
}
