<?php

declare(strict_types=1);

namespace App\Auth\Guards;

use App\Auth\AuthException;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Auth\Contracts\PrimaryAuthenticationGate;
use App\Auth\Contracts\StatefulAuthGuard;
use App\Auth\PasswordHasher;
use App\Auth\Remember\RememberCredential;
use App\Auth\Remember\RememberManager;
use App\AccountSecurity\CredentialFingerprint;
use App\Diagnostics\Diagnostics;
use App\Session\SessionStore;
use App\Http\Request;
use App\Http\Cookie;
use Closure;
use Throwable;

/** Keeps a guard-specific ID and credential digest in private session metadata. */
final class SessionGuard implements StatefulAuthGuard
{
    private bool $resolved = false;
    private ?Authenticatable $identity = null;

    /** @param list<string> $allowedCredentials */
    public function __construct(
        private string $name,
        private IdentityProvider $provider,
        private SessionStore $session,
        private PasswordHasher $hasher,
        private array $allowedCredentials,
        private bool $rehashOnLogin,
        private ?Diagnostics $diagnostics = null,
        private ?Closure $onLogout = null,
        private ?RememberManager $remember = null,
        private ?PrimaryAuthenticationGate $primaryGate = null
    ) {
    }

    /** A resolved identity is cached only until the next Kernel request begins. */
    public function user(): ?Authenticatable
    {
        // A previous guard may have cached an identity earlier in this request.
        // Pending MFA is whole-session and takes precedence even over that cache.
        if ($this->session->pendingMfa() !== null) {
            $this->resetRequestState();
            return null;
        }
        if ($this->resolved) return $this->identity;
        $identifier = $this->session->authIdentifier($this->name);
        if ($identifier === null) {
            $this->resolved = true;
            if ($this->remember === null) return null;
            try {
                $recalled = $this->remember->recall($this->provider);
                if ($recalled === null) return null;
                try {
                    if ($this->acceptPrimary($recalled->identity, 'remember', true)) {
                        $this->remember->publish($recalled->credential);
                    } else {
                        // A later MFA stage can own the pending request. The
                        // old selector has been consumed; no cookie is issued.
                        $this->remember->discard($recalled->credential);
                    }
                } catch (Throwable $failure) {
                    $this->remember->discard($recalled->credential);
                    throw $failure;
                }
                return $this->identity;
            } catch (Throwable $exception) {
                $this->diagnostics?->auth('errors');
                throw $exception;
            }
        }
        $fingerprint = $this->session->authFingerprint($this->name);
        if ($fingerprint === null) {
            // Identifier-only sessions have not proven which credential state
            // authenticated them. Require a fresh login instead of upgrading.
            $this->session->forgetAuthIdentifier($this->name);
            $this->resetRequestState();
            $this->resolved = true;
            return null;
        }
        try {
            $identity = $this->provider->retrieveById($identifier);
            if ($identity !== null && !$this->provider->supports($identity)) {
                throw new AuthException('Identity provider returned an incompatible identity.');
            }
            if ($identity !== null && (string) $identity->authIdentifier() !== (string) $identifier) {
                throw new AuthException('Identity provider returned the wrong identifier.');
            }
            if ($identity === null) {
                // A removed or soft-deleted account cannot remain authenticated.
                $this->session->forgetAuthIdentifier($this->name);
            } elseif (!hash_equals($fingerprint, CredentialFingerprint::fromHash($identity->authPasswordHash()))) {
                // Other sessions become guests lazily after a credential write.
                $this->session->forgetAuthIdentifier($this->name);
                $identity = null;
            }
            $this->identity = $identity;
            $this->resolved = true;
            return $identity;
        } catch (Throwable $exception) {
            $this->diagnostics?->auth('errors');
            throw $exception;
        }
    }

    public function id(): int|string|null { return $this->user()?->authIdentifier(); }
    public function check(): bool { return $this->user() !== null; }
    public function guest(): bool { return !$this->check(); }

    public function resetRequestState(): void
    {
        $this->resolved = false;
        $this->identity = null;
    }

