<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Config\Repository;
use App\Core\View;
use App\Http\BrowserNavigation;
use App\Http\Request;
use App\Http\Response;
use App\Routing\RoutePattern;
use App\View\LogicalViewName;
use InvalidArgumentException;

/**
 * Selects an explicitly configured browser shell only after routing misses.
 * Ordinary routes, method errors, and registered route fallbacks stay in the
 * matcher; this policy never makes an API or missing asset look successful.
 */
final class SpaFallbackPolicy
{
    public function __construct(private Repository $config)
    {
    }

    public function responseFor(Request $request): ?Response
    {
        $settings = $this->settings();
        if (!$settings['enabled'] || !in_array($request->method(), ['GET', 'HEAD'], true)
            || $request->attribute('_squehub.api_scope') === true
            || !RoutePattern::safeRequestPath($request->rawPath())
            || $request->headerConflict('Accept')
            || $request->headerConflict('Sec-Fetch-Mode')
            || $request->header('X-Requested-With') !== null
            || !$this->acceptsHtml($request)) {
            return null;
        }

        // The Kernel has already stripped APP_BASE_PATH. Decode only after its
        // segment safety check so encoded private/API spellings cannot evade
        // the exclusions while encoded separators and traversal stay rejected.
        $path = $request->path();
        $decoded = rawurldecode($path);
        if (!self::under($path, $settings['prefix'])
            || self::excluded($decoded, $settings['except'])
            || self::excluded($decoded, $this->apiPrefixes())
            || self::reserved($decoded)) {
            return null;
        }

        // A session token may be rendered by the shell. It must never enter a
        // shared cache, and Accept-based selection must not be cached as JSON.
        return View::response($settings['view'], [], 200, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Accept',
        ]);
    }

    /** @return array{enabled:bool,prefix:string,view:string,except:list<string>} */
    private function settings(): array
    {
        $settings = $this->config->get('frontend.spa', []);
        if (!is_array($settings)) {
            throw new InvalidArgumentException('Frontend SPA configuration must be a map.');
        }
        $enabled = $settings['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Frontend SPA enabled setting must be boolean.');
        }
        if (!$enabled) {
            return ['enabled' => false, 'prefix' => '/', 'view' => '', 'except' => []];
        }
        $prefix = self::literalPrefix($settings['prefix'] ?? '/');
        $view = $settings['view'] ?? null;
        if (!is_string($view) || LogicalViewName::parse($view) === null) {
            throw new InvalidArgumentException('Enabled frontend SPA needs a valid logical shell View.');
        }
        $except = $settings['except'] ?? [];
        if (!is_array($except) || !array_is_list($except) || count($except) > 64) {
            throw new InvalidArgumentException('Frontend SPA exclusions must be a bounded list.');
        }
        $normalized = [];
        foreach ($except as $entry) {
            $normalized[] = self::literalPrefix($entry);
        }
        return ['enabled' => true, 'prefix' => $prefix, 'view' => $view, 'except' => $normalized];
    }

    private static function literalPrefix(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 1024
            || BrowserNavigation::internalPath($value) === null
            || preg_match('~[\*\[\]\(\)\{\}\|\^\$]~', $value) === 1) {
            throw new InvalidArgumentException('Frontend SPA paths require bounded literal prefixes.');
        }
        return $value === '/' ? '/' : rtrim($value, '/');
    }

    /** Prefix matching is segment-aware, so /app never catches /apple. */
    private static function under(string $path, string $prefix): bool
    {
        return $prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /** @param list<string> $prefixes */
    private static function excluded(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (self::under($path, $prefix)) return true;
        }
        return false;
    }

    /** @return list<string> */
    private function apiPrefixes(): array
    {
        $paths = $this->config->get('api.paths', ['/api']);
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > 64) {
            throw new InvalidArgumentException('API path reservations must be a bounded list.');
        }
        $prefixes = ['/api'];
        foreach ($paths as $path) {
            $prefixes[] = self::literalPrefix($path);
        }
        return $prefixes;
    }

    private static function reserved(string $path): bool
    {
        $lower = strtolower($path);
        foreach (['/assets', '/health', '/studio', '/_squehub', '/squehub',
            '/vendor', '/node_modules', '/public', '/config', '/bootstrap',
            '/database', '/docs', '/tests', '/storage', '/project'] as $prefix) {
            if (self::under($lower, $prefix)) return true;
        }
        // A configured /app SPA prefix must remain usable. Source-shaped App/
        // requests are still rejected by their file suffix or exact casing.
        if (self::under($path, '/App')) return true;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment !== '' && $segment[0] === '.') return true;
        }
        return $path !== '/' && str_contains((string) basename($path), '.');
    }

    private function acceptsHtml(Request $request): bool
    {
        if ($request->expectsJson()) return false;
        $mode = $request->header('Sec-Fetch-Mode');
        if ($mode !== null && $mode !== 'navigate') return false;
        $accept = $request->header('Accept');
        if ($accept === null) return false;
        if (!is_string($accept) || strlen($accept) > 4096) return false;
        foreach (explode(',', strtolower($accept)) as $item) {
            $parts = array_map('trim', explode(';', $item));
            if (!in_array($parts[0] ?? '', ['text/html', 'application/xhtml+xml'], true)) continue;
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $part) {
                if (!str_starts_with($part, 'q')) continue;
                if (preg_match('/\Aq\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/D', $part, $match) !== 1) {
                    continue 2;
                }
                $quality = (float) $match[1];
            }
            if ($quality > 0.0) return true;
        }
        return false;
    }
}
