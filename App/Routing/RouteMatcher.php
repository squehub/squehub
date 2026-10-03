<?php

declare(strict_types=1);

namespace App\Routing;

use App\Http\Exception\MethodNotAllowedHttpException;
use App\Http\Exception\NotFoundHttpException;
use App\Http\Request;

/** Matches compiled route structure without invoking middleware, actions, or Models. */
final class RouteMatcher
{
    public function match(RouteRegistry $registry, Request $request): MatchedRoute
    {
        $normal = [];
        $fallbacks = [];
        foreach ($registry->all() as $index => $route) {
            if ($route->isFallback()) {
                $fallbacks[] = [$index, $route];
            } else {
                $normal[] = [$index, $route];
            }
        }
        // Static segments outrank constrained, then broad, then optional
        // segments. Equivalent shapes retain explicit registration order.
        usort($normal, static function (array $left, array $right): int {
            $leftRank = $left[1]->pattern()->priority();
            $rightRank = $right[1]->pattern()->priority();
            $length = max(count($leftRank), count($rightRank));
            for ($index = 0; $index < $length; $index++) {
                $comparison = ($rightRank[$index] ?? 4) <=> ($leftRank[$index] ?? 4);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            $hostComparison = $right[1]->pattern()->hostPriority() <=> $left[1]->pattern()->hostPriority();
            return $hostComparison !== 0 ? $hostComparison : $left[0] <=> $right[0];
        });
        $host = RoutePattern::requestHost($request);
        $path = $request->path();
        $allowed = [];
        $headFallback = null;
        foreach ($normal as [, $route]) {
            $params = $route->pattern()->match($path, $host);
            if ($params === null) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (!in_array($method, $allowed, true)) {
                    $allowed[] = $method;
                }
            }
            if (in_array($request->method(), $route->methods(), true)) {
                return new MatchedRoute($route, $params);
            }
            if ($request->method() === 'HEAD' && in_array('GET', $route->methods(), true) && $headFallback === null) {
                $headFallback = new MatchedRoute($route, $params);
            }
        }
        if ($headFallback !== null) {
            return $headFallback;
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException('Method Not Allowed', ['Allow' => implode(', ', $allowed)]);
        }

        // Fallbacks are method-agnostic path prefixes. A 405 above always wins.
        usort($fallbacks, static function (array $left, array $right): int {
            $prefix = strlen($right[1]->uri()) <=> strlen($left[1]->uri());
            if ($prefix !== 0) {
                return $prefix;
            }
            $host = $right[1]->pattern()->hostPriority() <=> $left[1]->pattern()->hostPriority();
            return $host !== 0 ? $host : $left[0] <=> $right[0];
        });
        foreach ($fallbacks as [, $route]) {
            $params = $route->pattern()->match($path, $host);
            if ($params !== null) {
                return new MatchedRoute($route, $params);
            }
        }
        throw new NotFoundHttpException();
    }
}
