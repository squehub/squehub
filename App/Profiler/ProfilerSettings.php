<?php

declare(strict_types=1);

namespace App\Profiler;

/**
 * Validated retention limits shared by the capture layer and both stores.
 * Effective enablement is restricted to the exact development environment;
 * debug mode has no part in that decision.
 */
final readonly class ProfilerSettings
{
    public bool $enabled;
    public string $store;
    public int $maxProfiles;
    public int $maxAgeSeconds;
    public int $maxEvents;
    public int $maxProfileBytes;
    public int $maxBytes;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings, string $environment)
    {
        if (array_diff(array_keys($settings), ['enabled', 'store', 'max_profiles',
            'max_age_seconds', 'max_events', 'max_profile_bytes', 'max_bytes']) !== []) {
            throw new ProfilerException('Profiler configuration has an unknown setting.');
        }
        $settings += [
            'enabled' => false, 'store' => 'file', 'max_profiles' => 100,
            'max_age_seconds' => 86400, 'max_events' => 256,
            'max_profile_bytes' => 65536, 'max_bytes' => 5242880,
        ];
        if (!is_bool($settings['enabled'])
            || !in_array($settings['store'], ['array', 'file'], true)
            || !self::within($settings['max_profiles'], 1, 1000)
            || !self::within($settings['max_age_seconds'], 1, 2592000)
            || !self::within($settings['max_events'], 1, 2048)
            || !self::within($settings['max_profile_bytes'], 8192, 262144)
            || !self::within($settings['max_bytes'], 8192, 16777216)
            || $settings['max_bytes'] < $settings['max_profile_bytes']) {
            throw new ProfilerException('Profiler configuration is invalid.');
        }
        $this->enabled = $settings['enabled'] && $environment === 'development';
        $this->store = $settings['store'];
        $this->maxProfiles = $settings['max_profiles'];
        $this->maxAgeSeconds = $settings['max_age_seconds'];
        $this->maxEvents = $settings['max_events'];
        $this->maxProfileBytes = $settings['max_profile_bytes'];
        $this->maxBytes = $settings['max_bytes'];
    }

    private static function within(mixed $value, int $minimum, int $maximum): bool
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum;
    }
}
