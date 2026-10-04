<?php

declare(strict_types=1);

namespace App\Profiler;

/**
 * A bounded, private store for completed development profiles. Every method
 * receives the same clock instant so retention is deterministic in tests.
 */
interface ProfilerStore
{
    public function save(ProfileRecord $profile, int $now): void;

    /** @return list<ProfileRecord> Newest first. */
    public function latest(int $limit, int $now): array;

    public function find(string $id, int $now): ?ProfileRecord;
}
