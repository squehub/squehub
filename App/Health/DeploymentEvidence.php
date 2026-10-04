<?php

declare(strict_types=1);

namespace App\Health;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One time-bounded deployment observation, never a permanent certification.
 * Unknown reachability and end-to-end states remain null instead of being
 * promoted from configuration or from another host's reported settings.
 */
final readonly class DeploymentEvidence
{
    /** @param array<string,array{configured:bool,reachable:?bool,end_to_end_verified:?bool,reason:string}> $checks */
    public function __construct(
        public string $profile,
        public DateTimeImmutable $checkedAt,
        public array $checks,
    ) {
    }

    public function fresh(int $maximumAgeSeconds = 3600, ?DateTimeImmutable $now = null): bool
    {
        if ($maximumAgeSeconds < 1) return false;
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $age = $now->getTimestamp() - $this->checkedAt->getTimestamp();
        return $age >= 0 && $age <= $maximumAgeSeconds;
    }

    /** The highest tier proved for every required check, with no inferred remote proof. */
    public function level(): string
    {
        if ($this->checks === [] || in_array(false, array_column($this->checks, 'configured'), true)) {
            return 'not_ready';
        }
        if (in_array(null, array_column($this->checks, 'reachable'), true)
            || in_array(false, array_column($this->checks, 'reachable'), true)) {
            return 'configured';
        }
        if (in_array(null, array_column($this->checks, 'end_to_end_verified'), true)
            || in_array(false, array_column($this->checks, 'end_to_end_verified'), true)) {
            return 'reachable';
        }
        return 'end_to_end_verified';
    }

    /** A deliberately attempted but failed probe must not produce a successful CLI exit. */
    public function hasFailedObservation(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['reachable'] === false || $check['end_to_end_verified'] === false) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'profile' => $this->profile,
            'checked_at' => $this->checkedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'framework' => 'squehub/unreleased-v2',
            'php' => PHP_VERSION,
            'os_family' => PHP_OS_FAMILY,
            'level' => $this->level(),
            'checks' => $this->checks,
        ];
    }

    public function render(): string
    {
        $lines = ['SqueHub Deployment Proof',
            'Profile: ' . $this->profile,
            'Checked: ' . $this->toArray()['checked_at'],
            'Level: ' . $this->level()];
        foreach ($this->checks as $name => $result) {
            $tier = $result['end_to_end_verified'] === true ? 'end_to_end_verified'
                : ($result['reachable'] === true ? 'reachable'
                    : ($result['configured'] ? 'configured' : 'not_ready'));
            $lines[] = str_pad($name, 22) . ' ' . $tier . ' (' . $result['reason'] . ')';
        }
        return implode(PHP_EOL, $lines);
    }
}
