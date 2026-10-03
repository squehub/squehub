<?php

declare(strict_types=1);

namespace App\Mfa;

use App\AccountSecurity\CredentialFingerprint;
use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\PrimaryAuthenticationGate;
use App\Auth\Guards\SessionGuard;
use App\Config\Repository;
use App\Container\Container;
use App\Cryptography\CryptManager;
use App\Database\DatabaseManager;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Database\SystemModelClock;
use App\Mfa\Repositories\ArrayMfaRepository;
use App\Mfa\Repositories\DatabaseMfaRepository;
use App\Mfa\Repositories\MfaIdentity;
use App\Mfa\Repositories\MfaRepository;
use App\RateLimit\RateLimiter;
use App\Session\SessionManager;
use App\Session\SessionStore;
use Throwable;

/** Coordinates optional TOTP enrollment and the primary-to-full-session transition. */
final class MfaManager implements PrimaryAuthenticationGate
{
    /** @var array<string,mixed> */
    private array $settings;
    private ?MfaRepository $repository = null;
    private ?ModelClock $clock = null;

    public function __construct(private Container $container, ?MfaRepository $repository = null)
    {
        $settings = $container->make(Repository::class)->get('security.mfa', []);
        if (!is_array($settings)) throw new MfaConfigurationException('MFA configuration must be a map.');
        $known = ['enabled', 'driver', 'credentials_table', 'recovery_table', 'connection',
            'issuer', 'enrollment_ttl', 'challenge_ttl', 'skew', 'recovery_count',
            'max_attempts', 'attempt_window'];
        if (array_diff(array_keys($settings), $known) !== []) {
            throw new MfaConfigurationException('MFA configuration has an unknown setting.');
        }
        $settings += [
            'enabled' => false, 'driver' => 'database', 'credentials_table' => 'mfa_credentials',
            'recovery_table' => 'mfa_recovery_codes', 'connection' => null, 'issuer' => 'SqueHub',
            'enrollment_ttl' => 600, 'challenge_ttl' => 300, 'skew' => 1,
            'recovery_count' => 10, 'max_attempts' => 5, 'attempt_window' => 300,
        ];
        if (!is_bool($settings['enabled']) || !in_array($settings['driver'], ['database', 'array'], true)
            || !is_string($settings['credentials_table']) || !is_string($settings['recovery_table'])
            || ($settings['connection'] !== null && (!is_string($settings['connection'])
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $settings['connection']) !== 1))
            || !is_string($settings['issuer']) || $settings['issuer'] === ''
            || strlen($settings['issuer']) > 100 || preg_match('/[\x00-\x1F\x7F:]/', $settings['issuer'])
            || preg_match('//u', $settings['issuer']) !== 1
            || !self::boundedInt($settings['enrollment_ttl'], 30, 3600)
            || !self::boundedInt($settings['challenge_ttl'], 30, 900)
            || !self::boundedInt($settings['skew'], 0, 1)
            || !self::boundedInt($settings['recovery_count'], 1, 20)
            || !self::boundedInt($settings['max_attempts'], 1, 20)
            || !self::boundedInt($settings['attempt_window'], 30, 3600)) {
            throw new MfaConfigurationException('MFA configuration is invalid.');
        }
        try {
            Table::name($settings['credentials_table']);
            Table::name($settings['recovery_table']);
        } catch (Throwable $failure) {
            throw new MfaConfigurationException('MFA table name is invalid.', 0, $failure);
        }
        $this->settings = $settings;
        $this->repository = $repository;
    }

    private static function boundedInt(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }

    public function isEnabled(): bool { return $this->settings['enabled']; }

    /**
     * Called by every session guard after its primary factor succeeds. An
     * enabled enrollment turns that result into a bounded guest challenge.
     */
    public function begin(Authenticatable $identity, string $guard, string $source,
        bool $rememberRequested): bool
    {
        if (!$this->isEnabled()) {
            // A deployment may disable MFA while a browser still holds an old
            // pending challenge. A fresh primary login replaces that state.
            if ($this->session()->pendingMfa() !== null) {
                $this->session()->invalidate();
                $this->auth()->clearSessionGuardIdentityCaches();
            }
            return true;
        }
        if (!in_array($source, ['password', 'login', 'remember'], true)) {
            throw new MfaException('Unsupported primary authentication source.');
        }
        $scope = MfaIdentity::from($guard, $identity);
        $record = $this->repository()->find($scope);
        $session = $this->session();
        if ($record === null || !$record['enabled']) {
            // A new login without MFA may replace a different pending identity.
            if ($session->pendingMfa() !== null) {
                $session->invalidate();
                $this->auth()->clearSessionGuardIdentityCaches();
            }
            return true;
        }
        $now = $this->now();
        $pending = [
            'guard' => $guard,
            'class' => get_class($identity),
            'identifier' => $identity->authIdentifier(),
            'scope_digest' => $scope->digest,
            'credential_fingerprint' => CredentialFingerprint::fromHash($identity->authPasswordHash()),
            'expires_at' => $now + $this->settings['challenge_ttl'],
            'remember_requested' => $rememberRequested,
        ];
        // Invalidation rotates the Session ID and removes every prior guard ID.
        $session->invalidate();
        $this->auth()->clearSessionGuardIdentityCaches();
        $session->setPendingMfa($pending);
        return false;
    }

    public function forGuard(string $name): MfaContext
    {
        $this->guard($name);
        return new MfaContext($this, $name);
    }

    public function pending(): bool
    {
        return $this->livePending() !== null;
    }

    public function pendingFor(string $guard): bool
    {
        return ($this->livePending()['guard'] ?? null) === $guard;
    }

    public function enabledFor(string $guard): bool
    {
        if (!$this->isEnabled()) return false;
        $identity = $this->authenticated($guard);
        return ($this->repository()->find(MfaIdentity::from($guard, $identity))['enabled'] ?? false) === true;
    }

    public function beginEnrollmentFor(string $guard, string $accountLabel): MfaEnrollment
    {
        $this->required();
        $identity = $this->authenticated($guard);
        if ($accountLabel === '' || strlen($accountLabel) > 254
            || preg_match('/[\x00-\x1F\x7F:]/', $accountLabel)
            || preg_match('//u', $accountLabel) !== 1) {
            throw new MfaException('TOTP account label is invalid.');
        }
        $scope = MfaIdentity::from($guard, $identity);
        if (($this->repository()->find($scope)['enabled'] ?? false) === true) {
            throw new MfaException('MFA is already enabled for this identity.');
        }
        $secret = Totp::generateSecret();
        $ciphertext = $this->crypt()->encrypt($secret, $this->purpose($scope));
        $expiry = $this->now() + $this->settings['enrollment_ttl'];
        $this->repository()->savePending($scope, $ciphertext, $expiry);
        $issuer = $this->settings['issuer'];
        $uri = 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountLabel)
            . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
        return new MfaEnrollment($secret, $uri, $expiry);
    }

    /** @return list<string>|null Enrollment becomes active only after a current code proves setup. */
    public function confirmEnrollmentFor(string $guard, #[\SensitiveParameter] string $code): ?array
    {
        $this->required();
        $identity = $this->authenticated($guard);
        $scope = MfaIdentity::from($guard, $identity);
        $record = $this->repository()->find($scope);
        $now = $this->now();
        if ($record === null || $record['enabled'] || !is_string($record['pending_secret'])
            || !is_int($record['pending_expires_at']) || $record['pending_expires_at'] <= $now) return null;
        if (!$this->allowed('enrollment', $scope)) return null;
        $secret = $this->crypt()->decrypt($record['pending_secret'], $this->purpose($scope));
        $counter = Totp::match($secret, $code, $now, $this->settings['skew']);
        if ($counter === null) return null;
        [$codes, $hashes] = $this->recoveryCodes();
        if (!$this->repository()->activate($scope, $record['pending_secret'], $counter, $hashes, $now)) {
            return null;
        }
        $this->clearLimit('enrollment', $scope);
        return $codes;
    }

    public function completeChallenge(#[\SensitiveParameter] string $proof): bool
    {
        $guard = $this->auth()->defaultGuardName();
        if ($guard === null) throw new MfaConfigurationException('No default authentication guard is configured.');
        return $this->completeChallengeFor($guard, $proof);
    }

    public function completeChallengeFor(string $guard, #[\SensitiveParameter] string $proof): bool
    {
        $this->required();
        $pending = $this->livePending();
        if ($pending === null || $pending['guard'] !== $guard) return false;
        $session = $this->session();
        $authGuard = $this->guard($guard);
        $identifier = $pending['identifier'];
        $provider = $authGuard->identityProvider();
        $identity = $provider->retrieveById($identifier);
        if ($identity === null || !$provider->supports($identity)
            || get_class($identity) !== $pending['class']
            || !hash_equals($pending['credential_fingerprint'],
                CredentialFingerprint::fromHash($identity->authPasswordHash()))) {
            $this->abandon();
            return false;
        }
        $scope = MfaIdentity::from($guard, $identity);
        // PDO may rehydrate an integer primary key as its canonical decimal
        // string; the repository's framed scope normalizes only that form.
        $pendingScope = MfaIdentity::fromIdentifier($guard, $pending['class'], $identifier);
        if (!hash_equals($pending['scope_digest'], $pendingScope->digest)
            || !hash_equals($pendingScope->digest, $scope->digest)) {
            $this->abandon();
            return false;
        }
        $record = $this->repository()->find($scope);
        if ($record === null || !$record['enabled'] || !is_string($record['encrypted_secret'])) {
            $this->abandon();
            return false;
        }
        $secretHash = hash('sha256', $record['encrypted_secret']);
        if (!$this->allowed('challenge', $scope)
            || !$this->consumeProof($scope, $record['encrypted_secret'], $proof)) return false;
        $latest = $this->repository()->find($scope);
        if ($latest === null || !$latest['enabled'] || !is_string($latest['encrypted_secret'])
            || !hash_equals($secretHash, hash('sha256', $latest['encrypted_secret']))) {
            $this->abandon();
            return false;
        }

        // A consumed proof cannot be reused if Session rotation fails. Clear
        // pending state first; refreshCredential() leaves a guest on failure.
        $session->clearPendingMfa();
        $authGuard->refreshCredential($identity);
        if ($pending['remember_requested']) $authGuard->rememberAuthenticated($identity);
        $this->clearLimit('challenge', $scope);
        return true;
    }

    /** @return list<string>|null Replacing the set invalidates every previous unused code. */
    public function regenerateRecoveryCodesFor(string $guard, #[\SensitiveParameter] string $proof): ?array
    {
        $this->required();
        $identity = $this->authenticated($guard);
        $scope = MfaIdentity::from($guard, $identity);
        $record = $this->repository()->find($scope);
        if ($record === null || !$record['enabled'] || !is_string($record['encrypted_secret'])
            || !$this->allowed('manage', $scope)
            || !$this->consumeProof($scope, $record['encrypted_secret'], $proof)) return null;
        [$codes, $hashes] = $this->recoveryCodes();
        if (!$this->repository()->replaceRecoveryCodes($scope, $hashes,
            hash('sha256', $record['encrypted_secret']))) return null;
        $this->clearLimit('manage', $scope);
        return $codes;
    }

    /** An application endpoint must also protect this call with CSRF and its account policy. */
    public function disableFor(string $guard, #[\SensitiveParameter] string $proof): bool
    {
        $this->required();
        $identity = $this->authenticated($guard);
        $scope = MfaIdentity::from($guard, $identity);
        $record = $this->repository()->find($scope);
        if ($record === null || !$record['enabled'] || !is_string($record['encrypted_secret'])
            || !$this->allowed('manage', $scope)
            || !$this->consumeProof($scope, $record['encrypted_secret'], $proof)) return false;
        if (!$this->repository()->disable($scope, hash('sha256', $record['encrypted_secret']))) {
            return false;
        }
        $this->clearLimit('manage', $scope);
        return true;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function recoveryCodes(): array
    {
        $codes = [];
        $hashes = [];
        for ($index = 0; $index < $this->settings['recovery_count']; $index++) {
            $raw = strtoupper(bin2hex(random_bytes(16)));
            $codes[] = implode('-', str_split($raw, 4));
            $hashes[] = hash('sha256', $raw);
        }
        return [$codes, $hashes];
    }

    private function consumeProof(MfaIdentity $scope, string $ciphertext,
        #[\SensitiveParameter] string $proof): bool
    {
        $secretHash = hash('sha256', $ciphertext);
        if (preg_match('/\A[0-9]{6}\z/D', $proof) === 1) {
            $secret = $this->crypt()->decrypt($ciphertext, $this->purpose($scope));
            $counter = Totp::match($secret, $proof, $this->now(), $this->settings['skew']);
            return $counter !== null && $this->repository()->claimCounter($scope, $counter, $secretHash);
        }
        $canonical = self::recoveryCode($proof);
        return $canonical !== null
            && $this->repository()->claimRecoveryCode($scope, hash('sha256', $canonical), $secretHash);
    }

    /** Only case and the generated ASCII grouping hyphens are presentation. */
    private static function recoveryCode(#[\SensitiveParameter] string $proof): ?string
    {
        if (preg_match('/\A(?:[A-Fa-f0-9]{4}-){7}[A-Fa-f0-9]{4}\z/D', $proof) === 1) {
            return strtoupper(str_replace('-', '', $proof));
        }
        return preg_match('/\A[A-Fa-f0-9]{32}\z/D', $proof) === 1 ? strtoupper($proof) : null;
    }

    /** Return no expired or malformed private state; it never becomes a user. */
    private function livePending(): ?array
    {
        $pending = $this->session()->pendingMfa();
        if ($pending === null) return null;
        if (!is_string($pending['guard'] ?? null) || !is_string($pending['class'] ?? null)
            || !(is_int($pending['identifier'] ?? null) || is_string($pending['identifier'] ?? null))
            || !is_string($pending['scope_digest'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $pending['scope_digest'])
            || !is_string($pending['credential_fingerprint'] ?? null)
            || !is_int($pending['expires_at'] ?? null)
            || !is_bool($pending['remember_requested'] ?? null)
            || $pending['expires_at'] <= $this->now()) {
            $this->abandon();
            return null;
        }
        return $pending;
    }

    private function abandon(): void
    {
        $this->session()->invalidate();
        $this->auth()->clearSessionGuardIdentityCaches();
    }

    private function authenticated(string $guard): Authenticatable
    {
        return $this->guard($guard)->user()
            ?? throw new MfaException('A fully authenticated Session is required.');
    }

    private function guard(string $name): SessionGuard
    {
        $guard = $this->auth()->guard($name);
        if (!$guard instanceof SessionGuard) throw new MfaConfigurationException('MFA requires a session guard.');
        return $guard;
    }

    private function allowed(string $action, MfaIdentity $scope): bool
    {
        return $this->limiter()->consume('mfa.' . $action, $scope->digest,
            $this->settings['max_attempts'], $this->settings['attempt_window'])->allowed();
    }

    private function clearLimit(string $action, MfaIdentity $scope): void
    {
        $this->limiter()->clear('mfa.' . $action, $scope->digest);
    }

    private function purpose(MfaIdentity $scope): string { return 'mfa.totp.v1.' . $scope->digest; }
    private function required(): void
    {
        if (!$this->isEnabled()) throw new MfaConfigurationException('MFA is disabled for this Application.');
    }
    private function auth(): AuthManager { return $this->container->make(AuthManager::class); }
    private function session(): SessionStore
    {
        return $this->container->make(SessionManager::class)->store();
    }
    private function crypt(): CryptManager { return $this->container->make(CryptManager::class); }
    private function limiter(): RateLimiter { return $this->container->make(RateLimiter::class); }
    private function now(): int
    {
        $this->clock ??= $this->container->has(ModelClock::class)
            ? $this->container->make(ModelClock::class)
            : ($this->container->has(DatabaseManager::class)
                ? $this->container->make(DatabaseManager::class)->clock() : new SystemModelClock());
        return $this->clock->now()->getTimestamp();
    }
    private function repository(): MfaRepository
    {
        if ($this->repository !== null) return $this->repository;
        if ($this->settings['driver'] === 'array') return $this->repository = new ArrayMfaRepository();
        return $this->repository = new DatabaseMfaRepository(
            $this->container->make(DatabaseManager::class), $this->settings['credentials_table'],
            $this->settings['recovery_table'], $this->settings['connection']);
    }
}
