<?php

declare(strict_types=1);

namespace App\Storage;

/** Validates portable logical paths before either driver sees a filesystem name. */
final class StoragePath
{
    public static function validate(string $path, bool $allowRoot = false): string
    {
        if ($path === '' && $allowRoot) return '';
        if ($path === '' || strlen($path) > 4096 || str_starts_with($path, '/')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $path)) {
            throw StorageException::forPath('path validation', $path);
        }
        foreach (explode('/', $path) as $segment) {
            // Windows device aliases still resolve after an extension; trailing
            // spaces and dots are also normalized ambiguously by Windows.
            $base = strtoupper(rtrim(explode('.', $segment, 2)[0], ' .'));
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255
                || preg_match('/[ .]$/', $segment)
                || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $base)
                || strcasecmp($segment, '.squehub') === 0
                || str_starts_with(strtolower($segment), '.squehub-tmp-')) {
                throw StorageException::forPath('path validation', $path);
            }
        }
        return $path;
    }
}
