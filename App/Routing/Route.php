<?php

declare(strict_types=1);

namespace App\Routing;

use Closure;
use LogicException;

/** Static developer API backed by the Application's instance-based registry. */
final class Route
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function registry(): ?RouteRegistry
    {
        if (self::$resolver === null) {
            return null;
        }
        $registry = (self::$resolver)();
        if (!$registry instanceof RouteRegistry) {
            throw new LogicException('Route resolver must return a RouteRegistry.');
        }
        return $registry;
    }

    private static function requireRegistry(): RouteRegistry
    {
        return self::registry() ?? throw new LogicException('Route API is unavailable before Application bootstrap.');
    }

    public static function path(string $uri): RoutePath
    {
        return new RoutePath(self::requireRegistry(), $uri);
    }

    public static function group(): RouteGroup
    {
        return self::requireRegistry()->groupBuilder();
    }

    /** @return list<RouteDefinition> */
    public static function resource(string $uri, string $controller): array
    {
        return self::requireRegistry()->resource($uri, $controller);
    }

    /** Render a custom browser error page for one HTTP error status. */
    public static function error(int $status, callable $handler): void
    {
        self::requireRegistry()->setErrorHandler($status, $handler);
    }
}
