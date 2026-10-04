<?php

declare(strict_types=1);

namespace App\Scheduler\Stores;

use App\Scheduler\ScheduleStore;
use DateTimeImmutable;

/** Process-local store for isolated tests and deliberate single-process use. */
final class ArrayScheduleStore implements ScheduleStore
{
    /** @var array<string, string> */
    private array $runs = [];
    /** @var array<string, array{token:string,until:int}> */
    private array $locks = [];

    public function claim(string $taskKey, string $occurrence, DateTimeImmutable $now): bool
    {
        $key = $taskKey . ':' . $occurrence;
        if (isset($this->runs[$key])) return false;
        $this->runs[$key] = 'running';
        return true;
    }

    public function finish(string $taskKey, string $occurrence, string $status, DateTimeImmutable $now): void
    {
        $key = $taskKey . ':' . $occurrence;
        if (!isset($this->runs[$key])) throw new \App\Scheduler\SchedulerException('Schedule occurrence is not claimed.');
        $this->runs[$key] = $status;
    }

    public function acquireOverlap(string $taskKey, DateTimeImmutable $now, int $seconds): ?string
    {
        if (isset($this->locks[$taskKey]) && $this->locks[$taskKey]['until'] > $now->getTimestamp()) return null;
        $token = bin2hex(random_bytes(16));
        $this->locks[$taskKey] = ['token' => $token, 'until' => $now->getTimestamp() + $seconds];
        return $token;
    }

    public function releaseOverlap(string $taskKey, string $token): void
    {
        if (($this->locks[$taskKey]['token'] ?? null) === $token) unset($this->locks[$taskKey]);
    }
}
