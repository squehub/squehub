<?php

declare(strict_types=1);

namespace App\Auth\Tokens\Repositories;

use App\Auth\Tokens\TokenException;
use App\Auth\Tokens\TokenRecord;
use App\Auth\Tokens\TokenRepository;
use App\Database\DatabaseManager;
use App\Database\Schema\Table;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/** Portable, indexed token persistence through SqueHub's bound QueryBuilder. */
final class DatabaseTokenRepository implements TokenRepository
{
    public function __construct(
        private DatabaseManager $database,
        private string $table = 'api_tokens',
        private ?string $connection = null
    ) {
        Table::name($table);
    }

    public function insert(TokenRecord $record): void
    {
        $this->database->table($this->table, $this->connection)->insert(self::values($record));
    }

    public function find(string $identifier): ?TokenRecord
    {
        $row = $this->database->table($this->table, $this->connection)
            ->filter('identifier', $identifier)->first();
        return $row === null ? null : self::record($row);
    }

    /** @return list<TokenRecord> */
    public function listFor(string $guard, int|string $identityId): array
    {
        [$kind, $key] = TokenRecord::identityColumns($identityId);
        $rows = $this->database->table($this->table, $this->connection)
            ->filter('guard', $guard)->filter('identity_kind', $kind)
            ->filter('identity_identifier', $key)->sort('created_at', 'desc')
            ->sort('identifier')->all();
        return array_map(self::record(...), $rows);
    }

    public function touch(string $guard, string $identifier, DateTimeImmutable $at): void
    {
        $this->database->table($this->table, $this->connection)
            ->filter('identifier', $identifier)->filter('guard', $guard)
            ->filterNull('revoked_at')->update(['last_used_at' => self::date($at)]);
    }

    public function revoke(string $guard, string $identifier, DateTimeImmutable $at): bool
    {
        return $this->database->table($this->table, $this->connection)
            ->filter('identifier', $identifier)->filter('guard', $guard)
            ->filterNull('revoked_at')->update(['revoked_at' => self::date($at)]) === 1;
    }

    public function revokeAll(string $guard, int|string $identityId, DateTimeImmutable $at): int
    {
        [$kind, $key] = TokenRecord::identityColumns($identityId);
        return $this->database->table($this->table, $this->connection)
            ->filter('guard', $guard)->filter('identity_kind', $kind)
            ->filter('identity_identifier', $key)->filterNull('revoked_at')
            ->update(['revoked_at' => self::date($at)]);
    }

    /** One managed transaction makes old-token revocation and new insertion indivisible. */
    public function rotate(string $guard, string $identifier, TokenRecord $replacement, DateTimeImmutable $at): bool
    {
        return $this->database->transaction(function () use ($guard, $identifier, $replacement, $at): bool {
            $current = $this->find($identifier);
            if ($current === null || $current->guard !== $guard || !$current->active($at)) return false;
            if ($replacement->guard !== $guard
                || TokenRecord::identityColumns($replacement->identityId)
                    !== TokenRecord::identityColumns($current->identityId)) {
                throw new TokenException('Token replacement is invalid.');
            }
            // A competing revocation/rotation can win after the read; only the
            // writer that changes the active row may insert a replacement.
            if (!$this->revoke($guard, $identifier, $at)) return false;
            $this->insert($replacement);
            return true;
        }, $this->connection);
    }

    public function prune(string $guard, DateTimeImmutable $cutoff): int
    {
        $date = self::date($cutoff);
        $expired = $this->database->table($this->table, $this->connection)
            ->filter('guard', $guard)->filter('expires_at', '<=', $date)->delete();
        $revoked = $this->database->table($this->table, $this->connection)
            ->filter('guard', $guard)->filter('revoked_at', '<=', $date)->delete();
        return $expired + $revoked;
    }

    /** @return array<string,string|null> */
    private static function values(TokenRecord $record): array
    {
        [$kind, $key] = TokenRecord::identityColumns($record->identityId);
        try {
            $abilities = json_encode($record->abilities, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new TokenException('Token abilities cannot be stored.', 0, $failure);
        }
        return [
            'identifier' => $record->identifier,
            'token_hash' => $record->tokenHash,
            'guard' => $record->guard,
            'identity_kind' => $kind,
            'identity_identifier' => $key,
            'name' => $record->name,
            'abilities' => $abilities,
            'created_at' => self::date($record->createdAt),
            'expires_at' => $record->expiresAt === null ? null : self::date($record->expiresAt),
            'last_used_at' => $record->lastUsedAt === null ? null : self::date($record->lastUsedAt),
            'revoked_at' => $record->revokedAt === null ? null : self::date($record->revokedAt),
        ];
    }

    /** Strict hydration fails closed if persisted ability or date metadata is corrupt. */
    private static function record(array $row): TokenRecord
    {
        try {
            foreach (['identifier', 'token_hash', 'guard', 'identity_kind', 'identity_identifier',
                'name', 'abilities', 'created_at'] as $column) {
                if (!is_string($row[$column] ?? null)) throw new TokenException('Stored token record is invalid.');
            }
            $kind = $row['identity_kind'];
            $key = $row['identity_identifier'];
            if ($kind === 'i' && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $key) === 1
                && (string) (int) $key === $key) {
                $identity = (int) $key;
            } elseif ($kind === 's') {
                $identity = $key;
            } else {
                throw new TokenException('Stored token identity is invalid.');
            }
            $abilities = json_decode($row['abilities'], true, 512, JSON_THROW_ON_ERROR);
            if (!TokenRecord::validAbilities($abilities)) {
                throw new TokenException('Stored token abilities are invalid.');
            }
            return new TokenRecord($row['identifier'], $row['token_hash'], $row['guard'],
                $identity, $row['name'], $abilities, self::parse($row['created_at']),
                self::nullableDate($row['expires_at'] ?? null),
                self::nullableDate($row['last_used_at'] ?? null),
                self::nullableDate($row['revoked_at'] ?? null));
        } catch (JsonException $failure) {
            throw new TokenException('Stored token abilities are invalid.', 0, $failure);
        }
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) return null;
        if (!is_string($value)) throw new TokenException('Stored token date is invalid.');
        return self::parse($value);
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new TokenException('Stored token date is invalid.');
        }
        return $date;
    }
}
