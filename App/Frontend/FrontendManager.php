<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Core\ViewEscaper;
use App\Foundation\Application;
use App\Packages\PackageException;
use App\Packages\PackageFiles;

/**
 * Converts one declared frontend entry into safe, ordered View stack markup.
 * The selected adapter owns its build convention; this manager does not start
 * Node, probe a dev server, or make frontend tooling a PHP boot dependency.
 */
final class FrontendManager
{
    public function __construct(private readonly Application $application,
        private readonly AssetMapper $assets)
    {
    }

    /** @return array{head:list<string>,styles:list<string>,scripts:list<string>} */
    public function entryTags(string $name): array
    {
        if (strlen($name) > 128
            || preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new FrontendException('Frontend entry name is invalid.');
        }
        $entries = $this->application->config()->get('frontend.entries', []);
        if (!is_array($entries) || !is_string($entries[$name] ?? null)) {
            throw new FrontendException('Frontend entry is not configured.');
        }
        $source = $entries[$name];
        $adapter = $this->application->config()->get('frontend.adapter', 'none');
        if ($adapter === 'none') {
            if (!str_starts_with($source, '/assets/') && !str_contains($source, '::')) {
                throw new FrontendException('Native frontend entry must be a public asset.');
            }
            if (preg_match('/\.(?:js|mjs)\z/iD', $source) !== 1) {
                throw new FrontendException('Native frontend entry must be a JavaScript module.');
            }
            if (str_starts_with($source, '/assets/')) { $this->requireNativeFile($source); }
            $url = $this->assets->url($source);
            return ['head' => $this->importMap(), 'styles' => [],
                'scripts' => [self::module($url)]];
        }
        if ($adapter !== 'vite') {
            throw new FrontendException('Frontend adapter is unsupported.');
        }
        self::sourcePath($source);
        $development = $this->application->config()->get('frontend.development', []);
        if (!is_array($development)
            || !is_bool($development['enabled'] ?? null)) {
            throw new FrontendException('Frontend development configuration is invalid.');
        }
        if (in_array($this->application->environment(), ['development', 'local'], true)
            && $development['enabled']) {
            $url = self::developmentUrl($development['url'] ?? null);
            return ['head' => [], 'styles' => [], 'scripts' => [
                self::module($url . '/@vite/client'),
                self::module($url . '/' . $source),
            ]];
        }

        // A Vite profile outside explicitly enabled development must use its
        // current local build. Production never emits HMR or loopback URLs.
        $resources = $this->assets->manifest()->resources($source);
        $styles = [];
        foreach ($resources['css'] as $file) {
            $styles[] = '<link rel="stylesheet" href="'
                . ViewEscaper::escape($this->assets->basePath()->assetUrl($file)) . '">';
        }
        $head = [];
        foreach ($resources['imports'] as $file) {
            $head[] = '<link rel="modulepreload" href="'
                . ViewEscaper::escape($this->assets->basePath()->assetUrl($file)) . '">';
        }
        $script = self::module($this->assets->basePath()->assetUrl($resources['file']));
        return ['head' => $head, 'styles' => $styles, 'scripts' => [$script]];
    }