    /** @internal AuthManager binds the validated request after Kernel mount resolution. */
    public function beginRequest(Request $request): void { $this->remember?->beginRequest($request); }

    /** @internal Drop response-bound cookie state before every Kernel handling attempt. */
    public function clearRequestState(): void
    {
        $this->resetRequestState();
        $this->remember?->clearRequest();
    }

    /** @internal Attach at the common Response boundary, including redirects. */
    public function pendingRememberCookie(): ?Cookie { return $this->remember?->pendingCookie(); }

    /** Forget only this browser's credential for this named guard. */
    public function forgetRemembered(): void { $this->remember?->forgetCurrent(); }

    /** Explicit all-device revocation for one guard and identity. */
    public function revokeRemembered(Authenticatable $identity): void
    {
        if (!$this->provider->supports($identity)) throw new AuthException('Identity is not supported by this guard.');
        $this->remember?->revokeIdentity($identity);
    }

    /**
     * @internal Called after an independently verified MFA challenge has used
     * refreshCredential() to establish the full Session. No pending challenge
     * or primary factor alone can use this to become authenticated.
     */
    public function rememberAuthenticated(Authenticatable $identity): void
    {
        $current = $this->user();
        if ($current === null || !$this->provider->supports($identity)
            || (string) $current->authIdentifier() !== (string) $identity->authIdentifier()
            || !hash_equals(CredentialFingerprint::fromHash($current->authPasswordHash()),
                CredentialFingerprint::fromHash($identity->authPasswordHash()))) {
            throw new AuthException('A fully authenticated identity is required to remember this browser.');
        }
        $issued = $this->rememberManager()->issue($current);
        $this->rememberManager()->publish($issued);
    }

    /** @internal AccountSecurity uses the guard's configured provider, never a second lookup path. */
    public function identityProvider(): IdentityProvider { return $this->provider; }

    /**
     * @internal After a password write, rotate and retain this guard on the new
     * credential state. If rotation fails, clear old auth metadata best-effort.
     */
    public function refreshCredential(Authenticatable $identity): void
    {
        if (!$this->provider->supports($identity)) throw new AuthException('Identity is not supported by this guard.');
        try {
            $this->session->regenerate();
            $this->session->setAuthIdentity($this->name, $identity->authIdentifier(),
                CredentialFingerprint::fromHash($identity->authPasswordHash()));
            $this->identity = $identity;
            $this->resolved = true;
        } catch (Throwable $failure) {
            try { $this->session->forgetAuthIdentifier($this->name); } catch (Throwable) {}
            $this->resetRequestState();
            throw $failure;
        }
    }

