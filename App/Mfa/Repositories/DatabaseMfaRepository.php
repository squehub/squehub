<?php

declare(strict_types=1);

namespace App\Mfa\Repositories;

use App\Database\DatabaseManager;
use App\Database\Exception\QueryException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDOException;

/** Bound queries use an exact identity digest and conditional one-winner writes. */
final class DatabaseMfaRepository implements MfaRepository
{
    private const MAX_EPOCH = 253402300799; // Last second of MySQL DATETIME's year 9999.

    public function __construct(private DatabaseManager $database,
        private string $credentialsTable = 'mfa_credentials',
        private string $recoveryTable = 'mfa_recovery_codes',
        private ?string $connection = null)
    {
    }

    public function find(MfaIdentity $identity): ?array
    {
        $row = $this->row($identity);
        if ($row === null) return null;
        return [
            'enabled' => self::enabled($row),
            'encrypted_secret' => self::nullableCiphertext($row, 'encrypted_secret'),
            'pending_secret' => self::nullableCiphertext($row, 'pending_secret'),
            'pending_expires_at' => self::pendingExpiry($row),
            'last_counter' => self::lastCounter($row),
        ];
    }

    public function savePending(MfaIdentity $identity, string $encryptedSecret, int $expiresAt): void
    {
        self::assertCiphertext($encryptedSecret);
        $expiry = self::utc($expiresAt);
        $digest = hash('sha256', $encryptedSecret);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $row = $this->row($identity);
            if ($row === null) {
                try {
                    $this->database->table($this->credentialsTable, $this->connection)->insert([
                        'identity_digest' => $identity->digest,
                        'guard' => $identity->guard,
                        'identity_class' => $identity->class,
                        'identity_kind' => $identity->kind,
                        'identity_identifier' => $identity->identifier,
                        'enabled' => 0,
                        'encrypted_secret' => null,
                        'secret_hash' => null,
                        'generation_nonce' => null,
                        'pending_secret' => $encryptedSecret,
                        'pending_secret_hash' => $digest,
                        'pending_expires_at' => $expiry,
                        'last_counter' => null,
                    ]);
                    return;
                } catch (QueryException $failure) {
                    // Only a concurrent insert of this exact identity is retryable.
                    if (!self::duplicateIdentity($failure, $this->credentialsTable)) throw $failure;
                    if ($this->row($identity) === null) throw $failure;
                    continue;
                }
            }
            if (self::enabled($row)) {
                throw new LogicException('Enabled MFA cannot be replaced by pending enrollment.');
            }
            $query = $this->database->table($this->credentialsTable, $this->connection)
                ->filter('id', self::rowId($row))->filter('identity_digest', $identity->digest)
                ->filter('enabled', 0);
            $oldHash = $row['pending_secret_hash'] ?? null;
            $query = $oldHash === null ? $query->filterNull('pending_secret_hash')
                : $query->filter('pending_secret_hash', $oldHash);
            if ($query->update([
                'pending_secret' => $encryptedSecret,
                'pending_secret_hash' => $digest,
                'pending_expires_at' => $expiry,
            ]) === 1) return;
            $latest = $this->row($identity);
            if ($latest !== null && self::enabled($latest)) {
                throw new LogicException('Enabled MFA cannot be replaced by pending enrollment.');
            }
            if ($latest !== null
                && self::nullableCiphertext($latest, 'pending_secret') === $encryptedSecret
                && ($latest['pending_secret_hash'] ?? null) === $digest
                && ($latest['pending_expires_at'] ?? null) === $expiry) return;
        }
        throw new LogicException('MFA enrollment changed concurrently.');
    }

    public function activate(MfaIdentity $identity, string $expectedPendingCiphertext,
        int $confirmedCounter, array $recoveryHashes, int $now): bool
    {
        self::assertCiphertext($expectedPendingCiphertext);
        self::assertCounter($confirmedCounter);
        self::assertHashes($recoveryHashes);
        $currentTime = self::utc($now);
        return $this->database->transaction(function () use ($identity, $expectedPendingCiphertext,
            $confirmedCounter, $recoveryHashes, $currentTime): bool {
            $row = $this->row($identity);
            if ($row === null || self::enabled($row)
                || !is_string($row['pending_secret'] ?? null)
                || !hash_equals($row['pending_secret'], $expectedPendingCiphertext)
                || ($row['pending_secret_hash'] ?? null) !== hash('sha256', $expectedPendingCiphertext)
                || !is_string($row['pending_expires_at'] ?? null)
                || $row['pending_expires_at'] <= $currentTime) return false;
            $updated = $this->database->table($this->credentialsTable, $this->connection)
                ->filter('id', self::rowId($row))
                ->filter('identity_digest', $identity->digest)
                ->filter('enabled', 0)
                ->filter('pending_secret_hash', hash('sha256', $expectedPendingCiphertext))
                ->filter('pending_expires_at', '>', $currentTime)
                ->update([
                    'enabled' => 1,
                    'encrypted_secret' => $expectedPendingCiphertext,
                    'secret_hash' => hash('sha256', $expectedPendingCiphertext),
                    'generation_nonce' => bin2hex(random_bytes(16)),
                    'pending_secret' => null,
                    'pending_secret_hash' => null,
                    'pending_expires_at' => null,
                    'last_counter' => self::counterString($confirmedCounter),
                ]);
            if ($updated !== 1) return false;
            $this->insertCodes(self::rowId($row), $recoveryHashes,
                hash('sha256', $expectedPendingCiphertext));
            return true;
        }, $this->connection);
    }

    public function claimCounter(MfaIdentity $identity, int $counter, string $secretHash): bool
    {
        self::assertCounter($counter);
        self::assertHash($secretHash);
        $row = $this->row($identity);
        if ($row === null || !self::enabled($row)) return false;
        if (!hash_equals($row['secret_hash'], $secretHash)) return false;
        $last = self::lastCounter($row);
        if ($last === null || $counter <= $last) return false;
        // This compare-and-swap prevents concurrent requests from replaying
        // one code or moving the counter backwards after a newer claim.
        return $this->database->table($this->credentialsTable, $this->connection)
            ->filter('id', self::rowId($row))
            ->filter('identity_digest', $identity->digest)
            ->filter('enabled', 1)
            ->filter('secret_hash', $secretHash)
            ->filter('last_counter', self::counterString($last))
            ->update(['last_counter' => self::counterString($counter)]) === 1;
    }

    public function claimRecoveryCode(MfaIdentity $identity, string $hash, string $secretHash): bool
    {
        self::assertHash($hash);
        self::assertHash($secretHash);
        $row = $this->row($identity);
        if ($row === null || !self::enabled($row)) return false;
        if (!hash_equals($row['secret_hash'], $secretHash)) return false;
        // A SELECT grants no ownership; only the conditional DELETE wins.
        return $this->database->table($this->recoveryTable, $this->connection)
            ->filter('credential_id', self::rowId($row))
            ->filter('code_hash', $hash)
            ->filter('secret_hash', $secretHash)->delete() === 1;
    }

    public function replaceRecoveryCodes(MfaIdentity $identity, array $hashes,
        string $secretHash): bool
    {
        self::assertHashes($hashes);
        self::assertHash($secretHash);
        return $this->database->transaction(function () use ($identity, $hashes, $secretHash): bool {
            $row = $this->row($identity);
            if ($row === null || !self::enabled($row)
                || !hash_equals($row['secret_hash'], $secretHash)) return false;
            $id = self::rowId($row);
            // Change a nonce through a conditional update so a concurrent
            // re-enrollment cannot slip between proof and code replacement.
            $updated = $this->database->table($this->credentialsTable, $this->connection)
                ->filter('id', $id)
                ->filter('identity_digest', $identity->digest)
                ->filter('enabled', 1)
                ->filter('secret_hash', $secretHash)
                ->filter('generation_nonce', $row['generation_nonce'])
                ->update(['generation_nonce' => bin2hex(random_bytes(16))]);
            if ($updated !== 1) return false;
            $this->database->table($this->recoveryTable, $this->connection)
                ->filter('credential_id', $id)->delete();
            $this->insertCodes($id, $hashes, $secretHash);
            return true;
        }, $this->connection);
    }

    public function disable(MfaIdentity $identity, string $secretHash): bool
    {
        self::assertHash($secretHash);
        $row = $this->row($identity);
        if ($row === null || !self::enabled($row)
            || !hash_equals($row['secret_hash'], $secretHash)) return false;
        // The recovery table's foreign key cascades in the same statement.
        return $this->database->table($this->credentialsTable, $this->connection)
            ->filter('id', self::rowId($row))
            ->filter('identity_digest', $identity->digest)
            ->filter('enabled', 1)
            ->filter('secret_hash', $secretHash)->delete() === 1;
    }

    /** @return array<string,mixed>|null */
    private function row(MfaIdentity $identity): ?array
    {
        $row = $this->database->table($this->credentialsTable, $this->connection)
            ->filter('identity_digest', $identity->digest)->first();
        if ($row === null) return null;
        if (($row['identity_digest'] ?? null) !== $identity->digest
            || ($row['guard'] ?? null) !== $identity->guard
            || ($row['identity_class'] ?? null) !== $identity->class
            || ($row['identity_kind'] ?? null) !== $identity->kind
            || ($row['identity_identifier'] ?? null) !== $identity->identifier) {
            throw new LogicException('Stored MFA identity digest is inconsistent.');
        }
        $enabled = self::enabled($row);
        $activeSecret = self::nullableCiphertext($row, 'encrypted_secret');
        $secretHash = $row['secret_hash'] ?? null;
        $nonce = $row['generation_nonce'] ?? null;
        $pendingSecret = self::nullableCiphertext($row, 'pending_secret');
        $pendingHash = $row['pending_secret_hash'] ?? null;
        $expiry = self::pendingExpiry($row);
        $lastCounter = self::lastCounter($row);
        if ($enabled) {
            if ($activeSecret === null || !is_string($secretHash)
                || !hash_equals(hash('sha256', $activeSecret), $secretHash)
                || !is_string($nonce) || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1
                || $pendingSecret !== null || $pendingHash !== null
                || $expiry !== null || $lastCounter === null) {
                throw new LogicException('Stored MFA enrollment is inconsistent.');
            }
        } elseif ($activeSecret !== null || $secretHash !== null || $nonce !== null
            || $pendingSecret === null
            || !is_string($pendingHash) || !hash_equals(hash('sha256', $pendingSecret), $pendingHash)
            || $expiry === null || $lastCounter !== null) {
            throw new LogicException('Stored MFA enrollment is inconsistent.');
        }
        return $row;
    }

    /** @param int|string $credentialId @param list<string> $hashes */
    private function insertCodes(int|string $credentialId, array $hashes, string $secretHash): void
    {
        if ($hashes === []) return;
        $rows = [];
        foreach ($hashes as $hash) {
            $rows[] = ['credential_id' => $credentialId, 'code_hash' => $hash,
                'secret_hash' => $secretHash];
        }
        $this->database->table($this->recoveryTable, $this->connection)->insertMany($rows);
    }

    /** An existing row cannot justify swallowing a deadlock or failed transaction. */
    private static function duplicateIdentity(QueryException $failure, string $table): bool
    {
        $cause = $failure->getPrevious();
        if (!$cause instanceof PDOException || !is_array($cause->errorInfo)
            || ($cause->errorInfo[0] ?? null) !== '23000') return false;
        $native = $cause->errorInfo[1] ?? null;
        if ($native === 1062 || $native === '1062') return true;
        if (!in_array($native, [19, '19', 1555, '1555', 2067, '2067'], true)) return false;
        return ($cause->errorInfo[2] ?? null)
            === 'UNIQUE constraint failed: ' . $table . '.identity_digest';
    }

    /** @param array<string,mixed> $row */
    private static function rowId(array $row): int|string
    {
        $id = $row['id'] ?? null;
        if (is_int($id) && $id > 0) return $id;
        if (is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1) return $id;
        throw new LogicException('Stored MFA row identifier is invalid.');
    }

    /** @param array<string,mixed> $row */
    private static function enabled(array $row): bool
    {
        return match ($row['enabled'] ?? null) {
            0, '0' => false,
            1, '1' => true,
            default => throw new LogicException('Stored MFA status is invalid.'),
        };
    }

    /** @param array<string,mixed> $row */
    private static function nullableCiphertext(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if ($value !== null) {
            if (!is_string($value) || $value === '' || strlen($value) > 4096) {
                throw new LogicException('Stored MFA encrypted secret is invalid.');
            }
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function pendingExpiry(array $row): ?int
    {
        $value = $row['pending_expires_at'] ?? null;
        if ($value === null) return null;
        if (!is_string($value)) throw new LogicException('Stored MFA expiry is invalid.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new LogicException('Stored MFA expiry is invalid.');
        }
        return $date->getTimestamp();
    }

    /** @param array<string,mixed> $row */
    private static function lastCounter(array $row): ?int
    {
        $value = $row['last_counter'] ?? null;
        if ($value === null) return null;
        if (!is_string($value) || preg_match('/\A[0-9]{19}\z/D', $value) !== 1
            || $value > str_pad((string) PHP_INT_MAX, 19, '0', STR_PAD_LEFT)) {
            throw new LogicException('Stored MFA counter is invalid.');
        }
        return (int) $value;
    }

    private static function counterString(int $counter): string
    {
        return str_pad((string) $counter, 19, '0', STR_PAD_LEFT);
    }

    private static function assertCounter(int $counter): void
    {
        if ($counter < 0) throw new InvalidArgumentException('MFA counter is invalid.');
    }

    private static function assertCiphertext(string $ciphertext): void
    {
        if ($ciphertext === '' || strlen($ciphertext) > 4096) {
            throw new InvalidArgumentException('MFA encrypted secret is invalid.');
        }
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new InvalidArgumentException('MFA recovery hash is invalid.');
        }
    }

    private static function assertHashes(array $hashes): void
    {
        if (count($hashes) > 64 || !array_is_list($hashes)) {
            throw new InvalidArgumentException('MFA recovery hashes are invalid.');
        }
        $seen = [];
        foreach ($hashes as $hash) {
            if (!is_string($hash)) throw new InvalidArgumentException('MFA recovery hash is invalid.');
            self::assertHash($hash);
            if (isset($seen[$hash])) throw new InvalidArgumentException('MFA recovery hashes repeat.');
            $seen[$hash] = true;
        }
    }

    private static function utc(int $epoch): string
    {
        if ($epoch < 0 || $epoch > self::MAX_EPOCH) {
            throw new InvalidArgumentException('MFA enrollment time is invalid.');
        }
        return (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
