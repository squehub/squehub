<?php

declare(strict_types=1);

namespace App\Queue\Drivers;

use App\Database\Connection;
use App\Database\ModelClock;
use App\Database\Schema\Sql;
use App\Queue\Composition\CompositionDriver;
use App\Queue\Composition\CompositionStatus;
use App\Queue\CorrelatedQueueDriver;
use App\Queue\PersistentQueueDriver;
use App\Queue\FailedQueueDriver;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueStatus;
use App\Queue\QueueStatusDriver;
use App\Queue\ReservedJob;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Stores durable jobs on an existing Database connection without owning PDO.
 * The conditional UPDATE is the claim point; acknowledgement and retry use
 * the random token to reject stale workers after reservation expiry.
 * Side effects may repeat after a worker crashes before acknowledgement.
 */
final class DatabaseQueueDriver implements CompositionDriver, FailedQueueDriver, QueueStatusDriver, CorrelatedQueueDriver
{
    private string $jobs;
    private string $failed;
    private string $failedTableName;
    private string $jobTableName;
    private ?bool $compositionColumns = null;
    private const COMPOSITIONS = '`queue_compositions`';
    private const ITEMS = '`queue_composition_items`';

    public function __construct(
        private Connection $connection,
        private ModelClock $clock,
        string $table = 'queue_jobs',
        string $failedTable = 'queue_failed_jobs',
        private int $retryAfter = 60
    ) {
        $this->jobs = Sql::name($table);
        $this->jobTableName = $table;
        $this->failed = Sql::name($failedTable);
        $this->failedTableName = $failedTable;
        if ($retryAfter < 1) throw new QueueException('Queue reservation timeout must be positive.');
    }

    public function dispatch(QueueJob $job, string $queue, int $delay): void
    {
        $this->dispatchCorrelated($job, $queue, $delay, null);
    }

    public function dispatchCorrelated(QueueJob $job, string $queue, int $delay,
        ?string $correlationId): void
    {
        $payload = QueueCodec::encode($job, $correlationId);
        $now = $this->now();
        $this->connection->raw('INSERT INTO ' . $this->jobs
            . ' (`queue`, `payload`, `attempts`, `available_at`, `reserved_at`, `reservation_token`, `created_at`)'
            . ' VALUES (?, ?, 0, ?, NULL, NULL, ?)',
            [$queue, $payload, $this->stamp($now->modify('+' . $delay . ' seconds')), $this->stamp($now)]);
    }

    /**
     * Counts are a point-in-time snapshot. Expired leases count as ready because
     * the next reservation can reclaim them; no count proves a worker is alive.
     */
    public function status(string $queue): QueueStatus
    {
        \App\Queue\QueueManager::name($queue);
        $now = $this->now();
        $stamp = $this->stamp($now);
        $cutoff = $this->stamp($now->modify('-' . $this->retryAfter . ' seconds'));
        // One statement keeps the three per-queue categories internally
        // consistent while workers claim or settle records concurrently.
        $counts = $this->connection->raw('SELECT '
            . 'COALESCE(SUM(CASE WHEN `available_at` <= ?'
            . ' AND (`reserved_at` IS NULL OR `reserved_at` <= ?) THEN 1 ELSE 0 END), 0), '
            . 'COALESCE(SUM(CASE WHEN `available_at` > ? AND `reserved_at` IS NULL'
            . ' THEN 1 ELSE 0 END), 0), '
            . 'COALESCE(SUM(CASE WHEN `reserved_at` > ? THEN 1 ELSE 0 END), 0)'
            . ' FROM ' . $this->jobs . ' WHERE `queue` = ?',
            [$stamp, $cutoff, $stamp, $cutoff, $queue])->fetch(PDO::FETCH_NUM);
        if (!is_array($counts) || count($counts) !== 3) {
            throw new QueueException('Queue status response is invalid.');
        }
        $failed = (int) $this->connection->raw('SELECT COUNT(*) FROM ' . $this->failed)->fetchColumn();
        return new QueueStatus((int) $counts[0], (int) $counts[1], (int) $counts[2], $failed);
    }

