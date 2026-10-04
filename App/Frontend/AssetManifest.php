<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Packages\PackageException;
use App\Packages\PackageFiles;
use Normalizer;

/**
 * Strict, read-only Vite manifest. Every advertised output is checked against
 * the current public build tree, so an old manifest cannot silently select a
 * file removed by a later deployment. No source PHP or build tool executes.
 */
final class AssetManifest
{
    private const MAX_BYTES = 2097152;
    private const MAX_ENTRIES = 10000;

    private readonly string $root;
    private readonly string $directory;
    private readonly string $manifest;

    /** @param array<string, array<string,mixed>>|null $entries */
    private ?array $entries = null;
    private ?string $fingerprint = null;

    public function __construct(string $applicationRoot, string $directory,
        string $manifest = '.vite/manifest.json')
    {
        $root = realpath($applicationRoot);
        if ($root === false || !is_dir($root)) {
            throw new FrontendException('Frontend application root is unavailable.');
        }
        self::relative($directory, false);
        if (!str_starts_with($directory, 'public/assets/')
            || $directory === 'public/assets/') {
            throw new FrontendException('Frontend build directory must be under public/assets.');
        }
        self::relative($manifest, true);
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->directory = $directory;
        $this->manifest = $manifest;
    }

    /** Return a validated manifest entry, or null for an unmapped name. */
    public function find(string $source): ?array
    {
        self::relative($source, false);
        $this->reload();
        return $this->entries[$source] ?? null;
    }

    /** A named frontend entry must be explicitly present in the built output. */
    public function requireEntry(string $source): array
    {
        $entry = $this->find($source);
        if ($entry === null || ($entry['isEntry'] ?? false) !== true) {
            throw new FrontendException('Frontend build entry is missing.');
        }
        return $entry;
    }

    /**
     * Return CSS and static imports in deterministic dependency-first order.
     * Dynamic imports are validated but are loaded by the generated module.
     *
     * @return array{file:string, css:list<string>, imports:list<string>}
     */
    public function resources(string $source): array
    {
        $entry = $this->requireEntry($source);
        $css = [];
        $imports = [];
        $visiting = [];
        $visited = [];
        $walk = function (string $key) use (&$walk, &$css, &$imports, &$visiting,
            &$visited): void {
            if (isset($visited[$key])) { return; }
            if (isset($visiting[$key])) {
                throw new FrontendException('Frontend manifest import cycle is invalid.');
            }
            $visiting[$key] = true;
            $part = $this->entries[$key]
                ?? throw new FrontendException('Frontend manifest import is missing.');
            foreach ($part['imports'] ?? [] as $dependency) {
                $walk($dependency);
                $imports[$this->publicUrl($this->entries[$dependency]['file'])] = true;
            }
            foreach ($part['css'] ?? [] as $file) {
                $css[$this->publicUrl($file)] = true;
            }
            unset($visiting[$key]);
            $visited[$key] = true;
        };
        $walk($source);
        return ['file' => $this->publicUrl($entry['file']),
            'css' => array_keys($css), 'imports' => array_keys($imports)];
    }

    /** Validate a manifest output and return an application-root public URL. */
    public function publicUrl(string $file): string
    {
        self::relative($file, false);
        $this->output($file);
        return '/' . substr($this->directory, strlen('public/')) . '/' . $file;
    }

    /** The fingerprint is of manifest bytes, not of a timestamp. */
    public function fingerprint(): string
    {
        $this->reload();
        return $this->fingerprint ?? throw new FrontendException('Frontend manifest is unavailable.');
    }

