<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use App\AccountSecurity\Contracts\EmailVerificationIdentityProvider;
use App\AccountSecurity\Repositories\ArraySecurityTokenRepository;
use App\AccountSecurity\Repositories\DatabaseSecurityTokenRepository;
use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Guards\SessionGuard;
use App\Auth\PasswordHasher;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Schema\Table;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use DateTimeImmutable;
use Throwable;

/**
 * Coordinates credential transitions and one-time security tokens. Tokens are
 * claimed before account writes; a failed write consumes the token so it
 * cannot be replayed, and the application may issue a fresh one after repair.
 * Delivery, rate limiting, and security audit logging belong to separate
 * systems; this service records only aggregate diagnostics.
 */
final class AccountSecurityManager
{
    private const RESET = 'password_reset';
    private const VERIFY = 'email_verification';
    private SecurityTokenRepository $tokens;
    private ModelClock $clock;
    private int $resetTtl;
    private int $verificationTtl;

    public function __construct(
        private AuthManager $auth,
        private PasswordHasher $passwords,
        private DatabaseManager $database,
        Repository $config,
        private ?Diagnostics $diagnostics = null,
        ?SecurityTokenRepository $tokens = null,
        ?SecurityTokenGenerator $generator = null,
        ?ModelClock $clock = null
    ) {
        // Config/Loader.php exposes AccountSecurity.php as accountSecurity.
        $settings = $config->get('accountSecurity', []);
        if (!is_array($settings)) throw new AccountSecurityConfigurationException('Account security configuration must be a map.');
        if (array_key_exists('tokens', $settings) && !is_array($settings['tokens'])) {
            throw new AccountSecurityConfigurationException('Security token repository settings must be a map.');
        }
        $tokenSettings = $settings['tokens'] ?? [];
        $driver = array_key_exists('driver', $tokenSettings) ? $tokenSettings['driver'] : 'database';
        $table = array_key_exists('table', $tokenSettings) ? $tokenSettings['table'] : 'account_security_tokens';
        if (!is_string($driver) || !in_array($driver, ['database', 'array'], true)
            || !is_string($table)) {
            throw new AccountSecurityConfigurationException('Invalid security token repository configuration.');
        }
        try { Table::name($table); } catch (Throwable $failure) {
            throw new AccountSecurityConfigurationException('Invalid security token table name.', 0, $failure);
        }
        $this->resetTtl = $this->ttl($settings, 'password_reset', 3600);
        $this->verificationTtl = $this->ttl($settings, 'email_verification', 86400);
        $this->tokens = $tokens ?? ($driver === 'array' ? new ArraySecurityTokenRepository()
            : new DatabaseSecurityTokenRepository($database, $table));
        $this->generator = $generator ?? new SecurityTokenGenerator();
        $this->clock = $clock ?? $database->clock();
    }

    private SecurityTokenGenerator $generator;

    private function ttl(array $settings, string $purpose, int $default): int
    {
        if (array_key_exists($purpose, $settings) && !is_array($settings[$purpose])) {
            throw new AccountSecurityConfigurationException('Account security token settings must be a map.');
        }
        $purposeSettings = $settings[$purpose] ?? [];
        $ttl = array_key_exists('ttl', $purposeSettings) ? $purposeSettings['ttl'] : $default;
        if (!is_int($ttl) || $ttl < 1 || $ttl > 31536000) {
            throw new AccountSecurityConfigurationException('Account security token TTL must be positive seconds.');
        }
        return $ttl;
    }

    public function forGuard(string $guard): AccountSecurityContext
    {
        $this->guard($guard); // Validate without reading or changing Session state.
        return new AccountSecurityContext($this, $guard);
    }

    private function defaultName(): string
    {
        return $this->auth->defaultGuardName()
            ?? throw new AccountSecurityConfigurationException('No default authentication guard is configured.');
    }

    private function guard(string $name): SessionGuard
    {
        try { $guard = $this->auth->guard($name); } catch (Throwable $failure) {
            throw new AccountSecurityConfigurationException('Account security guard is not configured.', 0, $failure);
        }
        if (!$guard instanceof SessionGuard) {
            throw new AccountSecurityConfigurationException('Account security requires a supported credential guard.');
        }
        return $guard;
    }

