<?php

declare(strict_types=1);

namespace App\Routing;

use App\Api\ApiVersionPolicy;
use Closure;
use InvalidArgumentException;

/** Fluent group attributes; the registry applies them while the callback runs. */
final class RouteGroup
{
    private string $prefix = '';
    private array $middleware = [];
    private ?string $apiVersion = null;
    private ?string $host = null;

    public function __construct(private RouteRegistry $registry)
    {
    }

    public function prefix(string $prefix): self
    {
        $this->prefix = RouteRegistry::joinPaths($this->prefix, $prefix);
        return $this;
    }

    /** Attach semantic version metadata to all routes in the group. */
    public function apiVersion(string $version): self
    {
        $normalized = ApiVersionPolicy::normalizeIdentifier($version);
        if ($this->apiVersion !== null && $this->apiVersion !== $normalized) {
            throw new \LogicException('A route group cannot declare conflicting API versions.');
        }
        $this->apiVersion = $normalized;
        return $this;
    }

    /** Apply one host condition to every route in the group. */
    public function host(string $host): self
    {
        $normalized = (new RoutePattern('/', $host))->host();
        if ($this->host !== null && $this->host !== $normalized) {
            throw new \LogicException('A route group cannot declare conflicting hosts.');
        }
        $this->host = $normalized;
        return $this;
    }

    /** Group entries use the same alias and explicit-object contract as routes. */
    public function through(string|object|array $middleware): self
    {
        $items = is_array($middleware) ? $middleware : [$middleware];
        foreach ($items as $item) {
            if ((!is_string($item) || trim($item) === '')
                && (!is_object($item) || (!(method_exists($item, 'handle') && is_callable([$item, 'handle']))
                    && !is_callable($item)))) {
                throw new InvalidArgumentException('Group middleware must be an alias, class, or handler object.');
            }
            $this->middleware[] = $item;
        }
        return $this;
    }

    public function routes(Closure $callback): void
    {
        $this->registry->runGroup($this->prefix, $this->middleware, $callback, $this->apiVersion, $this->host);
    }
}
