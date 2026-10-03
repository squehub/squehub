<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Foundation\Application;
use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Packages\PackageManager;
use App\Packages\PackageName;

/**
 * Resolves a public Package asset from the frozen enabled Package set.
 *
 * Package source remains under Project/Packages, outside the document root.
 * Only an exact active Package name and a physical file beneath its Assets
 * directory can be exposed. No source file is copied into public/, so a
 * disabled Package has no framework-published static path left behind.
 */
final class PackageAssetSource
{
    private const EXTENSIONS = [
        'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
        'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    public function __construct(private Application $application)
    {
    }

    /** Return a contained physical file, or null without revealing source paths. */
    public function resolve(string $name, string $relative): ?string
    {
        if (!PackageName::valid($name) || !self::validRelative($relative)) {
            return null;
        }
        $descriptor = null;
        foreach ($this->application->container()->make(PackageManager::class)->active() as $candidate) {
            if ($candidate->name() === $name) {
                $descriptor = $candidate;
                break;
            }
        }
        if ($descriptor === null) {
            return null;
        }

        $root = $descriptor->path() . '/Assets';
        try {
            PackageFiles::assertPhysical($root);
            if (!is_dir($root)) {
                return null;
            }
            $current = $root;
            foreach (explode('/', $relative) as $part) {
                // A Windows alias must not become a different Linux asset after
                // deployment. Check exact spelling before resolving the child.
                $entries = @scandir($current);
                if ($entries === false || !in_array($part, $entries, true)) {
                    return null;
                }
                $current .= '/' . $part;
                PackageFiles::assertPhysical($current);
            }
            if (!is_file($current) || !is_readable($current)) {
                return null;
            }
            return $current;
        } catch (PackageException) {
            return null;
        }
    }

    public static function validRelative(string $relative): bool
    {
        if ($relative === '' || strlen($relative) > 1024
            || preg_match('/[\x00-\x1F\x7F\\\\%:?#]/', $relative) === 1) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/D', $segment) !== 1
                || str_ends_with($segment, '.')) {
                return false;
            }
        }
        return in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    public static function contentType(string $relative): ?string
    {
        return match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'text/javascript; charset=UTF-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject',
            default => null,
        };
    }
}
