<?php

declare(strict_types=1);

namespace App\Validation;

use LogicException;

/** A PHP upload entry. The client filename and MIME type are never trusted for validation. */
final class UploadedFile
{
    private function __construct(private array $entry)
    {
    }

    public static function fromPhpEntry(array $entry): self
    {
        return new self($entry);
    }

    public function error(): int
    {
        return is_int($this->entry['error'] ?? null) ? $this->entry['error'] : UPLOAD_ERR_NO_FILE;
    }

    public function isValid(): bool
    {
        $path = $this->path();
        return $this->error() === UPLOAD_ERR_OK
            && is_string($this->entry['name'] ?? null)
            && is_int($this->entry['size'] ?? null)
            && $this->entry['size'] >= 0
            && $path !== null && is_file($path) && is_readable($path);
    }

    public function isAbsent(): bool
    {
        return $this->error() === UPLOAD_ERR_NO_FILE;
    }

    public function size(): int
    {
        return (int) ($this->entry['size'] ?? 0);
    }

    public function path(): ?string
    {
        $path = $this->entry['tmp_name'] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    public function mimeType(): ?string
    {
        if (!class_exists(\finfo::class)) {
            throw new LogicException('The fileinfo extension is required for MIME validation.');
        }
        if (!$this->isValid()) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->path());
        return is_string($mime) ? $mime : null;
    }

    public function isImage(): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        $image = @getimagesize($this->path());
        return is_array($image) && in_array($image[2] ?? null, [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }
}