    private function reload(): void
    {
        $build = $this->buildRoot();
        $manifest = $this->containedFile($build, $this->manifest);
        $size = @filesize($manifest);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
            throw new FrontendException('Frontend production manifest has an invalid size.');
        }
        $bytes = @file_get_contents($manifest);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new FrontendException('Frontend production manifest could not be read.');
        }
        $hash = hash('sha256', $bytes);
        if ($hash === $this->fingerprint && $this->entries !== null) {
            // A deployment can replace files without changing manifest bytes.
            // Recheck even outputs not used by the current entry, including
            // asset files and dynamic imports, before trusting cached metadata.
            $this->validateOutputs($this->entries);
            return;
        }
        try {
            $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new FrontendException('Frontend production manifest is malformed.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || count($decoded) === 0 || count($decoded) > self::MAX_ENTRIES) {
            throw new FrontendException('Frontend production manifest has an unsupported shape.');
        }
        $entries = [];
        $folded = [];
        foreach ($decoded as $source => $value) {
            if (!is_string($source) || !is_array($value) || array_is_list($value)) {
                throw new FrontendException('Frontend production manifest has an invalid entry.');
            }
            self::relative($source, false);
            $case = self::collisionKey($source);
            if (isset($folded[$case])) {
                throw new FrontendException('Frontend production manifest has a casing collision.');
            }
            $folded[$case] = true;
            $allowed = ['file', 'src', 'name', 'isEntry', 'isDynamicEntry',
                'css', 'assets', 'imports', 'dynamicImports'];
            if (!isset($value['file']) || !is_string($value['file'])
                || array_diff(array_keys($value), $allowed) !== []) {
                throw new FrontendException('Frontend production manifest has an unsupported entry.');
            }
            self::relative($value['file'], false);
            foreach (['src', 'name'] as $field) {
                if (array_key_exists($field, $value)
                    && (!is_string($value[$field]) || $value[$field] === ''
                        || strlen($value[$field]) > 512)) {
                    throw new FrontendException('Frontend production manifest metadata is invalid.');
                }
            }
            foreach (['isEntry', 'isDynamicEntry'] as $field) {
                if (array_key_exists($field, $value) && !is_bool($value[$field])) {
                    throw new FrontendException('Frontend production manifest metadata is invalid.');
                }
            }
            foreach (['css', 'assets', 'imports', 'dynamicImports'] as $field) {
                if (!array_key_exists($field, $value)) { continue; }
                if (!is_array($value[$field]) || !array_is_list($value[$field])
                    || count($value[$field]) > self::MAX_ENTRIES) {
                    throw new FrontendException('Frontend production manifest dependency list is invalid.');
                }
                foreach ($value[$field] as $item) {
                    if (!is_string($item)) {
                        throw new FrontendException('Frontend production manifest dependency is invalid.');
                    }
                    self::relative($item, false);
                }
            }
            $entries[$source] = $value;
        }
        $this->validateOutputs($entries);
        $this->entries = $entries;
        $this->fingerprint = $hash;
    }

    /** @param array<string, array<string,mixed>> $entries */
    private function validateOutputs(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->output($entry['file']);
            foreach (['css', 'assets'] as $field) {
                foreach ($entry[$field] ?? [] as $file) { $this->output($file); }
            }
            foreach (['imports', 'dynamicImports'] as $field) {
                foreach ($entry[$field] ?? [] as $key) {
                    if (!isset($entries[$key])) {
                        throw new FrontendException('Frontend production manifest import is missing.');
                    }
                }
            }
        }
    }

    private function buildRoot(): string
    {
        $publicAssets = $this->root . '/public/assets';
        $this->physicalAncestors($publicAssets);
        $path = $this->root . '/' . $this->directory;
        $this->physicalAncestors($path);
        $root = realpath($path);
        $assets = realpath($publicAssets);
        if ($root === false || $assets === false || !is_dir($root)) {
            throw new FrontendException('Frontend production build is unavailable.');
        }
        $root = str_replace('\\', '/', $root);
        $assets = rtrim(str_replace('\\', '/', $assets), '/');
        if (!str_starts_with($root, $assets . '/')) {
            throw new FrontendException('Frontend production build escapes public assets.');
        }
        return $root;
    }

    private function output(string $file): string
    {
        return $this->containedFile($this->buildRoot(), $file);
    }

    private function containedFile(string $root, string $relative): string
    {
        $path = $root . '/' . $relative;
        $this->physicalAncestors($path);
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new FrontendException('Frontend production asset is missing.');
        }
        $resolved = str_replace('\\', '/', $resolved);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (!str_starts_with($resolved, $root . '/')) {
            throw new FrontendException('Frontend production asset escapes its build root.');
        }
        return $resolved;
    }

    /** Check each existing ancestor so a symlink or Windows junction cannot hide. */
    private function physicalAncestors(string $path): void
    {
        $root = $this->root;
        $relative = substr(str_replace('\\', '/', $path), strlen($root) + 1);
        if ($relative === '') { return; }
        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            if (is_dir($current)) {
                $names = @scandir($current);
                if ($names === false || !in_array($segment, $names, true)) {
                    throw new FrontendException('Frontend build path is unavailable or mis-cased.');
                }
            }
            $current .= '/' . $segment;
            if (file_exists($current) || is_link($current)) {
                try {
                    PackageFiles::assertPhysical($current);
                } catch (PackageException $error) {
                    throw new FrontendException('Frontend build contains an unsafe link.', 0, $error);
                }
            }
        }
    }

    /** Paths from JSON are filenames, never URLs or process instructions. */
    private static function relative(string $path, bool $manifest): void
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F:%?#]/', $path) === 1
            || preg_match('/\.(?:php[0-9]?|phtml|phar)\z/iD', $path) === 1
            || preg_match('//u', $path) !== 1) {
            throw new FrontendException('Frontend manifest path is unsafe.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || strlen($segment) > 255 || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
                || (!$manifest && str_starts_with($segment, '.'))
                || ($manifest && str_starts_with($segment, '.') && $segment !== '.vite')
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/iD', $segment) === 1) {
                throw new FrontendException('Frontend manifest path is unsafe.');
            }
        }
        if (class_exists(Normalizer::class)) {
            if (Normalizer::normalize($path, Normalizer::FORM_C) !== $path) {
                throw new FrontendException('Frontend manifest path is noncanonical.');
            }
        } elseif (preg_match('/[^\x00-\x7F]/', $path) === 1) {
            throw new FrontendException('Unicode frontend paths require the intl extension.');
        }
    }

    private static function collisionKey(string $path): string
    {
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($path, MB_CASE_FOLD, 'UTF-8');
        }
        if (preg_match('/[^\x00-\x7F]/', $path) === 1) {
            throw new FrontendException('Unicode frontend paths require mbstring.');
        }
        return strtolower($path);
    }
}
