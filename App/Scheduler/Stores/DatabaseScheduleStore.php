<?php

declare(strict_types=1);

namespace App\Scheduler\Stores;

use App\Database\Connection;
use App\Database\Exception\QueryException;
use App\Database\Schema\Sql;
use App\Scheduler\ScheduleStore;
use App\Scheduler\SchedulerException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Uses unique database keys and guarded updates for cooperating processes.
 * The insert is the occurrence claim; an expired overlap lock can be taken by
 * one later runner, while token checks prevent its previous owner releasing it.
 */
final class DatabaseScheduleStore implements ScheduleStore
{
    private string $runs;
    private string $locks;

    public function __construct(private Connection $connection, string $runs = 'schedule_runs',
        string $locks = 'schedule_locks')
    {
        $this->runs = Sql::name($runs);
        $this->locks = Sql::name($locks);
    }

    public function claim(string $taskKey, string $occurrence, DateTimeImmutable $now): bool
    {
        try {
            $this->connection->raw('INSERT INTO ' . $this->runs
                . ' (`task_key`,`occurrence_at`,`status`,`started_at`,`finished_at`) VALUES (?,?,?,?,NULL)',
                [$taskKey, $occurrence, 'running', self::stamp($now)]);
            return true;
        } catch (QueryException $exception) {
            // Only an existing unique occurrence is a normal skip. Missing
            // schema, permissions, and other backend errors remain failures.
            $existing = $this->connection->raw('SELECT `task_key` FROM ' . $this->runs
                . ' WHERE `task_key` = ? AND `occurrence_at` = ?', [$taskKey, $occurrence])->fetch();
            if ($existing !== false) return false;
            throw $exception;
        }
    }

    public function finish(string $taskKey, string $occurrence, string $status, DateTimeImmutable $now): void
    {
        if (!in_array($status, ['completed', 'failed'], true)) throw new SchedulerException('Schedule status is invalid.');
        $updated = $this->connection->raw('UPDATE ' . $this->runs
            . ' SET `status` = ?, `finished_at` = ? WHERE `task_key` = ? AND `occurrence_at` = ? AND `status` = ?',
            [$status, self::stamp($now), $taskKey, $occurrence, 'running']);
        if ($updated->rowCount() !== 1) throw new SchedulerException('Schedule occurrence could not be settled.');
    }

    public function acquireOverlap(string $taskKey, DateTimeImmutable $now, int $seconds): ?string
    {
        $token = bin2hex(random_bytes(16));
        $until = self::stamp($now->modify('+' . $seconds . ' seconds'));
        try {
            $this->connection->raw('INSERT INTO ' . $this->locks
                . ' (`task_key`,`token`,`expires_at`) VALUES (?,?,?)', [$taskKey, $token, $until]);
            return $token;
        } catch (QueryException $exception) {
            $existing = $this->connection->raw('SELECT `task_key` FROM ' . $this->locks
                . ' WHERE `task_key` = ?', [$taskKey])->fetch();
            if ($existing === false) throw $exception;
        }
        $updated = $this->connection->raw('UPDATE ' . $this->locks
            . ' SET `token` = ?, `expires_at` = ? WHERE `task_key` = ? AND `expires_at` <= ?',
            [$token, $until, $taskKey, self::stamp($now)]);
        return $updated->rowCount() === 1 ? $token : null;
    }

    public function releaseOverlap(string $taskKey, string $token): void
    {
        $this->connection->raw('DELETE FROM ' . $this->locks . ' WHERE `task_key` = ? AND `token` = ?',
            [$taskKey, $token]);
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
