<?php

declare(strict_types=1);

namespace App\Security\SignedUrl;

use App\Cryptography\CryptManager;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Foundation\UrlBasePath;
use App\Http\Request;
use App\Routing\RouteDefinition;
use App\Routing\RoutePattern;
use App\Routing\RouteRegistry;
use InvalidArgumentException;

/** Application-owned, purpose-bound authenticity for temporary named routes. */
final class SignedUrlManager
{
    private const CRYPT_PURPOSE = 'squehub.signed-url.v1';
    private const MAX_LIFETIME = 366 * 86400;
    private const MAX_PATH_BYTES = 8192;
    private ModelClock $clock;

    public function __construct(
        private RouteRegistry $routes,
        private UrlBasePath $basePath,
        private CryptManager $crypt,
        ?ModelClock $clock = null
    ) {
        $this->clock = $clock ?? new SystemModelClock();
    }

    /**
     * Return a relative public URL. Expiry is a UTC Unix second, at most one
     * year away; applications choose and authorize the destination parameters.
     *
     * @param array<string,mixed> $routeParams
     * @param array<string,string|int> $query
     */
    public function temporary(string $routeName, array $routeParams, array $query,
        int $expiresAt, string $purpose, string $method = 'GET'): string
    {
        self::validatePurpose($purpose);
        $method = self::normalizeMethod($method);
        $now = $this->now();
        if ($expiresAt <= $now || $expiresAt > $now + self::MAX_LIFETIME) {
            throw new InvalidArgumentException('Signed URL expiry must be in the next 366 days.');
        }
        $route = $this->routes->namedRoute($routeName);
        if ($route === null || $route->isLegacy() || $route->isFallback()
            || !self::routeAllows($route, $method)) {
            throw new InvalidArgumentException('Named route cannot be signed for the requested method.');
        }
        $host = self::routeHost($route);
        $publicPath = $this->routes->url($routeName, $routeParams);
        $path = $this->basePath->strip($publicPath);
        if ($path === null || !self::validPath($path)) {
            throw new InvalidArgumentException('Named route produced an unsafe signed URL path.');
        }
        $canonicalQuery = SignedUrlQuery::build($query);
        $expiry = (string) $expiresAt;
        $signature = $this->crypt->sign(
            self::material($routeName, $host, $path, $canonicalQuery, $expiry, $purpose, $method),
            self::CRYPT_PURPOSE
        );
        $fields = [];
        if ($canonicalQuery !== '') $fields[] = $canonicalQuery;
        $fields[] = SignedUrlQuery::EXPIRES . '=' . $expiry;
        $fields[] = SignedUrlQuery::SIGNATURE . '=' . rawurlencode($signature);
        return $publicPath . '?' . implode('&', $fields);
    }

    /** Verify the route already matched by this Application's dispatcher. */
    public function valid(Request $request, string $purpose, string $method = 'GET'): bool
    {
        self::validatePurpose($purpose);
        $method = self::normalizeMethod($method);
        if ($request->basePath() !== $this->basePath->value()
            || !self::requestAllows($request, $method)) return false;
        $route = $request->attribute('route');
        if (!$route instanceof RouteDefinition || $route->isLegacy() || $route->isFallback()
            || !self::routeAllows($route, $method)) return false;
        $routeName = $route->nameValue();
        if ($routeName === null || $this->routes->namedRoute($routeName) !== $route) return false;
        $hostPattern = $route->hostPattern();
        if ($hostPattern !== null && str_contains($hostPattern, '{')) return false;
        $requestHost = RoutePattern::requestHost($request);
        if ($route->pattern()->match($request->path(), $requestHost) === null) return false;
        $host = $hostPattern ?? '';
        $path = $this->basePath->strip($request->rawPath());
        if ($path === null || !self::validPath($path)) return false;
        $parsed = SignedUrlQuery::parse($request);
        if ($parsed === null) return false;
        $expiry = (int) $parsed['expires'];
        $now = $this->now();
        if ($expiry <= $now || $expiry > $now + self::MAX_LIFETIME) return false;
        return $this->crypt->verify(
            self::material($routeName, $host, $path, $parsed['query'], $parsed['expires'], $purpose, $method),
            $parsed['signature'], self::CRYPT_PURPOSE
        );
    }

    private static function material(string ...$parts): string
    {
        $framed = 'signed-url-v1';
        foreach ($parts as $part) $framed .= strlen($part) . ':' . $part;
        return $framed;
    }

    private static function validatePurpose(string $purpose): void
    {
        if (strlen($purpose) > 80
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $purpose) !== 1) {
            throw new InvalidArgumentException('Signed URL purpose is invalid.');
        }
    }

    private static function normalizeMethod(string $method): string
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Signed URL method is unsupported.');
        }
        return $method;
    }

    private static function routeAllows(RouteDefinition $route, string $method): bool
    {
        return in_array($method, $route->methods(), true)
            || ($method === 'HEAD' && in_array('GET', $route->methods(), true));
    }

    private static function requestAllows(Request $request, string $method): bool
    {
        if ($method === 'GET') {
            return in_array($request->transportMethod(), ['GET', 'HEAD'], true)
                && in_array($request->method(), ['GET', 'HEAD'], true);
        }
        return $request->transportMethod() === $method && $request->method() === $method;
    }

    private static function routeHost(RouteDefinition $route): string
    {
        $host = $route->hostPattern() ?? '';
        if (str_contains($host, '{')) {
            throw new InvalidArgumentException('Parameterized host routes need an explicit origin and cannot be signed as relative URLs.');
        }
        return $host;
    }

    private static function validPath(string $path): bool
    {
        return strlen($path) <= self::MAX_PATH_BYTES && RoutePattern::safeRequestPath($path);
    }

    private function now(): int { return $this->clock->now()->getTimestamp(); }
}
