<?php

declare(strict_types=1);

namespace App\Auth\Remember\Repositories;

use App\Auth\Remember\RememberException;
use App\Auth\Remember\RememberTokenRecord;
use App\Auth\Remember\RememberTokenRepository;
use App\Database\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;

/** A conditional update makes selector/validator rotation one atomic write. */
final class DatabaseRememberTokenRepository implements RememberTokenRepository
{
    public function __construct(private DatabaseManager $database, private string $table,
        private ?string $connection = null)
    {
    }

    public function insert(RememberTokenRecord $record): void
    {
        $this->database->table($this->table, $this->connection)->insert([
            'selector' => $record->selector,
            'validator_hash' => $record->validatorHash,
            'guard' => $record->guard,
            'identity_identifier' => $record->identityKey,
            'identity_scope_hash' => RememberTokenRecord::scopeHash($record->guard, $record->identityKey),
            'credential_fingerprint' => $record->credentialFingerprint,
            'created_at' => self::date($record->createdAt),
            'expires_at' => self::date($record->expiresAt),
        ]);
    }

    public function find(string $selector): ?RememberTokenRecord
    {
        $row = $this->database->table($this->table, $this->connection)
            ->filter('selector', $selector)->first();
        if ($row === null) return null;
        // MySQL's default text collation may return a case variant. Selector
        // bytes are credentials and must match exactly after the lookup.
        if (!is_string($row['selector']) || !hash_equals($selector, $row['selector'])) return null;
        if (!hash_equals(RememberTokenRecord::scopeHash($row['guard'], $row['identity_identifier']),
            $row['identity_scope_hash'])) {
            throw new RememberException('Stored remember credential scope is invalid.');
        }
        return new RememberTokenRecord($row['selector'], $row['validator_hash'], $row['guard'],
            $row['identity_identifier'], $row['credential_fingerprint'],
            self::parse($row['created_at']), self::parse($row['expires_at']));
    }

    public function rotate(string $selector, string $validatorHash, RememberTokenRecord $replacement): bool
    {
        $current = $this->find($selector);
        if ($current === null || !hash_equals($current->validatorHash, $validatorHash)) return false;
        if ($replacement->guard !== $current->guard || $replacement->identityKey !== $current->identityKey
            || $replacement->selector === $selector) {
            throw new RememberException('Remember replacement is invalid.');
        }
        // The conditional write is the concurrency boundary. A stale reader
        // cannot replace a credential already claimed by another request.
        return $this->database->table($this->table, $this->connection)
            ->filter('selector', $selector)->filter('validator_hash', $validatorHash)
            ->filter('guard', $current->guard)
            ->filter('identity_scope_hash', RememberTokenRecord::scopeHash(
                $current->guard, $current->identityKey))
            ->update([
                'selector' => $replacement->selector,
                'validator_hash' => $replacement->validatorHash,
                'credential_fingerprint' => $replacement->credentialFingerprint,
                'created_at' => self::date($replacement->createdAt),
                'expires_at' => self::date($replacement->expiresAt),
            ]) === 1;
    }

    public function revokeSelector(string $selector): void
    {
        $this->database->table($this->table, $this->connection)->filter('selector', $selector)->delete();
    }

    public function revokeIdentity(string $guard, string $identityKey): void
    {
        $this->database->table($this->table, $this->connection)
            ->filter('identity_scope_hash', RememberTokenRecord::scopeHash($guard, $identityKey))->delete();
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RememberException('Stored remember credential date is invalid.');
        }
        return $date;
    }
}
