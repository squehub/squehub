<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use DirectoryIterator;

final class TemporaryProject
{
    private string $path;

    public function __construct()
    {
        $this->path = BASE_DIR . '/app-' . bin2hex(random_bytes(6));
        mkdir($this->path . '/config', 0777, true);
    }

    public function path(string $suffix = ''): string
    {
        return $suffix === '' ? $this->path : $this->path . '/' . ltrim($suffix, '/');
    }

    public function write(string $suffix, string $contents): void
    {
        $file = $this->path($suffix);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    public function remove(): void
    {
        $delete = static function (string $path) use (&$delete): void {
            // Check links first: directory links must not be traversed, and a link
            // remains present even when its target was removed earlier in the walk.
            if (is_link($path)) {
                if (!@unlink($path)) {
                    rmdir($path); // Windows may require rmdir() for directory links.
                }
                return;
            }

            if (is_dir($path)) {
                foreach (new DirectoryIterator($path) as $entry) {
                    if (!$entry->isDot()) {
                        $delete($entry->getPathname());
                    }
                }
                rmdir($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        };
        $delete($this->path);
    }
}
