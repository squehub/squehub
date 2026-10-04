<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use DateTimeImmutable;

/** Internal hash-only record; raw bearer material never reaches a repository. */
final readonly class TokenRecord implements \JsonSerializable
{
    /** @param list<string> $abilities */
    public function __construct(
        public string $identifier,
        public string $tokenHash,
        public string $guard,
        public int|string $identityId,
        public string $name,
        public array $abilities,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastUsedAt = null,
        public ?DateTimeImmutable $revokedAt = null
    ) {
        if (!TokenFormat::validIdentifier($identifier)
            || preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1
            || strlen($guard) > 64
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $guard) !== 1
            || !self::validIdentity($identityId)
            || !self::validName($name)
            || !self::validAbilities($abilities)) {
            throw new TokenException('Token record contains invalid metadata.');
        }
    }

    /** Prevent accidental dump output of an internal credential fingerprint. */
    public function __debugInfo(): array
    {
        return ['identifier' => $this->identifier, 'tokenHash' => '[REDACTED]', 'guard' => $this->guard];
    }

    /** Internal records must never become an API or diagnostics payload. */
    public function jsonSerialize(): array { return []; }

    public function __serialize(): array
    {
        throw new TokenException('Internal token records cannot be serialized.');
    }

    public function active(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null && ($this->expiresAt === null || $now < $this->expiresAt);
    }

    public function withLastUsedAt(DateTimeImmutable $at): self
    {
        return new self($this->identifier, $this->tokenHash, $this->guard, $this->identityId,
            $this->name, $this->abilities, $this->createdAt, $this->expiresAt, $at, $this->revokedAt);
    }

    public function withRevokedAt(DateTimeImmutable $at): self
    {
        return new self($this->identifier, $this->tokenHash, $this->guard, $this->identityId,
            $this->name, $this->abilities, $this->createdAt, $this->expiresAt, $this->lastUsedAt, $at);
    }

    public static function validName(string $name): bool
    {
        return trim($name) !== '' && strlen($name) <= 128
            && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1
            && preg_match('//u', $name) === 1;
    }

    /** @param mixed $abilities */
    public static function validAbilities(mixed $abilities): bool
    {
        if (!is_array($abilities) || !array_is_list($abilities) || count($abilities) > 64) return false;
        foreach ($abilities as $ability) {
            if (!is_string($ability) || !self::validAbility($ability)) return false;
        }
        return true;
    }

    /** The exact same bounded vocabulary is used at issue time and at route checks. */
    public static function validAbility(string $ability): bool
    {
        return strlen($ability) <= 128 && ($ability === '*'
            || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $ability) === 1);
    }

    public static function validIdentity(int|string $identifier): bool
    {
        return is_int($identifier) ? $identifier >= 0 : $identifier !== '' && strlen($identifier) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $identifier) !== 1
            && preg_match('//u', $identifier) === 1;
    }

    /**
     * PDO may hydrate a generated integer ID after insert as an int although
     * lastInsertId() produced its canonical decimal string. Match both forms
     * while preserving noncanonical string keys such as "007".
     *
     * @return array{string,string}
     */
    public static function identityColumns(int|string $identifier): array
    {
        if (!self::validIdentity($identifier)) throw new TokenException('Token identity identifier is invalid.');
        if (is_int($identifier)) return ['i', (string) $identifier];
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $identifier) === 1
            && (string) (int) $identifier === $identifier) return ['i', $identifier];
        return ['s', $identifier];
    }
}
