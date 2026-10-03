<?php

declare(strict_types=1);

namespace App\Bundles;

use App\Packages\PackageException;
use App\Packages\PackageFiles;
use Normalizer;

/**
 * Portable bundle names and physical target paths share one validation policy.
 * The archive never supplies a filesystem path until every segment is checked.
 */
final class BundlePath
{
    /** Resolve a caller path without allowing a link or lexical parent escape. */
    public static function existingInput(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new BundleException('Bundle filesystem path is unsafe.');
        }
        if (!str_starts_with($path, '/')
            && preg_match('/\A[A-Za-z]:\//D', $path) !== 1) {
            $cwd = getcwd();
            if ($cwd === false) { throw new BundleException('Working directory is unavailable.'); }
            $path = rtrim(str_replace('\\', '/', $cwd), '/') . '/' . $path;
        }
        if (preg_match('/\A[A-Za-z]:\//D', $path) === 1) {
            $root = substr($path, 0, 3);
            $tail = substr($path, 3);
        } elseif (str_starts_with($path, '/')) {
            $root = '/';
            $tail = substr($path, 1);
        } else {
            throw new BundleException('Bundle filesystem path is unsafe.');
        }
        $segments = [];
        foreach (explode('/', $tail) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') {
                if ($segments === []) {
                    throw new BundleException('Bundle filesystem path escapes its volume.');
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $part;
            $candidate = $root . implode('/', $segments);
            if (file_exists($candidate) || is_link($candidate)) {
                // Inspect before a later ".." can erase the lexical segment.
                self::requirePhysical($candidate);
            }
        }
        $canonical = $root . implode('/', $segments);
        self::requirePhysical($canonical);
        $resolved = realpath($canonical);
        if ($resolved === false) { throw new BundleException('Bundle filesystem path cannot be resolved.'); }
        $resolved = rtrim(str_replace('\\', '/', $resolved), '/');
        if ($resolved === '') { return '/'; }
        return preg_match('/\A[A-Za-z]:\z/D', $resolved) === 1
            ? $resolved . '/' : $resolved;
    }

    /** Source trees cannot smuggle runtime state or common secret containers. */
    public static function excluded(string $path): bool
    {
        $parts = explode('/', strtolower($path));
        $name = end($parts);
        if (in_array($name, ['.env', 'activation.lock', 'state.json', 'credentials.json',
            'secrets.json', 'id_rsa', 'id_ed25519'], true)
            || str_starts_with($name, '.env.')
            || preg_match('/\.(?:pem|p12|pfx|key|secret)\z/D', $name) === 1) {
            return true;
        }
        foreach ($parts as $part) {
            if (in_array($part, ['.git', '.svn', 'vendor', 'node_modules', 'storage',
                'uploads', 'backups', 'sessions', 'logs', 'cache', '.phpunit.cache'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function requireRelative(string $path): void
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F:<>"|?*]/', $path) === 1
            || preg_match('//u', $path) !== 1) {
            throw new BundleException('Bundle contains an unsafe path.');
        }
        if (class_exists(Normalizer::class)) {
            if (Normalizer::normalize($path, Normalizer::FORM_C) !== $path) {
                throw new BundleException('Bundle contains a noncanonical Unicode path.');
            }
        } elseif (preg_match('/[^\x00-\x7F]/', $path) === 1) {
            // Without NFC support, declining a Unicode name is safer than
            // claiming a portable round trip we cannot verify.
            throw new BundleException('Unicode bundle paths require the intl extension.');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255
                || str_ends_with($part, '.') || str_ends_with($part, ' ')
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $part) === 1) {
                throw new BundleException('Bundle contains a nonportable path.');
            }
        }
    }

    /** Unicode folding is used only for collision detection, never renaming. */
    public static function collisionKey(string $path): string
    {
        self::requireRelative($path);
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($path, MB_CASE_FOLD, 'UTF-8');
        }
        if (preg_match('/[^\x00-\x7F]/', $path) === 1) {
            throw new BundleException('Unicode bundle paths require the mbstring extension.');
        }
        return strtolower($path);
    }

    /** Existing source/target entries must not be symlinks, junctions, or reparses. */
    public static function requirePhysical(string $path): void
    {
        try {
            PackageFiles::assertPhysical($path);
        } catch (PackageException $exception) {
            throw new BundleException('Bundle source or target contains an unsafe link.', 0, $exception);
        }
    }

    /**
     * Inspect each existing target ancestor before an archive path is joined to
     * the application root. This also catches case aliases on Windows.
     */
    public static function target(string $root, string $relative): string
    {
        self::requireRelative($relative);
        if (is_dir($root)) { self::requirePhysical($root); }
        $current = $root;
        $parts = explode('/', $relative);
        foreach ($parts as $index => $part) {
            if (is_dir($current)) {
                $entries = @scandir($current);
                if ($entries === false) {
                    throw new BundleException('Bundle target cannot be inspected.');
                }
                $folded = self::collisionKey($part);
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') { continue; }
                    if (self::collisionKey($entry) === $folded && $entry !== $part) {
                        throw new BundleException('Bundle target conflicts by filename casing.');
                    }
                }
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new BundleException('Bundle target contains an unsafe link.');
            }
            if (file_exists($current)) {
                self::requirePhysical($current);
                if ($index < count($parts) - 1 && !is_dir($current)) {
                    throw new BundleException('Bundle target parent is not a directory.');
                }
            }
        }
        return $current;
    }
}
