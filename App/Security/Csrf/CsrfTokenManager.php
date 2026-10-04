<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use App\Config\Repository;
use App\Session\SessionManager;
use InvalidArgumentException;

/**
 * Issues and verifies the session-bound synchronizer token.
 *
 * A token is stable until explicit rotation or session invalidation. Existing
 * v1 _token data is adopted once, then removed so internal metadata is the
 * only authoritative location. Verification never creates a fresh token.
 */
final class CsrfTokenManager
{
    public function __construct(private SessionManager $sessions, private ?Repository $config = null)
    {
    }

    public function token(): string
    {
        return $this->existingToken() ?? $this->rotate();
    }

    /** The template field follows the same configured name checked by middleware. */
    public function field(): string
    {
        $field = $this->config?->get('csrf.field', '_csrf') ?? '_csrf';
        if (!is_string($field) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $field) !== 1
            || $field === '_token') {
            throw new InvalidArgumentException('Invalid CSRF field configuration.');
        }
        return $field;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $store = $this->sessions->store();
        $store->setCsrfToken($token);
        $store->forget('_token');
        return $token;
    }

    public function verify(mixed $submitted): bool
    {
        $expected = $this->existingToken();
        if ($expected === null || !is_string($submitted)
            || preg_match('/\A[0-9a-f]{64}\z/D', $submitted) !== 1) {
            return false;
        }
        // Compare same-shape strings in constant time; never expose either
        // value through an exception or a response.
        return hash_equals($expected, $submitted);
    }

    /** Move a valid pre-v2 token out of the public application key once. */
    public function migrateLegacy(): void
    {
        $this->existingToken();
    }

    private function existingToken(): ?string
    {
        $store = $this->sessions->store();
        $internal = $store->csrfToken();
        if ($internal !== null && preg_match('/\A[0-9a-f]{64}\z/D', $internal) !== 1) {
            $internal = null;
        }
        if ($store->has('_token')) {
            $legacy = $store->pull('_token');
            if ($internal === null && is_string($legacy)
                && preg_match('/\A[0-9a-f]{64}\z/D', $legacy) === 1) {
                $store->setCsrfToken($legacy);
                return $legacy;
            }
        }
        return $internal;
    }
}
