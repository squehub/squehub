<?php

declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\Auth;
use App\Auth\TokenAuthenticationException;
use App\Auth\Tokens\TokenRecord;
use App\Authorization\AuthorizationException;
use App\Http\Request;
use App\Http\Response;
use Closure;
use InvalidArgumentException;

/**
 * Narrows a token-authenticated route with one explicit token ability.
 * Application Gate or policy authorization remains a separate, additional
 * decision; token possession never grants an otherwise forbidden action.
 */
final readonly class RequireTokenAbility
{
    private function __construct(private string $ability)
    {
    }

    public static function named(string $ability): self
    {
        if (!TokenRecord::validAbility($ability)) {
            throw new InvalidArgumentException('Token ability must be a bounded identifier.');
        }
        return new self($ability);
    }

    /** The declared ability can be compared without invoking authorization. */
    public function abilityName(): string { return $this->ability; }

    public function handle(Request $request, Closure $next): Response
    {
        $auth = Auth::manager();
        if ($auth->token() === null) throw new TokenAuthenticationException();
        if (!$auth->tokenAllows($this->ability)) throw new AuthorizationException();
        return $next($request);
    }
}
