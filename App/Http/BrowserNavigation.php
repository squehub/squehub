<?php

declare(strict_types=1);

namespace App\Http;

use App\Foundation\UrlBasePath;
use App\Session\SessionManager;
use ValueError;

/**
 * Keeps a safe internal GET destination for browser validation redirects.
 *
 * The session stores only a path, never query data or credentials. Referer is
 * a fallback only after strict same-origin checks; unsafe values yield no
 * destination so validation can return direct HTML 422.
 */
final class BrowserNavigation
{
    public function __construct(private SessionManager $sessions, private ?UrlBasePath $basePath = null)
    {
    }

    /** Store only successful browser GET paths, never a query string or API request. */
    public function remember(Request $request, Response $response): void
    {
        $contentType = strtolower((string) $response->header('Content-Type', ''));
        if (!in_array($request->method(), ['GET', 'HEAD'], true) || $request->expectsJson()
            || strcasecmp((string) $request->header('X-Requested-With', ''), 'XMLHttpRequest') === 0
            || $response->status() < 200 || $response->status() >= 300
            || ($contentType !== '' && !str_contains($contentType, 'text/html'))
            || preg_match('~\.(?:css|js|map|png|jpe?g|gif|svg|ico|webp|woff2?|ttf|pdf)$~i', $request->path())) {
            return;
        }
        $path = self::internalPath($request->path());
        if ($path !== null) $this->sessions->store()->setPreviousPath($path);
    }

    /** Resolve only an internal path; Referer must match the current origin. */
    public function back(Request $request): ?string
    {
        $current = self::internalPath($request->path());
        $previous = self::internalPath($this->sessions->store()->previousPath());
        if ($previous !== null && $previous !== $current) return $previous;

        $referer = $request->header('Referer');
        if (!is_string($referer) || str_contains($referer, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $referer)) return null;
        try {
            $parts = parse_url($referer);
            $host = $request->host();
            $expectedPort = $request->port();
        } catch (ValueError) {
            return null;
        }
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || strcasecmp($parts['scheme'], $request->scheme()) !== 0
            || strcasecmp($parts['host'], $host) !== 0) return null;

        $actualPort = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if ($actualPort !== $expectedPort) return null;

        // Query and fragment are intentionally discarded: they can contain
        // credentials even when the origin and path are otherwise trusted.
        $publicPath = self::internalPath($parts['path'] ?? '/');
        if ($publicPath === null) return null;
        // Referer carries the public mount, while Session keeps application
        // paths. Never accept an otherwise same-origin URL outside this mount.
        $path = $this->basePath === null ? $publicPath : $this->basePath->strip($publicPath);
        if ($path === null) return null;
        $path = self::internalPath($path);
        return $path !== $current ? $path : null;
    }

    /** Shared strict validation for browser navigation and configured auth destinations. */
    public static function internalPath(?string $path): ?string
    {
        if ($path === null || $path === '' || $path[0] !== '/'
            || str_contains($path, '//') || str_contains($path, '\\')
            || str_contains($path, '%') || str_contains($path, '?') || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7F]/', $path)
            || preg_match('~(?:^|/)\.\.?(/|$)~', $path)) return null;
        return $path;
    }
}
