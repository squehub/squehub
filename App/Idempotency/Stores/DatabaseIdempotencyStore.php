<?php

declare(strict_types=1);

namespace App\Idempotency\Stores;

use App\Database\Connection;
use App\Database\Exception\QueryException;
use App\Idempotency\ClaimResult;
use App\Idempotency\IdempotencyException;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Record;
use App\Idempotency\ResponseSnapshot;
use JsonException;
use PDOException;

/** Unique scope index and owner-conditional statements arbitrate SQL workers. */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(private Connection $connection)
    {
        if (!in_array($connection->driver(), ['sqlite', 'mysql'], true)) {
            throw new IdempotencyException('Idempotency database driver is unsupported.');
        }
    }

    public function claim(string $scope, string $fingerprint, string $owner, int $now,
        int $leaseSeconds, int $retentionSeconds): ClaimResult
    {
        Record::scope($scope);
        $fresh = Record::fresh($fingerprint, $owner, $now, $leaseSeconds, $retentionSeconds);
        $lease = self::date($fresh['lease_until']);
        $expiry = self::date($fresh['expires_at']);
        $current = self::date($now);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $this->connection->raw('INSERT INTO `idempotency_records` '
                    . '(`scope_hash`, `fingerprint`, `state`, `owner_token`, `lease_until`, `expires_at`, `snapshot`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, NULL)',
                    [$scope, $fingerprint, 'processing', $owner, $lease, $expiry]);
                return new ClaimResult('claimed');
            } catch (QueryException $failure) {
                if (!$this->isScopeDuplicate($failure)) throw $failure;
            }
            // Expired records can be replaced by any request. A stale lease
            // can only be reclaimed by its original fingerprint until retention.
            $updated = $this->connection->raw('UPDATE `idempotency_records` '
                . 'SET `fingerprint` = ?, `state` = ?, `owner_token` = ?, '
                . '`lease_until` = ?, `expires_at` = ?, `snapshot` = NULL '
                . 'WHERE `scope_hash` = ? AND (`expires_at` <= ? '
                . 'OR (`state` = ? AND `lease_until` <= ? AND `fingerprint` = ?))',
                [$fingerprint, 'processing', $owner, $lease, $expiry, $scope,
                    $current, 'processing', $current, $fingerprint]);
            if ($updated->rowCount() === 1) return new ClaimResult('claimed');
            $row = $this->connection->raw('SELECT `fingerprint`, `state`, `snapshot` '
                . 'FROM `idempotency_records` WHERE `scope_hash` = ?', [$scope])->fetch();
            if ($row !== false) {
                if (!is_array($row) || !is_string($row['fingerprint'] ?? null)
                    || !in_array($row['state'] ?? null, ['processing', 'complete'], true)) {
                    throw new IdempotencyException('Idempotency database record is corrupt.');
                }
                $snapshot = null;
                if ($row['snapshot'] !== null) {
                    if (!is_string($row['snapshot']) || strlen($row['snapshot']) > 48000) {
                        throw new IdempotencyException('Idempotency database record is corrupt.');
                    }
                    try {
                        $snapshot = json_decode($row['snapshot'], true, 8, JSON_THROW_ON_ERROR);
                    } catch (JsonException $failure) {
                        throw new IdempotencyException('Idempotency database record is corrupt.', 0, $failure);
                    }
                }
                return ClaimResult::fromRecord([
                    'fingerprint' => $row['fingerprint'], 'state' => $row['state'],
                    'snapshot' => $snapshot,
                ], $fingerprint);
            }
            // A first owner may have abandoned between duplicate INSERT and
            // SELECT. Retry the unique insert once instead of guessing state.
        }
        throw new IdempotencyException('Idempotency claim changed concurrently.');
    }

    public function complete(string $scope, string $owner, int $now, ?ResponseSnapshot $snapshot): bool
    {
        Record::scope($scope);
        Record::identity(str_repeat('0', 64), $owner);
        $encoded = null;
        if ($snapshot !== null) {
            try {
                $encoded = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR);
            } catch (JsonException $failure) {
                throw new IdempotencyException('Idempotency snapshot cannot be encoded.', 0, $failure);
            }
        }
        $updated = $this->connection->raw('UPDATE `idempotency_records` '
            . 'SET `state` = ?, `owner_token` = NULL, `lease_until` = NULL, `snapshot` = ? '
            . 'WHERE `scope_hash` = ? AND `state` = ? AND `owner_token` = ? '
            . 'AND `lease_until` > ? AND `expires_at` > ?',
            ['complete', $encoded, $scope, 'processing', $owner, self::date($now), self::date($now)]);
        return $updated->rowCount() === 1;
    }

    public function abandon(string $scope, string $owner): bool
    {
        Record::scope($scope);
        Record::identity(str_repeat('0', 64), $owner);
        $deleted = $this->connection->raw('DELETE FROM `idempotency_records` '
            . 'WHERE `scope_hash` = ? AND `state` = ? AND `owner_token` = ?',
            [$scope, 'processing', $owner]);
        return $deleted->rowCount() === 1;
    }

    public function prune(int $now, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) throw new IdempotencyException('Invalid idempotency prune limit.');
        $rows = $this->connection->raw('SELECT `scope_hash` FROM `idempotency_records` '
            . 'WHERE `expires_at` <= ? ORDER BY `expires_at` LIMIT ' . $limit,
            [self::date($now)])->fetchAll();
        $count = 0;
        foreach ($rows as $row) {
            $count += $this->connection->raw('DELETE FROM `idempotency_records` '
                . 'WHERE `scope_hash` = ? AND `expires_at` <= ?',
                [$row['scope_hash'], self::date($now)])->rowCount();
        }
        return $count;
    }

    private function isScopeDuplicate(QueryException $exception): bool
    {
        $failure = $exception->getPrevious();
        if (!$failure instanceof PDOException || ($failure->errorInfo[0] ?? '') !== '23000') return false;
        $code = (int) ($failure->errorInfo[1] ?? 0);
        $detail = strtolower((string) ($failure->errorInfo[2] ?? ''));
        return match ($this->connection->driver()) {
            'mysql' => $code === 1062 && str_contains($detail, 'idempotency_scope_unique'),
            'sqlite' => in_array($code, [19, 2067], true)
                && str_contains($detail, 'unique constraint failed: idempotency_records.scope_hash'),
            default => false,
        };
    }

    private static function date(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
