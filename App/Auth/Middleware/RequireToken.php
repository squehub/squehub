<?php

declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\Auth;
use App\Auth\AuthException;
use App\Auth\Guards\TokenGuard;
use App\Auth\TokenAuthenticationException;
use App\Http\Request;
use App\Http\Response;
use Closure;

/**
 * Selects one named token guard for the rest of this request. It never falls
 * back to a browser session when a Bearer credential is absent or invalid.
 */
final readonly class RequireToken
{
    private function __construct(private string $guard)
    {
    }

    public static function guard(string $name): self
    {
        if (strlen($name) > 64 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
            throw new AuthException('Token guard name must be an identifier.');
        }
        return new self($name);
    }

    /** Safe declarative metadata for contract checks; no credential is exposed. */
    public function guardName(): string { return $this->guard; }

    public function handle(Request $request, Closure $next): Response
    {
        $auth = Auth::manager();
        $guard = $auth->guard($this->guard);
        if (!$guard instanceof TokenGuard) {
            throw new AuthException('Required guard is not configured for API tokens.');
        }
        if (!$guard->check()) throw new TokenAuthenticationException();
        // Downstream auth()->user() and Gate checks now see precisely this
        // token's identity, irrespective of an accompanying session cookie.
        $auth->selectGuardForRequest($this->guard);
        return $next($request);
    }
}
