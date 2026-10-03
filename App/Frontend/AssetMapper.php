<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Packages\PackageName;

/**
 * One Application-owned projection for handwritten and View-owned asset URLs.
 * Ordinary paths retain UrlBasePath semantics. Only explicit logical names
 * consult a production manifest or an active Package asset source.
 */
final class AssetMapper
{
    private ?AssetManifest $manifest = null;

    public function __construct(private readonly Application $application,
        private readonly UrlBasePath $basePath)
    {
    }

    public function url(string $reference): string
    {
        if (str_contains($reference, '::')) {
            return $this->packageUrl($reference);
        }
        if ($this->isLogical($reference) && $this->usesProductionManifest()) {
            $entry = $this->manifest()->find($reference);
            if ($entry !== null) {
                return $this->basePath->assetUrl($this->manifest()->publicUrl($entry['file']));
            }
        }
        return $this->basePath->assetUrl($reference);
    }

    /** A manifest is read only on actual production resolution or CLI proof. */
    public function manifest(): AssetManifest
    {
        if ($this->manifest !== null) { return $this->manifest; }
        $build = $this->application->config()->get('frontend.build', []);
        if (!is_array($build) || !is_string($build['directory'] ?? null)
            || !is_string($build['manifest'] ?? null)) {
            throw new FrontendException('Frontend build configuration is invalid.');
        }
        return $this->manifest = new AssetManifest($this->application->basePath(),
            $build['directory'], $build['manifest']);
    }

    public function basePath(): UrlBasePath
    {
        return $this->basePath;
    }

    private function usesProductionManifest(): bool
    {
        $adapter = $this->application->config()->get('frontend.adapter', 'none');
        if ($adapter !== 'none' && $adapter !== 'vite') {
            throw new FrontendException('Frontend adapter is unsupported.');
        }
        if ($adapter !== 'vite') { return false; }
        $dev = $this->application->config()->get('frontend.development.enabled', false);
        if (!is_bool($dev)) {
            throw new FrontendException('Frontend development configuration is invalid.');
        }
        return !in_array($this->application->environment(), ['development', 'local'], true)
            || !$dev;
    }

    /** A public URL has an established meaning and is never a manifest key. */
    private function isLogical(string $reference): bool
    {
        return $reference !== '' && $reference[0] !== '/'
            && $reference[0] !== '?' && $reference[0] !== '#'
            && !str_contains($reference, ':') && !str_contains($reference, '?')
            && !str_contains($reference, '#');
    }

    private function packageUrl(string $reference): string
    {
        [$name, $path] = explode('::', $reference, 2);
        if (!PackageName::valid($name) || $path === ''
            || str_contains($path, '::')) {
            throw new FrontendException('Logical Package asset name is invalid.');
        }
        // The middleware and this mapper share PackageAssetSource's active
        // Package and physical containment policy; an installed disabled
        // Package cannot obtain a logical public asset URL.
        $source = $this->application->container()->make(PackageAssetSource::class)
            ->resolve($name, $path);
        if ($source === null) {
            throw new FrontendException('Logical Package asset is unavailable.');
        }
        $hash = @hash_file('sha256', $source);
        if (!is_string($hash)) {
            throw new FrontendException('Logical Package asset could not be read.');
        }
        return $this->basePath->assetUrl('/assets/Packages/' . $name . '/' . $path
            . '?v=' . substr($hash, 0, 16));
    }
}
