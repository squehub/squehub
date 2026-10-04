<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/** Resolves first-letter casing variants after Composer has tried PSR-4. */
final class CaseFallbackAutoloader
{
    private const ROOTS = ['App' => true, 'Project' => true, 'Database' => true];
    private const MAX_DIRECTORIES = 64;

    private string $basePath;

    public function __construct(string $basePath)
    {
        $resolved = realpath($basePath);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Autoload base path must be an existing directory.');
        }

        $this->basePath = $resolved;
    }

    public function register(): void
    {
        // Composer registers its PSR-4 loader before processing autoload.files.
        spl_autoload_register([$this, 'load'], true, false);
    }

    public function load(string $class): void
    {
        if (strlen($class) > 512) {
            return;
        }

        $segments = explode('\\', $class);
        if (count($segments) < 2 || count($segments) > 16) {
            return;
        }

        foreach ($segments as $segment) {
            // Reject separators, dot segments, and other path syntax before joining.
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $segment) !== 1) {
                return;
            }
        }

        $root = ucfirst($segments[0]);
        if ($root === 'Packages') {
            // The public Packages\ alias points at Project/Packages.
            $segments = ['Project', $segments[0], ...array_slice($segments, 1)];
        } elseif (!isset(self::ROOTS[$root])) {
            return;
        }

        /** @var list<array{path: string, root: string}> $directories */
        $directories = [['path' => $this->basePath, 'root' => '']];
        foreach (array_slice($segments, 0, -1) as $index => $segment) {
            $next = [];
            $seen = [];
            foreach ($directories as $directory) {
                foreach ($this->variants($segment) as $variant) {
                    $path = realpath($directory['path'] . DIRECTORY_SEPARATOR . $variant);
                    if ($path === false || !is_dir($path) || isset($seen[$path])
                        || dirname($path) !== $directory['path']) {
                        continue;
                    }

                    // A symlink may not take a class outside its source root.
                    $boundary = $index === 0 ? $this->basePath : $directory['root'];
                    if (!str_starts_with($path, $boundary . DIRECTORY_SEPARATOR)) {
                        continue;
                    }

                    $seen[$path] = true;
                    $next[] = ['path' => $path, 'root' => $index === 0 ? $path : $directory['root']];
                    if (count($next) >= self::MAX_DIRECTORIES) {
                        break 2;
                    }
                }
            }

            if ($next === []) {
                return;
            }
            $directories = $next;
        }

        foreach ($directories as $directory) {
            foreach ($this->variants($segments[array_key_last($segments)]) as $variant) {
                $file = realpath($directory['path'] . DIRECTORY_SEPARATOR . $variant . '.php');
                if ($file === false || !is_file($file) || dirname($file) !== $directory['path']
                    || !str_starts_with($file, $directory['root'] . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                require_once $file;
                return;
            }
        }
    }

    /** @return list<string> */
    private function variants(string $segment): array
    {
        return array_values(array_unique([$segment, ucfirst($segment), lcfirst($segment)]));
    }
}

(new CaseFallbackAutoloader(dirname(__DIR__, 2)))->register();
