<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Repository;
use App\Http\BrowserNavigation;
use App\Http\Request;
use InvalidArgumentException;

/**
 * Explicit path scopes select error formatting before routing or middleware.
 * This policy grants no authentication, CSRF exemption, or version semantics.
 * Request headers and query values cannot activate it.
 * @internal
 */
final class ApiRequestPolicy
{
    public function __construct(private Repository $config)
    {
    }

    public function matches(Request $request): bool
    {
        // The Kernel captures this decision before dispatch and replaces it
        // on each handle(), even when callers reuse the same Request object.
        $captured = $request->attribute('_squehub.api_scope');
        if (is_bool($captured)) return $captured;
        $enabled = $this->config->get('api.enabled', false);
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('API enabled configuration must be a boolean.');
        }
        if (!$enabled) return false;
        $paths = $this->config->get('api.paths', ['/api']);
        if (!is_array($paths) || !array_is_list($paths) || count($paths) > 64) {
            throw new InvalidArgumentException('API paths must be a bounded list of path prefixes.');
        }
        $prefixes = [];
        foreach ($paths as $path) {
            if (!is_string($path) || strlen($path) > 1024
                || BrowserNavigation::internalPath($path) === null
                || strpbrk($path, '*[](){}|^$') !== false) {
                throw new InvalidArgumentException('API paths require literal internal path prefixes.');
            }
            $prefixes[] = $path === '/' ? '/' : rtrim($path, '/');
        }
        $path = $request->path();
        if ($this->config->get('health.endpoints_enabled', false) === true
            && in_array($path, ['/health/live', '/health/ready'], true)) {
            return false;
        }
        foreach ($prefixes as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/')) return true;
        }
        return false;
    }
}
