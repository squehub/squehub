<?php

declare(strict_types=1);

namespace App\Auth\Guards;

use App\Auth\Contracts\AuthGuard;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Tokens\TokenAuthentication;
use App\Auth\Tokens\TokenManager;
use App\Auth\Tokens\TokenMetadata;
use App\Http\Request;

/**
 * Resolves a Bearer credential through the configured token manager, without
 * consulting Session or retaining raw token material after authentication.
 * The manager and repository live for the Application; the result lives for
 * one Kernel request and is cleared before the next request begins.
 */
final class TokenGuard implements AuthGuard
{
    private ?Request $request = null;
    private ?TokenAuthentication $authentication = null;
    private bool $resolved = false;

    public function __construct(private TokenManager $tokens)
    {
    }

    /** @internal AuthManager binds the currently handled request. */
    public function bindRequest(?Request $request): void
    {
        $this->resetRequestState();
        $this->request = $request;
    }

    public function manager(): TokenManager { return $this->tokens; }

    public function user(): ?Authenticatable { return $this->authentication()?->identity(); }

    public function id(): int|string|null { return $this->user()?->authIdentifier(); }

    public function check(): bool { return $this->authentication() !== null; }

    public function guest(): bool { return !$this->check(); }

    /** Metadata exposes ability and lifecycle information, never the presented credential. */
    public function token(): ?TokenMetadata { return $this->authentication()?->metadata(); }

    public function resetRequestState(): void
    {
        $this->request = null;
        $this->authentication = null;
        $this->resolved = false;
    }

    private function authentication(): ?TokenAuthentication
    {
        if ($this->resolved) return $this->authentication;
        // A missing, duplicate, or malformed Authorization header is a guest.
        // The route boundary gives every such case the same public challenge.
        $raw = $this->request?->bearerToken();
        if ($raw === null) {
            $this->resolved = true;
            return null;
        }
        $authentication = $this->tokens->authenticate($raw);
        $this->authentication = $authentication;
        $this->resolved = true;
        return $authentication;
    }
}
