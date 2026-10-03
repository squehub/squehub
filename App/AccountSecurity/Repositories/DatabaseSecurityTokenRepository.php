<?php

declare(strict_types=1);

namespace App\AccountSecurity\Repositories;

use App\AccountSecurity\AccountSecurityConfigurationException;
use App\AccountSecurity\SecurityTokenRecord;
use App\AccountSecurity\SecurityTokenRepository;
use App\Database\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;

/** Stores only digests; conditional DELETE is the one-winner claim boundary. */
final class DatabaseSecurityTokenRepository implements SecurityTokenRepository
{
    public function __construct(private DatabaseManager $database, private string $table)
    {
    }

    public function replace(SecurityTokenRecord $record): void
    {
        // Revocation and insertion form one unit; a failed insert restores the
        // prior outstanding token instead of losing it during replacement.
        $write = function () use ($record): void {
            if ($this->database->table($this->table)->filter('token_hash', $record->tokenHash)->exists()) {
                throw new AccountSecurityConfigurationException('Security token hash already exists.');
            }
            $this->revoke($record->purpose, $record->guard, $record->identityKey);
            $this->database->table($this->table)->insert([
                'token_hash' => $record->tokenHash, 'purpose' => $record->purpose,
                'guard' => $record->guard, 'identity_identifier' => $record->identityKey,
                'context_hash' => $record->contextHash,
                'expires_at' => self::date($record->expiresAt),
                'created_at' => self::date($record->createdAt),
            ]);
        };
        $connection = $this->database->connection();
        if (!$connection->pdo()->inTransaction()) {
            $connection->transaction($write);
            return;
        }
        // An application may own the surrounding transaction. A savepoint
        // gives replacement local rollback without committing caller work.
        $savepoint = 'squehub_security_' . bin2hex(random_bytes(6));
        $connection->raw('SAVEPOINT ' . $savepoint);
        try {
            $write();
            $connection->raw('RELEASE SAVEPOINT ' . $savepoint);
        } catch (\Throwable $failure) {
            $connection->raw('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $connection->raw('RELEASE SAVEPOINT ' . $savepoint);
            throw $failure;
        }
    }

    public function claim(string $tokenHash, string $purpose, string $guard, DateTimeImmutable $now): ?SecurityTokenRecord
    {
        $row = $this->database->table($this->table)->filter('token_hash', $tokenHash)
            ->filter('purpose', $purpose)->filter('guard', $guard)->first();
        if ($row === null) return null;
        // The SELECT does not grant ownership. Exactly one competing DELETE
        // can affect this hash, so a stale reader must return null.
        $deleted = $this->database->table($this->table)->filter('token_hash', $tokenHash)
            ->filter('purpose', $purpose)->filter('guard', $guard)->delete();
        if ($deleted !== 1) return null;
        $record = new SecurityTokenRecord($row['token_hash'], $row['purpose'], $row['guard'],
            $row['identity_identifier'], $row['context_hash'], self::parse($row['expires_at']),
            self::parse($row['created_at']));
        return $record->expired($now) ? null : $record;
    }

    public function revoke(string $purpose, string $guard, string $identityKey): void
    {
        $this->database->table($this->table)->filter('purpose', $purpose)
            ->filter('guard', $guard)->filter('identity_identifier', $identityKey)->delete();
    }

    private static function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false) throw new AccountSecurityConfigurationException('Stored security token date is invalid.');
        return $date;
    }
}
