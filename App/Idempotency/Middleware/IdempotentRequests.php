<?php

declare(strict_types=1);

namespace App\Idempotency\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Idempotency\Idempotency;
use App\Idempotency\IdempotencyException;
use App\Routing\RouteDefinition;
use Closure;

/** Explicit final route guard; earlier authentication and policy guards rerun on replay. */
final readonly class IdempotentRequests
{
    private function __construct() {}

    public static function authenticated(): self { return new self(); }

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->attribute('route');
        if (!$route instanceof RouteDefinition || $route->isLegacy()) {
            throw new IdempotencyException('Idempotency requires a modern route.');
        }
        $middleware = $route->middlewares();
        if ($middleware === [] || $middleware[array_key_last($middleware)] !== $this) {
            throw new IdempotencyException('Idempotency must be the final route middleware.');
        }
        return Idempotency::manager()->process($request, $route, $next);
    }
}
