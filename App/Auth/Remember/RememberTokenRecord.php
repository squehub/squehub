<?php

declare(strict_types=1);

namespace App\Auth\Remember;

use App\AccountSecurity\SecurityTokenRecord;
use DateTimeImmutable;

/** Hash-only persistent-login record. The browser validator never enters storage. */
final readonly class RememberTokenRecord implements \JsonSerializable
{
    public function __construct(
        public string $selector,
        public string $validatorHash,
        public string $guard,
        public string $identityKey,
        public string $credentialFingerprint,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt
    ) {
        if (preg_match('/\A[A-Za-z0-9_-]{22}\z/D', $selector) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $validatorHash) !== 1
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $guard) !== 1
            || strlen($guard) > 64
            || !((preg_match('/\Ai:(?:0|[1-9][0-9]*)\z/D', $identityKey) === 1
                    && (string) (int) substr($identityKey, 2) === substr($identityKey, 2))
                || (str_starts_with($identityKey, 's:') && strlen($identityKey) > 2
                    && strlen($identityKey) <= 255))
            || preg_match('/\A[a-f0-9]{64}\z/D', $credentialFingerprint) !== 1
            || $createdAt >= $expiresAt) {
            throw new RememberException('Remember credential record is invalid.');
        }
    }

    public static function identityKey(int|string $identifier): string
    {
        return SecurityTokenRecord::key($identifier);
    }

    /** Exact guard and typed identity equality across SQLite/MySQL collations. */
    public static function scopeHash(string $guard, string $identityKey): string
    {
        return hash('sha256', pack('N', strlen($guard)) . $guard
            . pack('N', strlen($identityKey)) . $identityKey);
    }

    public function identifier(): int|string
    {
        if (str_starts_with($this->identityKey, 'i:')) {
            $value = substr($this->identityKey, 2);
            if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
                && (string) (int) $value === $value) return (int) $value;
        }
        if (str_starts_with($this->identityKey, 's:') && strlen($this->identityKey) > 2) {
            return substr($this->identityKey, 2);
        }
        throw new RememberException('Remember credential identity is invalid.');
    }

    public function expired(DateTimeImmutable $now): bool { return $now >= $this->expiresAt; }

    public function __debugInfo(): array
    {
        return ['selector' => '[REDACTED]', 'validatorHash' => '[REDACTED]',
            'guard' => $this->guard, 'identityKey' => '[REDACTED]',
            'credentialFingerprint' => '[REDACTED]'];
    }

    public function jsonSerialize(): array { return []; }

    public function __serialize(): array
    {
        throw new RememberException('Remember credential records cannot be serialized.');
    }
}
