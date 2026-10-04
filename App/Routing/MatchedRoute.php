<?php

declare(strict_types=1);

namespace App\Routing;

/** Keeps a matched definition and its request-local parameter values together. */
final readonly class MatchedRoute
{
    /** @param array<string, string> $parameters */
    public function __construct(public RouteDefinition $route, public array $parameters)
    {
    }
}
