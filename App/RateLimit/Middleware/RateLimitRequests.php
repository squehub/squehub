<?php

declare(strict_types=1);

namespace App\RateLimit\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimitExceededException;
use App\RateLimit\RateLimitKey;
use Closure;

/**
 * Consumes one named permit at its explicit route position. Global CSRF and
 * earlier route guards can reject before consumption; no IP key is inferred.
 */
final readonly class RateLimitRequests
{
    private function __construct(private string $name) {}

    public static function named(string $name): self
    {
        RateLimitKey::bucket($name);
        return new self($name);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $result = RateLimit::manager()->consumeNamed($this->name, $request);
        if ($result->denied()) throw new RateLimitExceededException($result);
        $response = $next($request);
        // The framework's consumed permit is authoritative over any controller
        // values for these three response headers.
        return $response->withHeader('X-RateLimit-Limit', (string) $result->limit())
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining())
            ->withHeader('X-RateLimit-Reset', (string) $result->resetsAt()->getTimestamp());
    }
}