    /** @param array<string, mixed> $credentials */
    public function attempt(#[\SensitiveParameter] array $credentials, bool $remember = false): bool
    {
        $this->remember?->cancelPendingIssue();
        $this->diagnostics?->auth('attempts');
        try {
            foreach (array_keys($credentials) as $key) {
                if (!is_string($key) || ($key !== 'password' && !in_array($key, $this->allowedCredentials, true))) {
                    throw new AuthException('Authentication credential key is not configured.');
                }
            }
            $plain = $credentials['password'] ?? null;
            unset($credentials['password']);
            if (!is_string($plain) || $plain === '' || !$this->hasher->accepts($plain) || $credentials === []) {
                $this->diagnostics?->auth('failures');
                return false;
            }
            foreach ($credentials as $value) {
                if ((!is_string($value) && !is_int($value)) || $value === '') {
                    $this->diagnostics?->auth('failures');
                    return false;
                }
            }
            $identity = $this->provider->retrieveByCredentials($credentials);
            if ($identity === null) {
                $this->hasher->verifyDummy($plain);
                $this->diagnostics?->auth('failures');
                return false;
            }
            if (!$this->provider->supports($identity)) {
                throw new AuthException('Identity provider returned an incompatible identity.');
            }
            $hash = $identity->authPasswordHash();
            if (!$this->hasher->verify($plain, $hash)) {
                $this->diagnostics?->auth('failures');
                return false;
            }
            if ($this->rehashOnLogin && $this->hasher->needsRehash($hash)) {
                $replacementHash = $this->hasher->hash($plain);
                $this->provider->updatePassword($identity, $replacementHash);
                // Model providers update the object in place. Other providers
                // may return a new object, so never issue a stale fingerprint.
                if (!hash_equals($replacementHash, $identity->authPasswordHash())) {
                    $identity = $this->provider->retrieveById($identity->authIdentifier())
                        ?? throw new AuthException('Identity disappeared after credential rehash.');
                    if (!$this->provider->supports($identity)
                        || !hash_equals($replacementHash, $identity->authPasswordHash())) {
                        throw new AuthException('Identity credential rehash was not confirmed.');
                    }
                }
            }
            $issued = $remember ? $this->rememberManager()->issue($identity) : null;
            try {
                $authenticated = $this->acceptPrimary($identity, 'password', $remember);
            } catch (Throwable $failure) {
                if ($issued !== null) $this->rememberManager()->discard($issued);
                throw $failure;
            }
            if ($issued !== null) {
                if ($authenticated) $this->rememberManager()->publish($issued);
                else $this->rememberManager()->discard($issued);
            }
            $this->diagnostics?->auth('successes');
            if ($authenticated) $this->diagnostics?->auth('logins');
            return $authenticated;
        } catch (Throwable $exception) {
            $this->diagnostics?->auth('errors');
            throw $exception;
        }
    }

    public function login(Authenticatable $identity, bool $remember = false): void
    {
        try {
            $this->remember?->cancelPendingIssue();
            $this->assertSupported($identity);
            $issued = $remember ? $this->rememberManager()->issue($identity) : null;
            try {
                $authenticated = $this->acceptPrimary($identity, 'login', $remember);
            } catch (Throwable $failure) {
                if ($issued !== null) $this->rememberManager()->discard($issued);
                throw $failure;
            }
            if ($issued !== null) {
                if ($authenticated) $this->rememberManager()->publish($issued);
                else $this->rememberManager()->discard($issued);
            }
            if ($authenticated) $this->diagnostics?->auth('logins');
        } catch (Throwable $exception) {
            $this->diagnostics?->auth('errors');
            throw $exception;
        }
    }

    private function rememberManager(): RememberManager
    {
        return $this->remember ?? throw new AuthException('Remember-me is not enabled for this guard.');
    }

    /**
     * The single primary-factor adoption seam for password, explicit login,
     * and remember. MFA may return false after creating a pending challenge.
     */
    private function acceptPrimary(Authenticatable $identity, string $source,
        bool $rememberRequested): bool
    {
        if ($this->primaryGate !== null
            && !$this->primaryGate->begin($identity, $this->name, $source, $rememberRequested)) return false;
        $this->establish($identity);
        return true;
    }

    private function assertSupported(Authenticatable $identity): void
    {
        if (!$this->provider->supports($identity)) throw new AuthException('Identity is not supported by this guard.');
        $identifier = $identity->authIdentifier();
        if ((is_string($identifier) && $identifier === '') || (is_int($identifier) && $identifier < 0)) {
            throw new AuthException('Identity has no usable authentication identifier.');
        }
    }

    /** Rotation happens before writing an identifier, so a failed rotation cannot log in. */
    private function establish(Authenticatable $identity): void
    {
        $this->assertSupported($identity);
        $identifier = $identity->authIdentifier();
        $this->session->regenerate();
        $this->session->setAuthIdentity($this->name, $identifier,
            CredentialFingerprint::fromHash($identity->authPasswordHash()));
        $this->identity = $identity;
        $this->resolved = true;
    }

    /** Invalidation is whole-session: all named guard IDs, flash, and CSRF are cleared. */
    public function logout(): void
    {
        try {
            $this->session->invalidate();
            $this->onLogout ? ($this->onLogout)() : $this->resetRequestState();
            $this->diagnostics?->auth('logouts');
        } catch (Throwable $exception) {
            $this->diagnostics?->auth('errors');
            throw $exception;
        }
    }
}
