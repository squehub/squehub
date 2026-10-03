<?php

declare(strict_types=1);

namespace App\Profiler;

/** Isolated in-process ring for tests and single-Application inspection. */
final class ArrayProfilerStore implements ProfilerStore
{
    /** @var array<string, ProfileRecord> */
    private array $profiles = [];

    public function __construct(private ProfilerSettings $settings)
    {
    }

    public function save(ProfileRecord $profile, int $now): void
    {
        if ($profile->bytes() > $this->settings->maxProfileBytes) {
            throw new ProfilerException('Profile exceeds the configured byte limit.');
        }
        $this->profiles[$profile->id] = $profile;
        $this->prune($now);
    }

    public function latest(int $limit, int $now): array
    {
        if ($limit < 1 || $limit > $this->settings->maxProfiles) {
            throw new ProfilerException('Profile list limit is invalid.');
        }
        $this->prune($now);
        $profiles = array_values($this->profiles);
        usort($profiles, self::newestFirst(...));
        return array_slice($profiles, 0, $limit);
    }

    public function find(string $id, int $now): ?ProfileRecord
    {
        ProfileRecord::identifier($id);
        $this->prune($now);
        return $this->profiles[$id] ?? null;
    }

    /** Oldest timestamp, then ID, wins every retention tie deterministically. */
    private function prune(int $now): void
    {
        $profiles = array_values($this->profiles);
        usort($profiles, self::oldestFirst(...));
        $bytes = array_sum(array_map(static fn (ProfileRecord $record): int => $record->bytes(), $profiles));
        foreach ($profiles as $profile) {
            if ($profile->recordedAt <= $now - $this->settings->maxAgeSeconds
                || count($this->profiles) > $this->settings->maxProfiles
                || $bytes > $this->settings->maxBytes) {
                unset($this->profiles[$profile->id]);
                $bytes -= $profile->bytes();
            }
        }
    }

    public static function oldestFirst(ProfileRecord $left, ProfileRecord $right): int
    {
        return ($left->recordedAt <=> $right->recordedAt)
            ?: strcmp($left->id, $right->id);
    }

    public static function newestFirst(ProfileRecord $left, ProfileRecord $right): int
    {
        return ($right->recordedAt <=> $left->recordedAt)
            ?: strcmp($right->id, $left->id);
    }
}
