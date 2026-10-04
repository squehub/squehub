<?php

declare(strict_types=1);

namespace App\Locks\Stores;

use App\Database\Connection;
use App\Database\Exception\QueryException;
use App\Locks\LockBackendException;
use App\Locks\LockOwnershipException;
use App\Locks\LockStorageException;
use App\Locks\LockStore;
use PDOException;
use Throwable;

/** Unique-key insert and guarded transitions on one SQLite/MySQL connection. */
final class DatabaseLockStore implements LockStore
{
    public function __construct(private Connection $connection)
    {
        if (!in_array($connection->driver(), ['sqlite', 'mysql'], true)) {
            throw new LockBackendException('Lock database driver is unsupported.');
        }
    }

    public function acquire(string $hash, string $token, int $now, int $expiresAt): bool
    {
        try {
            $this->assertIndependentTransaction();
            $this->prepareSqlite();
            return $this->connection->transaction(function (Connection $connection) use ($hash, $token, $now, $expiresAt): bool {
                try {
                    $connection->raw('INSERT INTO `reliability_locks` (`key_hash`,`owner_token`,`expires_at`) VALUES (?,?,?)',
                        [$hash, $token, self::stamp($expiresAt)]);
                    return true;
                } catch (QueryException $failure) {
                    if (!self::uniqueConflict($failure)) throw $failure;
                }
                $updated = $connection->raw('UPDATE `reliability_locks` SET `owner_token` = ?, `expires_at` = ? '
                    . 'WHERE `key_hash` = ? AND `expires_at` <= ?',
                    [$token, self::stamp($expiresAt), $hash, self::stamp($now)]);
                if ($updated->rowCount() === 1) return true;
                $row = $connection->raw('SELECT `owner_token`,`expires_at` FROM `reliability_locks` '
                    . 'WHERE `key_hash` = ?', [$hash])->fetch();
                if ($row !== false) self::validateRow($row);
                return false;
            }, 3);
        } catch (LockBackendException $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new LockBackendException('Lock database operation failed.');
        }
    }

    public function release(string $hash, string $token, int $now): bool
    {
        try {
            $this->assertIndependentTransaction();
            $this->prepareSqlite();
            return $this->connection->transaction(function (Connection $connection) use ($hash, $token, $now): bool {
                $deleted = $connection->raw('DELETE FROM `reliability_locks` '
                    . 'WHERE `key_hash` = ? AND `owner_token` = ? AND `expires_at` > ?',
                    [$hash, $token, self::stamp($now)]);
                if ($deleted->rowCount() === 1) return true;
                $row = $connection->raw('SELECT `owner_token`,`expires_at` FROM `reliability_locks` '
                    . 'WHERE `key_hash` = ?', [$hash])->fetch();
                if ($row === false) return false;
                self::validateRow($row);
                if ($row['expires_at'] <= self::stamp($now)) return false;
                if (!hash_equals($row['owner_token'], $token)) {
                    throw new LockOwnershipException('Lock belongs to another owner.');
                }
                return false;
            });
        } catch (LockOwnershipException|LockBackendException $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new LockBackendException('Lock database operation failed.');
        }
    }

    private function prepareSqlite(): void
    {
        if ($this->connection->driver() === 'sqlite') {
            // Each process waits briefly for a concurrent writer rather than
            // mistaking SQLite's immediate BUSY reply for lock contention.
            $this->connection->pdo()->exec('PRAGMA busy_timeout = 5000');
        }
    }

    private function assertIndependentTransaction(): void
    {
        if ($this->connection->inTransaction() || $this->connection->pdo()->inTransaction()) {
            throw new LockBackendException('Lock database connection has an active transaction.');
        }
    }

    private static function uniqueConflict(QueryException $failure): bool
    {
        $previous = $failure->getPrevious();
        return $previous instanceof PDOException
            && in_array((string) $previous->getCode(), ['23000', '23505'], true);
    }

    /** @param array<string,mixed> $row */
    private static function validateRow(array $row): void
    {
        if (!is_string($row['owner_token'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $row['owner_token']) !== 1
            || !is_string($row['expires_at'] ?? null)
            || preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D', $row['expires_at']) !== 1) {
            throw new LockStorageException('Lock database row is corrupt.');
        }
    }

    private static function stamp(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch);
    }
}
