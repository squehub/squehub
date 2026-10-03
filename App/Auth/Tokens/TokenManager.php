<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Issues and verifies long-lived bearer credentials for one named Auth guard.
 * Only an indexed non-secret identifier and a SHA-256 digest reach persistence.
 * Password hashing is deliberately unnecessary for independent 256-bit secrets.
 */
final class TokenManager
{
    private const DUMMY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @var array{driver:string,table:string,connection:?string,default_ttl:?int,allow_non_expiring:bool,max_ttl:int,last_used_interval:int,prune_retention:int} */
    private array $settings;

    /** @param array<string,mixed> $settings */
    public function __construct(
        private string $guard,
        private IdentityProvider $provider,
        private TokenRepository $repository,
        private ModelClock $clock,
        array $settings = [],
        private ?Diagnostics $diagnostics = null
    ) {
        if (strlen($guard) > 64 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $guard) !== 1) {
            throw new TokenException('Token guard name is invalid.');
        }
        $this->settings = self::settings($settings);
    }

    /** Validate without opening a database or generating a credential at bootstrap. */
    public static function settings(array $settings): array
    {
        $defaults = [
            'driver' => 'database', 'table' => 'api_tokens', 'connection' => null,
            'default_ttl' => 2592000, 'allow_non_expiring' => false,
            'max_ttl' => 31536000, 'last_used_interval' => 300,
            'prune_retention' => 2592000,
        ];
        foreach (array_keys($settings) as $key) {
            if (!is_string($key) || !array_key_exists($key, $defaults)) {
                throw new TokenException('Unknown token configuration option.');
            }
        }
        $values = array_replace($defaults, $settings);
        if (!is_string($values['driver']) || !in_array($values['driver'], ['array', 'database'], true)) {
            throw new TokenException('Token repository driver is invalid.');
        }
        if (!is_string($values['table'])) throw new TokenException('Token table name is invalid.');
        try { Table::name($values['table']); }
        catch (Throwable $failure) { throw new TokenException('Token table name is invalid.', 0, $failure); }
        if ($values['connection'] !== null
            && (!is_string($values['connection'])
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $values['connection']) !== 1)) {
            throw new TokenException('Token database connection is invalid.');
        }
        if (!is_bool($values['allow_non_expiring'])) {
            throw new TokenException('Token non-expiring policy is invalid.');
        }
        if (!is_int($values['max_ttl']) || $values['max_ttl'] < 1 || $values['max_ttl'] > 315360000) {
            throw new TokenException('Token maximum lifetime is invalid.');
        }
        if ($values['default_ttl'] !== null
            && (!is_int($values['default_ttl']) || $values['default_ttl'] < 1
                || $values['default_ttl'] > $values['max_ttl'])) {
            throw new TokenException('Token default lifetime is invalid.');
        }
        if ($values['default_ttl'] === null && !$values['allow_non_expiring']) {
            throw new TokenException('Non-expiring tokens are not enabled.');
        }
        if (!is_int($values['last_used_interval']) || $values['last_used_interval'] < 0
            || $values['last_used_interval'] > 31536000) {
            throw new TokenException('Token last-used interval is invalid.');
        }
        if (!is_int($values['prune_retention']) || $values['prune_retention'] < 0
            || $values['prune_retention'] > 315360000) {
            throw new TokenException('Token prune retention is invalid.');
        }
        return $values;
    }

    /**
     * The raw token leaves this method once. Application code must authorize
     * the caller, selected abilities, and expiry before invoking it.
     *
     * @param list<string> $abilities
     */
    public function issue(Authenticatable $identity, string $name,
        array $abilities = ['*'], ?DateTimeImmutable $expiresAt = null): IssuedToken
    {
        try {
            $this->requireIdentity($identity);
            $current = $this->provider->retrieveById($identity->authIdentifier());
            if ($current === null || !$this->provider->supports($current)
                || (string) $current->authIdentifier() !== (string) $identity->authIdentifier()) {
                throw new TokenException('Identity is not supported by this token guard.');
            }
            if (!TokenRecord::validName($name) || !TokenRecord::validAbilities($abilities)) {
                throw new TokenException('Token name or abilities are invalid.');
            }
            $now = $this->now();
            $expiresAt = $this->expiry($expiresAt, $now);
            $generated = TokenFormat::generate();
            $record = new TokenRecord($generated['identifier'], $generated['hash'], $this->guard,
                $identity->authIdentifier(), $name, $abilities, $now, $expiresAt);
            $this->repository->insert($record);
            $this->diagnostics?->tokenAuth('issued');
            return new IssuedToken($generated['token'], TokenMetadata::fromRecord($record));
        } catch (Throwable $failure) {
            $this->diagnostics?->tokenAuth('errors');
            throw $failure;
        }
    }

    /**
     * Invalid bearer material has one null outcome. Even an unknown indexed
     * identifier follows a fixed-length digest comparison before rejection.
     */
    public function authenticate(#[\SensitiveParameter] string $rawToken): ?TokenAuthentication
    {
        $this->diagnostics?->tokenAuth('attempts');
        try {
            $parsed = TokenFormat::parse($rawToken);
            if ($parsed === null) return $this->failed();
            $record = $this->repository->find($parsed['identifier']);
            $hash = $record?->tokenHash ?? self::DUMMY_HASH;
            $matches = hash_equals($hash, $parsed['hash']);
            $now = $this->now();
            if (!$matches || $record === null || $record->guard !== $this->guard || !$record->active($now)) {
                return $this->failed();
            }
            $identity = $this->provider->retrieveById($record->identityId);
            if ($identity === null || !$this->provider->supports($identity)
                || (string) $identity->authIdentifier() !== (string) $record->identityId) {
                return $this->failed();
            }
            // Last-used metadata is advisory and bounded to avoid one UPDATE
            // for every authenticated API request.
            if ($record->lastUsedAt === null
                || $record->lastUsedAt <= $now->modify('-' . $this->settings['last_used_interval'] . ' seconds')) {
                $this->repository->touch($this->guard, $record->identifier, $now);
                $record = $record->withLastUsedAt($now);
            }
            $this->diagnostics?->tokenAuth('successes');
            return new TokenAuthentication($identity, TokenMetadata::fromRecord($record));
        } catch (Throwable $failure) {
            $this->diagnostics?->tokenAuth('errors');
            throw $failure;
        }
    }

    /** @return list<TokenMetadata> */
    public function listFor(Authenticatable $identity): array
    {
        $this->requireIdentity($identity);
        return array_map(TokenMetadata::fromRecord(...),
            $this->repository->listFor($this->guard, $identity->authIdentifier()));
    }

    public function revoke(string $identifier): bool
    {
        if (!TokenFormat::validIdentifier($identifier)) return false;
        try {
            $revoked = $this->repository->revoke($this->guard, $identifier, $this->now());
            if ($revoked) $this->diagnostics?->tokenAuth('revoked');
            return $revoked;
        } catch (Throwable $failure) {
            $this->diagnostics?->tokenAuth('errors');
            throw $failure;
        }
    }

    public function revokeAll(Authenticatable $identity): int
    {
        try {
            $this->requireIdentity($identity);
            $count = $this->repository->revokeAll($this->guard, $identity->authIdentifier(), $this->now());
            if ($count > 0) $this->diagnostics?->tokenAuth('revoked', $count);
            return $count;
        } catch (Throwable $failure) {
            $this->diagnostics?->tokenAuth('errors');
            throw $failure;
        }
    }

    /** Retain the old token's abilities, name, and expiry; rotate only its credential. */
    public function rotate(string $identifier): IssuedToken
    {
        try {
            if (!TokenFormat::validIdentifier($identifier)) throw new TokenException('Token cannot be rotated.');
            $now = $this->now();
            $old = $this->repository->find($identifier);
            if ($old === null || $old->guard !== $this->guard || !$old->active($now)) {
                throw new TokenException('Token cannot be rotated.');
            }
            $identity = $this->provider->retrieveById($old->identityId);
            if ($identity === null || !$this->provider->supports($identity)
                || (string) $identity->authIdentifier() !== (string) $old->identityId) {
                throw new TokenException('Token cannot be rotated.');
            }
            $generated = TokenFormat::generate();
            $replacement = new TokenRecord($generated['identifier'], $generated['hash'], $this->guard,
                $old->identityId, $old->name, $old->abilities, $now, $old->expiresAt);
            if (!$this->repository->rotate($this->guard, $identifier, $replacement, $now)) {
                throw new TokenException('Token cannot be rotated.');
            }
            $this->diagnostics?->tokenAuth('rotated');
            return new IssuedToken($generated['token'], TokenMetadata::fromRecord($replacement));
        } catch (Throwable $failure) {
            $this->diagnostics?->tokenAuth('errors');
            throw $failure;
        }
    }

    /** Delete records only after configured retention following expiry/revocation. */
    public function prune(): int
    {
        try {
            $cutoff = $this->now()->modify('-' . $this->settings['prune_retention'] . ' seconds');
            $count = $this->repository->prune($this->guard, $cutoff);
            if ($count > 0) $this->diagnostics?->tokenAuth('pruned', $count);
            return $count;
        } catch (Throwable $failure) {
            $this->diagnostics?->tokenAuth('errors');
            throw $failure;
        }
    }

    private function failed(): ?TokenAuthentication
    {
        $this->diagnostics?->tokenAuth('failures');
        return null;
    }

    private function requireIdentity(Authenticatable $identity): void
    {
        if (!TokenRecord::validIdentity($identity->authIdentifier()) || !$this->provider->supports($identity)) {
            throw new TokenException('Identity is not supported by this token guard.');
        }
    }

    private function expiry(?DateTimeImmutable $requested, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $expires = $requested === null
            ? ($this->settings['default_ttl'] === null ? null
                : $now->modify('+' . $this->settings['default_ttl'] . ' seconds'))
            : self::utcSeconds($requested);
        if ($expires !== null
            && ($expires <= $now || $expires > $now->modify('+' . $this->settings['max_ttl'] . ' seconds'))) {
            throw new TokenException('Token expiry is outside the configured policy.');
        }
        return $expires;
    }

    private function now(): DateTimeImmutable
    {
        return self::utcSeconds($this->clock->now());
    }

    /** SQL's portable DATETIME precision is seconds; normalize before comparisons. */
    private static function utcSeconds(DateTimeImmutable $date): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        return new DateTimeImmutable($date->setTimezone($utc)->format('Y-m-d H:i:s'), $utc);
    }
}