    public function reserve(string $queue): ?ReservedJob
    {
        $now = $this->now();
        $stamp = $this->stamp($now);
        $cutoff = $this->stamp($now->modify('-' . $this->retryAfter . ' seconds'));
        // Selection is advisory. The guarded UPDATE is the actual atomic
        // claim, so two workers that observed one row cannot both own it.
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $columns = $this->hasCompositionColumns()
                ? ', `composition_id`, `composition_position`' : '';
            $row = $this->connection->raw('SELECT `id`, `payload`, `attempts`' . $columns . ' FROM ' . $this->jobs
                . ' WHERE `queue` = ? AND `available_at` <= ?'
                . ' AND (`reserved_at` IS NULL OR `reserved_at` <= ?)'
                . ' ORDER BY `available_at`, `id` LIMIT 1', [$queue, $stamp, $cutoff])->fetch();
            if ($row === false) return null;
            $token = bin2hex(random_bytes(16));
            $claim = $this->connection->raw('UPDATE ' . $this->jobs
                . ' SET `reserved_at` = ?, `reservation_token` = ?, `attempts` = `attempts` + 1'
                . ' WHERE `id` = ? AND `queue` = ? AND `available_at` <= ?'
                . ' AND (`reserved_at` IS NULL OR `reserved_at` <= ?) AND `attempts` < 2147483647',
                [$stamp, $token, (int) $row['id'], $queue, $stamp, $cutoff]);
            if ($claim->rowCount() === 1) {
                return new ReservedJob((int) $row['id'], $queue, (string) $row['payload'],
                    (int) $row['attempts'] + 1, $token,
                    isset($row['composition_id']) ? (string) $row['composition_id'] : null,
                    isset($row['composition_position']) ? (int) $row['composition_position'] : null);
            }
        }
        return null;
    }

    public function acknowledge(ReservedJob $job): void
    {
        if ($job->compositionId !== null) {
            $this->settleComposition($job, 'succeeded');
            return;
        }
        $removed = $this->connection->raw('DELETE FROM ' . $this->jobs
            . ' WHERE `id` = ? AND `reservation_token` = ?', [$job->id, $job->token]);
        if ($removed->rowCount() !== 1) throw new QueueException('Queue reservation is no longer owned.');
    }

    public function release(ReservedJob $job, int $backoff): void
    {
        if ($backoff < 0) throw new QueueException('Queue backoff cannot be negative.');
        $available = $this->stamp($this->now()->modify('+' . $backoff . ' seconds'));
        $updated = $this->connection->raw('UPDATE ' . $this->jobs
            . ' SET `available_at` = ?, `reserved_at` = NULL, `reservation_token` = NULL'
            . ' WHERE `id` = ? AND `reservation_token` = ?', [$available, $job->id, $job->token]);
        if ($updated->rowCount() !== 1) throw new QueueException('Queue reservation is no longer owned.');
    }

    public function fail(ReservedJob $job, string $jobClass, string $type, string $reason): void
    {
        if ($jobClass !== 'unknown' && !QueueCodec::validClass($jobClass)) {
            throw new QueueException('Failed Queue job class is invalid.');
        }
        // Recording failure and removing the active row share one transaction.
        // A failed insert cannot silently lose the original queued job.
        $this->connection->transaction(function () use ($job, $jobClass, $type, $reason): void {
            if ($job->compositionId !== null) $this->lockComposition($job->compositionId);
            $removed = $this->connection->raw('DELETE FROM ' . $this->jobs
                . ' WHERE `id` = ? AND `reservation_token` = ?', [$job->id, $job->token]);
            if ($removed->rowCount() !== 1) throw new QueueException('Queue reservation is no longer owned.');
            $columns = $this->connection->schema()->hasColumn($this->failedTableName, 'payload')
                ? ' (`queue`, `job_class`, `error_type`, `error_message`, `attempts`, `failed_at`, `payload`)'
                : ' (`queue`, `job_class`, `error_type`, `error_message`, `attempts`, `failed_at`)';
            $values = [$job->queue, $jobClass, substr($type, 0, 255),
                substr($reason, 0, 255), $job->attempts, $this->stamp($this->now())];
            if (str_contains($columns, '`payload`')) $values[] = $job->payload;
            $this->connection->raw('INSERT INTO ' . $this->failed . $columns
                . ' VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')', $values);
            if ($job->compositionId !== null) {
                $this->completeItem($job, 'failed');
            }
        });
    }

    /** @return list<array{id:int,queue:string,job_class:string,error_type:string,attempts:int,failed_at:string}> */
    public function failed(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) throw new QueueException('Failed-job list limit is invalid.');
        $rows = $this->connection->raw('SELECT `id`, `queue`, `job_class`, `error_type`, `attempts`, `failed_at`'
            . ' FROM ' . $this->failed . ' ORDER BY `id` DESC LIMIT ' . $limit)->fetchAll();
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'queue' => (string) $row['queue'],
            'job_class' => (string) $row['job_class'], 'error_type' => (string) $row['error_type'],
            'attempts' => (int) $row['attempts'], 'failed_at' => (string) $row['failed_at'],
        ], $rows);
    }

    /** Atomically requeue a failed job and remove its failure record. */
    public function retry(int $id): bool
    {
        if ($id < 1) throw new QueueException('Failed-job ID is invalid.');
        if (!$this->connection->schema()->hasColumn($this->failedTableName, 'payload')) {
            throw new QueueException('Failed-job retry requires the Queue payload migration.');
        }
        return $this->connection->transaction(function () use ($id): bool {
            $row = $this->connection->raw('SELECT `queue`, `payload` FROM ' . $this->failed
                . ' WHERE `id` = ?', [$id])->fetch();
            if ($row === false) return false;
            if (!is_string($row['payload']) || $row['payload'] === '') {
                throw new QueueException('Failed job has no retryable payload.');
            }
            // Validate the retained versioned envelope before making a new
            // active record. Never print its data in operational output.
            QueueCodec::decode($row['payload']);
            $stamp = $this->stamp($this->now());
            $this->connection->raw('INSERT INTO ' . $this->jobs
                . ' (`queue`, `payload`, `attempts`, `available_at`, `reserved_at`, `reservation_token`, `created_at`)'
                . ' VALUES (?, ?, 0, ?, NULL, NULL, ?)',
                [(string) $row['queue'], $row['payload'], $stamp, $stamp]);
            $removed = $this->connection->raw('DELETE FROM ' . $this->failed . ' WHERE `id` = ?', [$id]);
            if ($removed->rowCount() !== 1) throw new QueueException('Failed job changed during retry.');
            return true;
        });
    }

    public function forget(int $id): bool
    {
        if ($id < 1) throw new QueueException('Failed-job ID is invalid.');
        return $this->connection->raw('DELETE FROM ' . $this->failed . ' WHERE `id` = ?', [$id])->rowCount() === 1;
    }

    /** Remove only failures older than the chosen number of hours. */
    public function prune(int $hours = 168): int
    {
        if ($hours < 1 || $hours > 87600) throw new QueueException('Failed-job retention is invalid.');
        $cutoff = $this->stamp($this->now()->modify('-' . $hours . ' hours'));
        return $this->connection->raw('DELETE FROM ' . $this->failed
            . ' WHERE `failed_at` < ?', [$cutoff])->rowCount();
    }

    /**
     * The metadata and first/all eligible jobs share one database transaction.
     * A caller-owned transaction on this Connection can roll the entire
     * composition back with its business write.
     *
     * @param list<string> $encodedJobs
     */
    public function createComposition(string $id, string $kind, array $encodedJobs,
        string $queue, int $retentionHours): void
    {
        $this->requireCompositionSchema();
        if (!in_array($kind, ['chain', 'batch'], true) || $encodedJobs === []
            || !array_is_list($encodedJobs) || count($encodedJobs) > 100
            || $retentionHours < 1 || $retentionHours > 87600) {
            throw new QueueException('Queue composition definition is invalid.');
        }
        $this->connection->transaction(function () use ($id, $kind, $encodedJobs, $queue): void {
            $stamp = $this->stamp($this->now());
            $this->connection->raw('INSERT INTO ' . self::COMPOSITIONS
                . ' (`id`, `kind`, `queue`, `state`, `total`, `succeeded`, `failed`, `cancelled`,'
                . ' `created_at`, `finished_at`) VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, NULL)',
                [$id, $kind, $queue, 'active', count($encodedJobs), $stamp]);
            foreach ($encodedJobs as $position => $payload) {
                $ready = $kind === 'batch' || $position === 0;
                $this->connection->raw('INSERT INTO ' . self::ITEMS
                    . ' (`composition_id`, `position`, `state`, `queue_job_id`, `payload`)'
                    . ' VALUES (?, ?, ?, NULL, ?)',
                    [$id, $position, $ready ? 'queued' : 'pending', $ready ? null : $payload]);
                if (!$ready) continue;
                $jobId = $this->insertComposedJob($queue, $payload, $id, $position, $stamp);
                $this->connection->raw('UPDATE ' . self::ITEMS
                    . ' SET `queue_job_id` = ? WHERE `composition_id` = ? AND `position` = ?',
                    [$jobId, $id, $position]);
            }
        });
    }

    public function compositionStatus(string $id): ?CompositionStatus
    {
        $this->requireCompositionSchema();
        $row = $this->connection->raw('SELECT `kind`, `state`, `total`, `succeeded`, `failed`, `cancelled`'
            . ' FROM ' . self::COMPOSITIONS . ' WHERE `id` = ?', [$id])->fetch(PDO::FETCH_NUM);
        if ($row === false) return null;
        return new CompositionStatus($id, (string) $row[0], (string) $row[1],
            (int) $row[2], (int) $row[3], (int) $row[4], (int) $row[5]);
    }

    public function shouldRunComposition(ReservedJob $job): bool
    {
        if ($job->compositionId === null || $job->compositionPosition === null) {
            throw new QueueException('Queue composition reservation is invalid.');
        }
        $row = $this->connection->raw('SELECT c.`state`, i.`state` FROM ' . self::COMPOSITIONS
            . ' c JOIN ' . self::ITEMS . ' i ON i.`composition_id` = c.`id`'
            . ' JOIN ' . $this->jobs . ' j ON j.`composition_id` = c.`id`'
            . ' AND j.`composition_position` = i.`position`'
            . ' WHERE c.`id` = ? AND i.`position` = ? AND i.`queue_job_id` = ?'
            . ' AND j.`id` = ? AND j.`reservation_token` = ?',
            [$job->compositionId, $job->compositionPosition, $job->id, $job->id, $job->token])
            ->fetch(PDO::FETCH_NUM);
        if ($row === false) throw new QueueException('Queue composition reservation is no longer owned.');
        return $row[0] === 'active' && $row[1] === 'queued';
    }

    public function skipComposition(ReservedJob $job): void
    {
        $this->settleComposition($job, 'cancelled');
    }

    public function cancelComposition(string $id): bool
    {
        $this->requireCompositionSchema();
        return $this->connection->transaction(function () use ($id): bool {
            $row = $this->lockComposition($id, false);
            if ($row === null || $row['state'] !== 'active') return false;
            $this->connection->raw('UPDATE ' . self::COMPOSITIONS
                . ' SET `state` = ? WHERE `id` = ?', ['cancelling', $id]);
            $items = $this->connection->raw('SELECT `position`, `state`, `queue_job_id`'
                . ' FROM ' . self::ITEMS . ' WHERE `composition_id` = ?'
                . ' AND `state` IN (?, ?) ORDER BY `position`', [$id, 'pending', 'queued'])
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as $item) {
                $position = (int) $item['position'];
                if ($item['state'] === 'queued') {
                    $removed = $this->connection->raw('DELETE FROM ' . $this->jobs
                        . ' WHERE `id` = ? AND `composition_id` = ?'
                        . ' AND `composition_position` = ? AND `reserved_at` IS NULL',
                        [(int) $item['queue_job_id'], $id, $position]);
                    if ($removed->rowCount() !== 1) continue;
                }
                $this->connection->raw('UPDATE ' . self::ITEMS
                    . ' SET `state` = ?, `queue_job_id` = NULL, `payload` = NULL'
                    . ' WHERE `composition_id` = ? AND `position` = ? AND `state` IN (?, ?)',
                    ['cancelled', $id, $position, 'pending', 'queued']);
            }
            $this->syncCompositionCounts($id, 'cancelling', $row['kind'], $row['total']);
            return true;
        });
    }

    public function pruneCompositions(int $hours): int
    {
        $this->requireCompositionSchema();
        if ($hours < 1 || $hours > 87600) throw new QueueException('Queue composition retention is invalid.');
        $cutoff = $this->stamp($this->now()->modify('-' . $hours . ' hours'));
        return $this->connection->transaction(function () use ($cutoff): int {
            $ids = $this->connection->raw('SELECT `id` FROM ' . self::COMPOSITIONS
                . ' WHERE `finished_at` IS NOT NULL AND `finished_at` < ?'
                . ' ORDER BY `finished_at`, `id` LIMIT 1000', [$cutoff])->fetchAll(PDO::FETCH_COLUMN);
            $removed = 0;
            foreach ($ids as $id) {
                $this->connection->raw('DELETE FROM ' . self::ITEMS
                    . ' WHERE `composition_id` = ?', [(string) $id]);
                $deleted = $this->connection->raw('DELETE FROM ' . self::COMPOSITIONS
                    . ' WHERE `id` = ? AND `finished_at` IS NOT NULL AND `finished_at` < ?',
                    [(string) $id, $cutoff]);
                $removed += $deleted->rowCount();
            }
            return $removed;
        });
    }

    /** One lock order for completion, failure and cancellation avoids deadlocks. */
    private function lockComposition(string $id, bool $required = true): ?array
    {
        $this->connection->raw('UPDATE ' . self::COMPOSITIONS
            . ' SET `state` = `state` WHERE `id` = ?', [$id]);
        $row = $this->connection->raw('SELECT `kind`, `queue`, `state`, `total`'
            . ' FROM ' . self::COMPOSITIONS . ' WHERE `id` = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            if ($required) throw new QueueException('Queue composition state is unavailable.');
            return null;
        }
        return ['kind' => (string) $row['kind'], 'queue' => (string) $row['queue'],
            'state' => (string) $row['state'], 'total' => (int) $row['total']];
    }

    private function settleComposition(ReservedJob $job, string $outcome): void
    {
        if ($job->compositionId === null || $job->compositionPosition === null) {
            throw new QueueException('Queue composition reservation is invalid.');
        }
        $this->connection->transaction(function () use ($job, $outcome): void {
            $composition = $this->lockComposition($job->compositionId);
            if ($outcome === 'cancelled' && $composition['state'] === 'active') {
                throw new QueueException('Active Queue composition cannot skip an item.');
            }
            $removed = $this->connection->raw('DELETE FROM ' . $this->jobs
                . ' WHERE `id` = ? AND `reservation_token` = ? AND `composition_id` = ?'
                . ' AND `composition_position` = ?',
                [$job->id, $job->token, $job->compositionId, $job->compositionPosition]);
            if ($removed->rowCount() !== 1) throw new QueueException('Queue reservation is no longer owned.');
            $this->completeItem($job, $outcome, $composition);
        });
    }

    /** Called only inside a transaction after the composition row is locked. */
    private function completeItem(ReservedJob $job, string $outcome, ?array $composition = null): void
    {
        $id = $job->compositionId;
        $position = $job->compositionPosition;
        if ($id === null || $position === null) throw new QueueException('Queue composition reservation is invalid.');
        $composition ??= $this->lockComposition($id);
        $changed = $this->connection->raw('UPDATE ' . self::ITEMS
            . ' SET `state` = ?, `queue_job_id` = NULL, `payload` = NULL'
            . ' WHERE `composition_id` = ? AND `position` = ? AND `state` = ? AND `queue_job_id` = ?',
            [$outcome, $id, $position, 'queued', $job->id]);
        if ($changed->rowCount() !== 1) throw new QueueException('Queue composition item already settled.');
        if ($composition['kind'] === 'chain' && $outcome === 'failed') {
            // A failed step has no successor. Clear dormant sensitive payloads.
            $this->connection->raw('UPDATE ' . self::ITEMS
                . ' SET `state` = ?, `payload` = NULL WHERE `composition_id` = ? AND `state` = ?',
                ['cancelled', $id, 'pending']);
        } elseif ($composition['kind'] === 'chain' && $outcome === 'succeeded'
            && $composition['state'] === 'active') {
            $next = $this->connection->raw('SELECT `payload` FROM ' . self::ITEMS
                . ' WHERE `composition_id` = ? AND `position` = ? AND `state` = ?',
                [$id, $position + 1, 'pending'])->fetchColumn();
            if (is_string($next)) {
                $jobId = $this->insertComposedJob($composition['queue'], $next, $id,
                    $position + 1, $this->stamp($this->now()));
                $advanced = $this->connection->raw('UPDATE ' . self::ITEMS
                    . ' SET `state` = ?, `queue_job_id` = ?, `payload` = NULL'
                    . ' WHERE `composition_id` = ? AND `position` = ? AND `state` = ?',
                    ['queued', $jobId, $id, $position + 1, 'pending']);
                if ($advanced->rowCount() !== 1) {
                    throw new QueueException('Queue chain advancement was not owned.');
                }
            }
        }
        $this->syncCompositionCounts($id, $composition['state'],
            $composition['kind'], $composition['total']);
    }

    private function syncCompositionCounts(string $id, string $currentState,
        string $kind, int $total): void
    {
        $counts = $this->connection->raw('SELECT '
            . 'COALESCE(SUM(CASE WHEN `state` = ? THEN 1 ELSE 0 END), 0), '
            . 'COALESCE(SUM(CASE WHEN `state` = ? THEN 1 ELSE 0 END), 0), '
            . 'COALESCE(SUM(CASE WHEN `state` = ? THEN 1 ELSE 0 END), 0)'
            . ' FROM ' . self::ITEMS . ' WHERE `composition_id` = ?',
            ['succeeded', 'failed', 'cancelled', $id])->fetch(PDO::FETCH_NUM);
        if (!is_array($counts)) throw new QueueException('Queue composition counters are unavailable.');
        [$succeeded, $failed, $cancelled] = array_map('intval', $counts);
        $terminal = $succeeded + $failed + $cancelled;
        if ($terminal > $total) throw new QueueException('Queue composition counters are invalid.');
        $state = $currentState;
        if ($kind === 'chain' && $failed > 0) $state = 'failed';
        elseif ($currentState === 'cancelling' && $terminal === $total) $state = 'cancelled';
        elseif ($currentState === 'active' && $terminal === $total) {
            $state = $failed > 0 ? 'completed_with_failures' : 'completed';
        }
        $finished = in_array($state,
            ['completed', 'completed_with_failures', 'failed', 'cancelled'], true)
            ? $this->stamp($this->now()) : null;
        $this->connection->raw('UPDATE ' . self::COMPOSITIONS
            . ' SET `state` = ?, `succeeded` = ?, `failed` = ?, `cancelled` = ?, `finished_at` = ?'
            . ' WHERE `id` = ?', [$state, $succeeded, $failed, $cancelled, $finished, $id]);
    }

    private function insertComposedJob(string $queue, string $payload, string $id,
        int $position, string $stamp): int
    {
        $this->connection->raw('INSERT INTO ' . $this->jobs
            . ' (`queue`, `payload`, `attempts`, `available_at`, `reserved_at`,'
            . ' `reservation_token`, `created_at`, `composition_id`, `composition_position`)'
            . ' VALUES (?, ?, 0, ?, NULL, NULL, ?, ?, ?)',
            [$queue, $payload, $stamp, $stamp, $id, $position]);
        $jobId = (int) $this->connection->pdo()->lastInsertId();
        if ($jobId < 1) throw new QueueException('Queue composition job ID is invalid.');
        return $jobId;
    }

    private function hasCompositionColumns(): bool
    {
        return $this->compositionColumns ??= $this->jobTableName === 'queue_jobs'
            && $this->connection->schema()->hasColumn($this->jobTableName, 'composition_id')
            && $this->connection->schema()->hasColumn($this->jobTableName, 'composition_position');
    }

    private function requireCompositionSchema(): void
    {
        if (!$this->hasCompositionColumns()
            || !$this->connection->schema()->hasTable('queue_compositions')
            || !$this->connection->schema()->hasTable('queue_composition_items')) {
            throw new QueueException('Queue composition migration is required on the default Queue table.');
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function stamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s');
    }
}
