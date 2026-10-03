<?php

declare(strict_types=1);

namespace App\Foundation;

use InvalidArgumentException;

/**
 * The configured URL mount, independent of Application::basePath() on disk.
 * Application routes and contract paths never contain this deployment prefix.
 */
final readonly class UrlBasePath
{
    private string $path;

    public function __construct(string $path = '')
    {
        if ($path === '' || $path === '/') {
            $this->path = '';
            return;
        }
        if (strlen($path) > 1024 || $path[0] !== '/'
            || str_contains($path, '//') || str_contains($path, '\\')
            || preg_match('/[%?#\x00-\x20\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('HTTP URL base path must be a bounded, literal path prefix.');
        }
        $normalized = rtrim($path, '/');
        foreach (explode('/', substr($normalized, 1)) as $segment) {
            if ($segment === '.' || $segment === '..'
                || preg_match('/\A[A-Za-z0-9._~-]+\z/D', $segment) !== 1) {
                throw new InvalidArgumentException('HTTP URL base path contains an invalid segment.');
            }
        }
        $this->path = $normalized;
    }

    /** Root deployment is represented by the empty string. */
    public function value(): string
    {
        return $this->path;
    }

    /**
     * Remove only a complete leading mount segment. A null result means the
     * request belongs to another application, even when a fallback exists.
     */
    public function strip(string $requestPath): ?string
    {
        if ($this->path === '') {
            return $requestPath;
        }
        if ($requestPath === $this->path || $requestPath === $this->path . '/') {
            return '/';
        }
        return str_starts_with($requestPath, $this->path . '/')
            ? substr($requestPath, strlen($this->path))
            : null;
    }

    /** Prefix one application-root path, including the root route. */
    public function publicPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Public path must be an internal root path.');
        }
        return $this->path . $path;
    }

    /**
     * Returnable redirects may receive either an application path or an
     * already generated public path. External and protocol-relative targets
     * retain their original response contract.
     */
    public function publicLocation(string $location): string
    {
        if ($this->path === '' || $location === '' || $location[0] !== '/'
            || str_starts_with($location, '//')) {
            return $location;
        }
        if ($this->alreadyMounted($location)) {
            return $location;
        }
        return $this->publicPath($location);
    }

    /**
     * Application-owned asset paths share one mount policy for direct helpers
     * and compiled templates. External references remain application-authored;
     * only HTTP(S), data, blob, and protocol-relative forms are accepted.
     */
    public function assetUrl(string $url): string
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('Asset URL is empty or contains control characters.');
        }
        if ($url[0] === '#' || $url[0] === '?') {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            if (str_contains($url, '\\') || str_contains($url, ' ')
                || !$this->hasAssetAuthority('https:' . $url)) {
                throw new InvalidArgumentException('Protocol-relative asset URL is invalid.');
            }
            return $url;
        }
        if (preg_match('/\A([A-Za-z][A-Za-z0-9+.-]*):/', $url, $scheme) === 1) {
            if (!in_array(strtolower($scheme[1]), ['http', 'https', 'data', 'blob'], true)) {
                throw new InvalidArgumentException('Asset URL scheme is unsupported.');
            }
            // Browsers can resolve "https:foo" against the current page. Only
            // an HTTP(S) URL with an authority is truly independent of the mount.
            if (in_array(strtolower($scheme[1]), ['http', 'https'], true)
                && (!str_starts_with($url, $scheme[0] . '//')
                    || str_contains($url, '\\') || str_contains($url, ' ')
                    || !$this->hasAssetAuthority($url))) {
                throw new InvalidArgumentException('Absolute asset URL is invalid.');
            }
            return $url;
        }

        $rooted = $url[0] === '/';
        $path = preg_split('/[?#]/', $url, 2)[0] ?? '';
        if ($rooted && $path === '/') {
            return $this->publicLocation($url);
        }
        $this->validateAssetPath($rooted ? substr($path, 1) : $path);
        if ($rooted) {
            return $this->publicLocation($url);
        }

        // A relative asset keeps its historical browser-relative meaning at
        // the root. A mounted application needs a public mount-aware target.
        return $this->path === '' ? $url : $this->publicPath('/' . $url);
    }

    /** Reject unsafe local paths before either root or mounted resolution. */
    private function validateAssetPath(string $path): void
    {
        if ($path === '' || strlen($path) > 24576 || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Local asset URL is invalid.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $segment) === 1) {
                throw new InvalidArgumentException('Local asset URL has an invalid segment.');
            }
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || str_contains($decoded, '/')
                || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
                || preg_match('//u', $decoded) !== 1) {
                throw new InvalidArgumentException('Local asset URL has an unsafe segment.');
            }
        }
    }

    /** Require a host, and never embed userinfo in a browser asset reference. */
    private function hasAssetAuthority(string $url): bool
    {
        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return false;
        }
        return is_array($parts) && isset($parts['host']) && $parts['host'] !== ''
            && !isset($parts['user']) && !isset($parts['pass'])
            && preg_match('/[\x00-\x20\x7F]/', $parts['host']) !== 1;
    }

    private function alreadyMounted(string $path): bool
    {
        if ($path === $this->path) {
            return true;
        }
        $next = substr($path, strlen($this->path), 1);
        return str_starts_with($path, $this->path) && in_array($next, ['/', '?', '#'], true);
    }
}
