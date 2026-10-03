<?php

declare(strict_types=1);

namespace App\Redis;

/**
 * One subsystem's fixed infrastructure decision. Auto probes only on first
 * resolution; a later outage never switches writes to another backend.
 */
final class InfrastructureSelection
{
    private ?string $selected = null;
    private ?string $reason = null;

    public function __construct(private string $configured, private string $fallback,
        private ?RedisManager $redis, private ?string $connection = null)
    {
    }

    public function configured(): string { return $this->configured; }
    public function selected(): ?string { return $this->selected; }
    public function reason(): ?string { return $this->reason; }

    public function resolve(): string
    {
        if ($this->selected !== null) return $this->selected;
        if ($this->configured !== 'auto') {
            $this->reason = 'explicit';
            return $this->selected = $this->configured;
        }
        if ($this->redis === null) {
            $this->reason = 'redis_provider_absent';
            return $this->selected = $this->fallback;
        }
        $capability = $this->redis->capability($this->connection, true);
        $this->reason = $capability['status'];
        return $this->selected = $capability['status'] === 'available' ? 'redis' : $this->fallback;
    }
}
