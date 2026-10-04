<?php

declare(strict_types=1);

namespace App\Profiler;

use App\Observability\ObservationConsumer;
use App\Observability\ObservationReport;
use Closure;

/**
 * Captures completed HTTP, Queue, and Scheduler roots from the single 19A
 * observation stream. Disabled managers allocate no event graph or store file.
 */
final class ProfilerManager implements ObservationConsumer
{
    /** @param ?Closure():int $clock UTC epoch seconds; injectable for retention tests. */
    public function __construct(private ProfilerSettings $settings,
        private ProfilerStore $store, private ?Closure $clock = null)
    {
    }

    public function enabled(): bool { return $this->settings->enabled; }

    public function accept(ObservationReport $report): void
    {
        if (!$this->enabled()) return;
        $now = $this->now();
        $this->store->save(ProfileRecord::capture($report, $now, $this->settings), $now);
    }

    /** @return list<ProfileRecord> Newest first, bounded by configuration. */
    public function latest(int $limit = 20): array
    {
        if ($limit < 1) throw new ProfilerException('Profile list limit is invalid.');
        return $this->enabled()
            ? $this->store->latest(min($limit, $this->settings->maxProfiles), $this->now())
            : [];
    }

    public function find(string $id): ?ProfileRecord
    {
        return $this->enabled() ? $this->store->find($id, $this->now()) : null;
    }

    private function now(): int
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($now) || $now < 1) {
            throw new ProfilerException('Profiler clock is invalid.');
        }
        return $now;
    }
}