    /** Native import maps are explicit application config, never PHP objects. */
    private function importMap(): array
    {
        $imports = $this->application->config()->get('frontend.imports', []);
        if (!is_array($imports) || count($imports) > 256) {
            throw new FrontendException('Frontend import map is invalid.');
        }
        if ($imports === []) { return []; }
        $mapped = [];
        $folded = [];
        foreach ($imports as $alias => $reference) {
            if (!is_string($alias) || !is_string($reference)
                || strlen($alias) > 128 || strlen($reference) > 1024
                || preg_match('/\A[@A-Za-z][@A-Za-z0-9._\/-]*\z/D', $alias) !== 1
                || str_contains($alias, '//') || str_contains($alias, '..')
                || isset($folded[strtolower($alias)])) {
                throw new FrontendException('Frontend import map alias is invalid or duplicated.');
            }
            $folded[strtolower($alias)] = true;
            if (!str_starts_with($reference, '/assets/')
                && !str_contains($reference, '::')
                && !str_starts_with($reference, 'https://')) {
                throw new FrontendException('Frontend import map URL is unsupported.');
            }
            // Import-map prefix values need a trailing slash, whereas ordinary
            // asset URLs intentionally reject an empty final path segment.
            $prefix = str_ends_with($alias, '/');
            if ($prefix && str_contains($reference, '::')) {
                // PackageAssetSource validates physical files for active
                // Packages; it deliberately does not publish directories.
                throw new FrontendException(
                    'Package import-map prefixes are unsupported; use a file alias.'
                );
            }
            if ($prefix !== str_ends_with($reference, '/')) {
                throw new FrontendException('Frontend import map prefix is invalid.');
            }
            $plain = $prefix ? substr($reference, 0, -1) : $reference;
            $mapped[$alias] = $this->assets->url($plain) . ($prefix ? '/' : '');
        }
        try {
            $json = json_encode(['imports' => $mapped], JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
                | JSON_HEX_APOS | JSON_HEX_QUOT);
        } catch (\JsonException $error) {
            throw new FrontendException('Frontend import map could not be encoded.', 0, $error);
        }
        return ['<script type="importmap">' . $json . '</script>'];
    }

    /** An entry is a source key, not a URL or executable command. */
    private static function sourcePath(string $source): void
    {
        if ($source === '' || strlen($source) > 512 || str_starts_with($source, '/')
            || str_contains($source, '\\')
            || preg_match('/[\x00-\x20\x7F:?#]/', $source) === 1
            || preg_match('/\.(?:js|mjs|jsx|ts|tsx)\z/iD', $source) !== 1) {
            throw new FrontendException('Frontend source entry is invalid.');
        }
        foreach (explode('/', $source) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || str_starts_with($part, '.')) {
                throw new FrontendException('Frontend source entry is invalid.');
            }
        }
    }

    /** Only an explicitly configured loopback origin can host development JS. */
    private static function developmentUrl(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 256
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new FrontendException('Frontend development URL is invalid.');
        }
        try { $parts = parse_url($url); } catch (\ValueError) { $parts = false; }
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || !in_array($parts['host'] ?? null,
                ['127.0.0.1', 'localhost', '[::1]'], true)
            || !is_int($parts['port'] ?? null) || $parts['port'] < 1
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new FrontendException('Frontend development URL must be a loopback HTTP origin.');
        }
        return rtrim($url, '/');
    }

    private static function module(string $url): string
    {
        return '<script type="module" src="' . ViewEscaper::escape($url)
            . '"></script>';
    }

    /** Native modules are served as files, never from an inferred source tree. */
    private function requireNativeFile(string $source): void
    {
        // UrlBasePath validates URL segments first; the physical check then
        // rejects linked assets without changing asset()'s URL-only contract.
        $this->assets->url($source);
        $root = realpath($this->application->basePath('public/assets'));
        $path = $this->application->basePath('public') . $source;
        if ($root === false || !is_dir($root)) {
            throw new FrontendException('Native frontend asset is unavailable.');
        }
        $current = $this->application->basePath('public');
        foreach (explode('/', ltrim($source, '/')) as $part) {
            if (is_dir($current)) {
                $names = @scandir($current);
                if ($names === false || !in_array($part, $names, true)) {
                    throw new FrontendException('Native frontend asset is unavailable or mis-cased.');
                }
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new FrontendException('Native frontend asset contains an unsafe link.');
            }
            if (file_exists($current)) {
                try { PackageFiles::assertPhysical($current); }
                catch (PackageException $error) {
                    throw new FrontendException('Native frontend asset contains an unsafe link.',
                        0, $error);
                }
            }
        }
        $resolved = realpath($path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($resolved === false || !is_file($resolved)
            || !str_starts_with(str_replace('\\', '/', $resolved), $root . '/')) {
            throw new FrontendException('Native frontend asset is unavailable.');
        }
    }
}
