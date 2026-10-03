<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use DateTimeImmutable;

/** Hash-only repository record with a typed identity key and UTC expiry. */
final readonly class SecurityTokenRecord
{
    public function __construct(
        public string $tokenHash,
        public string $purpose,
        public string $guard,
        public string $identityKey,
        public string $contextHash,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt
    ) {
    }

    /** Preserve int versus string identifiers across database storage. */
    public static function key(int|string $identifier): string
    {
        if (is_int($identifier)) {
            if ($identifier < 0) throw new AccountSecurityConfigurationException('Identity identifier is not suitable for token storage.');
            return 'i:' . $identifier;
        }
        // PDO-generated IDs may hydrate as integers even when lastInsertId()
        // produced a string. Canonical decimal strings must share that key.
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $identifier) === 1
            && (string) (int) $identifier === $identifier) return 'i:' . $identifier;
        if ($identifier === '' || strlen($identifier) > 253) {
            throw new AccountSecurityConfigurationException('Identity identifier is not suitable for token storage.');
        }
        return 's:' . $identifier;
    }

    public function identifier(): int|string
    {
        if (str_starts_with($this->identityKey, 'i:')
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', substr($this->identityKey, 2)) === 1) {
            $value = substr($this->identityKey, 2);
            if ((string) (int) $value === $value) return (int) $value;
        }
        if (str_starts_with($this->identityKey, 's:') && strlen($this->identityKey) > 2) {
            return substr($this->identityKey, 2);
        }
        throw new AccountSecurityConfigurationException('Stored token identity identifier is invalid.');
    }

    public function expired(DateTimeImmutable $now): bool { return $now >= $this->expiresAt; }
}
