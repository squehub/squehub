<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/** Storage failures omit physical paths and file contents from their messages. */
final class StorageException extends RuntimeException
{
    public static function forPath(string $operation, string $path, ?\Throwable $previous = null): self
    {
        return new self('Storage ' . $operation . ' failed for path [' . substr(hash('sha256', $path), 0, 12) . '].', 0, $previous);
    }
}
