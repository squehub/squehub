<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Routing\Route as RoutingRoute;
use App\Routing\RouteDefinition;
use App\Routing\RouteGroup;
use App\Routing\RoutePath;
use App\Routing\RouteRegistry;

/**
 * Stable application route gateway. Every registration reaches the same
 * Application-owned registry used by App\Routing\Route and App\Core\Route.
 */
final class Route
{
    public static function registry(): ?RouteRegistry { return RoutingRoute::registry(); }
    public static function path(string $uri): RoutePath { return RoutingRoute::path($uri); }
    public static function group(): RouteGroup { return RoutingRoute::group(); }

    /** @return list<RouteDefinition> */
    public static function resource(string $uri, string $controller): array
    {
        return RoutingRoute::resource($uri, $controller);
    }

    public static function error(int $status, callable $handler): void
    {
        RoutingRoute::error($status, $handler);
    }

}