    public function changePassword(#[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword): bool
    {
        return $this->changePasswordFor($this->defaultName(), $currentPassword, $newPassword);
    }

    /** Keep the current session authenticated only after both the write and rotation succeed. */
    public function changePasswordFor(string $name, #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword): bool
    {
        try {
            $guard = $this->guard($name);
            $identity = $guard->user();
            if ($identity === null) throw new AccountSecurityException('Authentication is required to change a password.');
            $hash = $identity->authPasswordHash();
            if (!$this->passwords->verify($currentPassword, $hash)) {
                $this->diagnostics?->accountSecurity('password_change_failures');
                return false;
            }
            $this->newPassword($newPassword, $hash);
            $guard->identityProvider()->updatePassword($identity, $this->passwords->hash($newPassword));
            $guard->refreshCredential($identity);
            // The credential digest already makes old tokens invalid; revocation
            // removes their rows as defense in depth and may surface DB failure.
            $this->tokens->revoke(self::RESET, $name, SecurityTokenRecord::key($identity->authIdentifier()));
            $this->diagnostics?->accountSecurity('password_changes');
            return true;
        } catch (Throwable $failure) {
            $this->diagnostics?->accountSecurity('errors');
            throw $failure;
        }
    }

    private function newPassword(#[\SensitiveParameter] string $password, string $oldHash): void
    {
        if ($password === '') throw new AccountSecurityException('New password must not be empty.');
        if ($this->passwords->verify($password, $oldHash)) {
            throw new AccountSecurityException('New password must differ from the current password.');
        }
        if (!$this->passwords->accepts($password)) {
            throw new AccountSecurityException('Password exceeds the configured technical limit.');
        }
    }

    /** @param array<string, int|string> $credentials */
    public function issuePasswordReset(array $credentials): ?SecurityToken
    {
        return $this->issuePasswordResetFor($this->defaultName(), $credentials);
    }

    /** @param array<string, int|string> $credentials */
    public function issuePasswordResetFor(string $name, array $credentials): ?SecurityToken
    {
        try {
            $provider = $this->guard($name)->identityProvider();
            if (array_key_exists('password', $credentials)) {
                throw new AccountSecurityConfigurationException('Password is not a reset lookup credential.');
            }
            $identity = $provider->retrieveByCredentials($credentials);
            if ($identity === null) return null;
            if (!$provider->supports($identity)) {
                throw new AccountSecurityConfigurationException('Identity provider returned an incompatible identity.');
            }
            $token = $this->issue(self::RESET, $name, $identity,
                CredentialFingerprint::fromHash($identity->authPasswordHash()), $this->resetTtl);
            $this->diagnostics?->accountSecurity('reset_tokens_issued');
            return $token;
        } catch (Throwable $failure) {
            $this->diagnostics?->accountSecurity('errors');
            throw $failure;
        }
    }

    public function resetPassword(#[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $newPassword): bool
    {
        return $this->resetPasswordFor($this->defaultName(), $token, $newPassword);
    }

    /** Token claim precedes identity lookup and mutation, preserving one-time use on failure. */
    public function resetPasswordFor(string $name, #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $newPassword): bool
    {
        try {
            $provider = $this->guard($name)->identityProvider();
            $record = $this->claim($token, self::RESET, $name);
            if ($record === null) return false;
            $identity = $provider->retrieveById($record->identifier());
            if ($identity === null || !$provider->supports($identity)
                || !hash_equals($record->contextHash, CredentialFingerprint::fromHash($identity->authPasswordHash()))) {
                return false;
            }
            $this->newPassword($newPassword, $identity->authPasswordHash());
            $provider->updatePassword($identity, $this->passwords->hash($newPassword));
            $this->tokens->revoke(self::RESET, $name, $record->identityKey);
            $this->diagnostics?->accountSecurity('password_resets');
            return true;
        } catch (Throwable $failure) {
            $this->diagnostics?->accountSecurity('errors');
            throw $failure;
        }
    }

    public function issueEmailVerification(Authenticatable $identity): ?SecurityToken
    {
        return $this->issueEmailVerificationFor($this->defaultName(), $identity);
    }

    /** Exact provider address is hashed into context; address changes invalidate the token. */
    public function issueEmailVerificationFor(string $name, Authenticatable $identity): ?SecurityToken
    {
        try {
            $provider = $this->verificationProvider($name);
            if (!$provider->supports($identity)) {
                throw new AccountSecurityConfigurationException('Identity is not supported by this guard.');
            }
            // Explicit identity objects may be stale. Read the provider's
            // current row before binding a token to its address and status.
            $identity = $provider->retrieveById($identity->authIdentifier());
            if ($identity === null || !$provider->supports($identity)) return null;
            if ($provider->emailVerified($identity)) return null;
            $address = $provider->verificationAddress($identity);
            $token = $this->issue(self::VERIFY, $name, $identity, hash('sha256', $address), $this->verificationTtl);
            $this->diagnostics?->accountSecurity('verification_tokens_issued');
            return $token;
        } catch (Throwable $failure) {
            $this->diagnostics?->accountSecurity('errors');
            throw $failure;
        }
    }

    public function verifyEmail(#[\SensitiveParameter] string $token): bool
    {
        return $this->verifyEmailFor($this->defaultName(), $token);
    }

    public function verifyEmailFor(string $name, #[\SensitiveParameter] string $token): bool
    {
        try {
            $provider = $this->verificationProvider($name);
            $record = $this->claim($token, self::VERIFY, $name);
            if ($record === null) return false;
            $identity = $provider->retrieveById($record->identifier());
            if ($identity === null || !$provider->supports($identity)
                || !hash_equals($record->contextHash, hash('sha256', $provider->verificationAddress($identity)))
                || $provider->emailVerified($identity)) return false;
            $provider->markEmailVerified($identity, $this->clock->now());
            $this->tokens->revoke(self::VERIFY, $name, $record->identityKey);
            $this->diagnostics?->accountSecurity('email_verifications');
            return true;
        } catch (Throwable $failure) {
            $this->diagnostics?->accountSecurity('errors');
            throw $failure;
        }
    }

    private function verificationProvider(string $name): EmailVerificationIdentityProvider
    {
        $provider = $this->guard($name)->identityProvider();
        if (!$provider instanceof EmailVerificationIdentityProvider) {
            throw new AccountSecurityConfigurationException('Identity provider does not support email verification.');
        }
        return $provider;
    }

    private function issue(string $purpose, string $name, Authenticatable $identity,
        string $contextHash, int $ttl): SecurityToken
    {
        $now = $this->clock->now();
        $expires = $now->modify('+' . $ttl . ' seconds');
        $plain = $this->generator->generate();
        $record = new SecurityTokenRecord(hash('sha256', $plain), $purpose, $name,
            SecurityTokenRecord::key($identity->authIdentifier()), $contextHash, $expires, $now);
        $this->tokens->replace($record);
        return new SecurityToken($plain, $expires);
    }

    private function claim(#[\SensitiveParameter] string $plain, string $purpose, string $name): ?SecurityTokenRecord
    {
        if (!SecurityTokenGenerator::valid($plain)) return null;
        return $this->tokens->claim(hash('sha256', $plain), $purpose, $name, $this->clock->now());
    }
}
