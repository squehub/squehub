<?php

declare(strict_types=1);

namespace App\Authorization\Middleware;

use App\Authorization\Authorization;
use App\Authorization\AuthorizationConfigurationException;
use App\Http\Request;
use App\Http\Response;
use Closure;

/** Protects a route with one global ability; resource checks stay in application code. */
final readonly class RequireAbility
{
    private function __construct(private string $ability)
    {
    }

    public static function named(string $ability): self
    {
        if (strlen($ability) > 128 || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $ability) !== 1) {
            throw new AuthorizationConfigurationException('Global ability name must be a bounded identifier.');
        }
        return new self($ability);
    }

    /** This metadata describes a known route guard, not a policy decision. */
    public function abilityName(): string { return $this->ability; }

    public function handle(Request $request, Closure $next): Response
    {
        Authorization::manager()->require($this->ability);
        return $next($request);
    }
}
