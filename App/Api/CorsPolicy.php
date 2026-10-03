<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Repository;
use App\Http\BrowserNavigation;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

/**
 * An opt-in, application-owned browser CORS policy. It does not authorize a
 * request or relax session, authentication, or CSRF checks.
 * @internal
 */
final class CorsPolicy
{
    private const ROUTE_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const HEADER_NAME = "/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D";

    private bool $enabled;
    /** @var list<string> */
    private array $paths = [];
    /** @var array<string, true> */
    private array $origins = [];
    /** @var array<string, true> */
    private array $methods = [];
    /** @var array<string, string> Lowercase name to configured spelling. */
    private array $allowedHeaders = [];
    /** @var list<string> */
    private array $exposedHeaders = [];
    private bool $credentials;
    private int $maxAge;

    public function __construct(Repository $config)
    {
        $options = $config->get('api.cors', []);
        if (!is_array($options) || array_is_list($options) && $options !== []) {
            throw new InvalidArgumentException('API CORS configuration must be an object.');
        }
        $option = static fn (string $key, mixed $fallback): mixed =>
            array_key_exists($key, $options) ? $options[$key] : $fallback;
        $enabled = $option('enabled', false);
        $paths = $option('paths', ['/api']);
        $origins = $option('allowed_origins', []);
        $methods = $option('allowed_methods', ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']);
        $allowedHeaders = $option('allowed_headers', []);
        $exposedHeaders = $option('exposed_headers', []);
        $credentials = $option('allow_credentials', false);
        $maxAge = $option('max_age', 600);

        if (!is_bool($enabled) || !is_bool($credentials)) {
            throw new InvalidArgumentException('API CORS enabled and allow_credentials must be booleans.');
        }
        if (!is_int($maxAge) || $maxAge < 0 || $maxAge > 86400) {
            throw new InvalidArgumentException('API CORS max_age must be an integer from 0 to 86400.');
        }
        $this->enabled = $enabled;
        $this->credentials = $credentials;
        $this->maxAge = $maxAge;
        $this->paths = self::paths($paths);
        $this->origins = self::origins($origins);
        $this->methods = self::methods($methods);
        $this->allowedHeaders = self::headers($allowedHeaders, 'allowed_headers');
        $this->exposedHeaders = array_values(self::headers($exposedHeaders, 'exposed_headers'));
        foreach ($this->exposedHeaders as $header) {
            if (in_array(strtolower($header), ['cookie', 'set-cookie'], true)) {
                throw new InvalidArgumentException('API CORS exposed_headers cannot expose cookies.');
            }
        }
        if ($credentials && isset($this->origins['*'])) {
            throw new InvalidArgumentException('API CORS wildcard origins cannot allow credentials.');
        }
    }

    /** A browser preflight has OPTIONS, Origin, and a requested method. */
    public function isPreflight(Request $request): bool
    {
        return $request->method() === 'OPTIONS'
            && ($request->header('Origin') !== null
                && $request->header('Access-Control-Request-Method') !== null);
    }

    /**
     * @param callable(string):bool $routeAcceptsMethod A read-only Router probe.
     * @return Response|null Null leaves ordinary HTTP dispatch unchanged.
     */
    public function preflight(Request $request, callable $routeAcceptsMethod): ?Response
    {
        $request->setAttribute('_squehub.cors_preflight_allowed', false);
        if (!$this->enabled || !$this->matchesPath($request) || !$this->isPreflight($request)) {
            return null;
        }
        $origin = $request->header('Origin');
        $methodHeader = $request->header('Access-Control-Request-Method');
        if ($request->headerConflict('Origin')
            || $request->headerConflict('Access-Control-Request-Method')
            || $request->headerConflict('Access-Control-Request-Headers')
            || !is_string($origin) || !$this->allowsOrigin($origin)
            || !is_string($methodHeader)
            || preg_match('/\A[ \t]*([A-Za-z]+)[ \t]*\z/D', $methodHeader, $match) !== 1) {
            throw self::denied();
        }
        $method = strtoupper($match[1]);
        if (!isset($this->methods[$method])) {
            throw self::denied();
        }
        $requestedHeaders = $this->requestedHeaders($request->header('Access-Control-Request-Headers'));
        foreach ($requestedHeaders as $name) {
            if (!isset($this->allowedHeaders[strtolower($name)])) {
                throw self::denied();
            }
        }
        if (!$routeAcceptsMethod($method)) {
            throw self::denied();
        }

        $headers = [
            'Access-Control-Allow-Origin' => $this->allowOriginValue($origin),
            'Access-Control-Allow-Methods' => $method,
            'Access-Control-Max-Age' => (string) $this->maxAge,
            'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
        ];
        if ($requestedHeaders !== []) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', array_map(
                fn (string $name): string => $this->allowedHeaders[strtolower($name)], $requestedHeaders
            ));
        }
        if ($this->credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        $request->setAttribute('_squehub.cors_preflight_allowed', true);
        return new Response('', 204, $headers);
    }

    /** Decorate after exception rendering so managed errors receive CORS too. */
    public function decorate(Request $request, Response $response): Response
    {
        if (!$this->enabled || !$this->matchesPath($request)) {
            return $response;
        }
        if ($this->isPreflight($request)) {
            // Only the response made by this policy carries a preflight grant.
            if ($request->attribute('_squehub.cors_preflight_allowed') !== true || $response->status() !== 204) {
                $response = self::withoutCorsHeaders($response);
            }
            return self::withVary($response, [
                'Origin', 'Access-Control-Request-Method', 'Access-Control-Request-Headers',
            ]);
        }

        $response = self::withoutCorsHeaders($response);
        // Include denied and absent origins so a shared cache cannot reuse a
        // response decorated for a different request Origin.
        $response = self::withVary($response, ['Origin']);
        $origin = $request->header('Origin');
        if ($request->headerConflict('Origin') || !is_string($origin) || !$this->allowsOrigin($origin)
            || !isset($this->methods[$request->method()])) {
            return $response;
        }
        $response = $response->withHeader('Access-Control-Allow-Origin', $this->allowOriginValue($origin));
        if ($this->credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }
        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }
        return $response;
    }

    private function matchesPath(Request $request): bool
    {
        $path = $request->path();
        foreach ($this->paths as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private function allowsOrigin(string $origin): bool
    {
        $normalized = self::normalizedOrigin($origin);
        if ($normalized === null) {
            return false;
        }
        if ($normalized === 'null') {
            return isset($this->origins['null']);
        }
        return isset($this->origins['*']) || isset($this->origins[$normalized]);
    }

    private function allowOriginValue(string $origin): string
    {
        return isset($this->origins['*']) && $origin !== 'null' ? '*' : $origin;
    }

    /** @return list<string> */
    private function requestedHeaders(mixed $header): array
    {
        if ($header === null) {
            return [];
        }
        if (!is_string($header) || $header === '' || strlen($header) > 4096) {
            throw self::denied();
        }
        $names = [];
        foreach (explode(',', $header) as $piece) {
            $name = trim($piece, " \t");
            if (preg_match(self::HEADER_NAME, $name) !== 1 || count($names) >= 64) {
                throw self::denied();
            }
            $names[strtolower($name)] = $name;
        }
        return array_values($names);
    }

    /** @return list<string> */
    private static function paths(mixed $paths): array
    {
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > 64) {
            throw new InvalidArgumentException('API CORS paths must be a bounded list of literal path prefixes.');
        }
        $result = [];
        foreach ($paths as $path) {
            if (!is_string($path) || strlen($path) > 1024
                || BrowserNavigation::internalPath($path) === null
                || strpbrk($path, '*[](){}|^$') !== false) {
                throw new InvalidArgumentException('API CORS paths require literal internal path prefixes.');
            }
            $result[] = $path === '/' ? '/' : rtrim($path, '/');
        }
        return array_values(array_unique($result));
    }

    /** @return array<string, true> */
    private static function origins(mixed $origins): array
    {
        if (!is_array($origins) || !array_is_list($origins) || count($origins) > 64) {
            throw new InvalidArgumentException('API CORS allowed_origins must be a bounded list.');
        }
        $result = [];
        foreach ($origins as $origin) {
            if (!is_string($origin) || strlen($origin) > 512) {
                throw new InvalidArgumentException('API CORS allowed_origins contains an invalid origin.');
            }
            $normalized = $origin === '*' ? '*' : self::normalizedOrigin($origin);
            if ($normalized === null) {
                throw new InvalidArgumentException('API CORS allowed_origins contains an invalid origin.');
            }
            $result[$normalized] = true;
        }
        return $result;
    }

    /** @return array<string, true> */
    private static function methods(mixed $methods): array
    {
        if (!is_array($methods) || !array_is_list($methods) || count($methods) > 16) {
            throw new InvalidArgumentException('API CORS allowed_methods must be a bounded list.');
        }
        $result = [];
        foreach ($methods as $method) {
            if (!is_string($method) || !in_array(strtoupper($method), self::ROUTE_METHODS, true)) {
                throw new InvalidArgumentException('API CORS allowed_methods contains an unsupported HTTP method.');
            }
            $result[strtoupper($method)] = true;
        }
        return $result;
    }

    /** @return array<string, string> */
    private static function headers(mixed $headers, string $key): array
    {
        if (!is_array($headers) || !array_is_list($headers) || count($headers) > 64) {
            throw new InvalidArgumentException("API CORS {$key} must be a bounded list of header names.");
        }
        $result = [];
        foreach ($headers as $header) {
            if (!is_string($header) || strlen($header) > 128
                || preg_match(self::HEADER_NAME, $header) !== 1) {
                throw new InvalidArgumentException("API CORS {$key} contains an invalid header name.");
            }
            $result[strtolower($header)] = $header;
        }
        return $result;
    }

    /** Normalized scheme/host/effective port; unsafe or opaque Origins fail closed. */
    private static function normalizedOrigin(string $origin): ?string
    {
        if ($origin === 'null') {
            return 'null';
        }
        if (strlen($origin) > 512
            || preg_match('~\A(https?)://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?\z~iD', $origin, $match) !== 1) {
            return null;
        }
        $scheme = strtolower($match[1]);
        $host = strtolower($match[2]);
        if (str_starts_with($host, '[')) {
            if (filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } else {
            if (strlen($host) > 253) {
                return null;
            }
            foreach (explode('.', $host) as $label) {
                if (strlen($label) > 63
                    || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) {
                    return null;
                }
            }
        }
        $port = $match[3] ?? null;
        if ($port !== null) {
            $number = (int) $port;
            if ($number < 1 || $number > 65535 || (string) $number !== $port) {
                return null;
            }
            if (($scheme === 'https' && $number === 443) || ($scheme === 'http' && $number === 80)) {
                $port = null;
            }
        }
        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }

    /** @param list<string> $names */
    private static function withVary(Response $response, array $names): Response
    {
        $existing = $response->header('Vary', '');
        $parts = array_values(array_filter(array_map('trim', explode(',', $existing ?? '')), 'strlen'));
        if (in_array('*', $parts, true)) {
            return $response;
        }
        $seen = [];
        $values = [];
        foreach ([...$parts, ...$names] as $name) {
            $lower = strtolower($name);
            if (!isset($seen[$lower])) {
                $seen[$lower] = true;
                $values[] = $name;
            }
        }
        return $response->withHeader('Vary', implode(', ', $values));
    }

    private static function withoutCorsHeaders(Response $response): Response
    {
        foreach (array_keys($response->headers()) as $name) {
            if (strncasecmp($name, 'Access-Control-', 15) === 0) {
                $response = $response->withoutHeader($name);
            }
        }
        return $response;
    }

    private static function denied(): ApiError
    {
        return ApiError::make('cors_preflight_denied', 'CORS preflight request is not allowed.', 403);
    }
}
