<?php

declare(strict_types=1);

namespace App\Config;

use App\Contributions\ContributionRegistry;
use InvalidArgumentException;
use LogicException;

/** Holds configuration values under dot-separated keys. */
final class Repository
{
    private ?string $packageContext = null;
    private ?ContributionRegistry $contributions = null;

    public function setContributionRegistry(ContributionRegistry $contributions): void
    {
        $this->contributions = $contributions;
    }

    public function __construct(private array $items = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach ($this->segments($key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->packageContext !== null) {
            $root = 'packages.' . $this->packageContext;
            if ($key !== $root && !str_starts_with($key, $root . '.')) {
                throw new LogicException('Package configuration may only set its own namespaced defaults.');
            }
            if ($this->has($key)) {
                throw new LogicException('Package configuration cannot replace an application value.');
            }
        }
        $segments = $this->segments($key);
        $last = array_pop($segments);
        $items = &$this->items;
        foreach ($segments as $segment) {
            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                $items[$segment] = [];
            }
            $items = &$items[$segment];
        }
        $items[$last] = $value;
        if ($this->packageContext !== null && $this->contributions !== null) {
            $this->recordPackageKeys($key, $value);
        } elseif ($this->contributions !== null) {
            foreach ($this->contributions->byType('config') as $contribution) {
                if ($contribution->identifier === $key
                    || str_starts_with($contribution->identifier, $key . '.')) {
                    $this->contributions->record('config', $contribution->identifier,
                        $contribution->source,
                        ['layer' => 'package_default', 'overridden' => true], $contribution->owner);
                }
            }
        }
    }

    public function has(string $key): bool
    {
        // A key with a null value is still present.
        $value = $this->items;
        foreach ($this->segments($key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        return true;
    }

    public function all(): array
    {
        return $this->items;
    }

    /** Restrict enabled Package hooks to additive, namespaced defaults. */
    public function beginPackageContext(string $name): void
    {
        if ($name === '' || $this->packageContext !== null) {
            throw new LogicException('Package configuration context is already active or invalid.');
        }
        $this->packageContext = $name;
    }

    public function endPackageContext(): void
    {
        $this->packageContext = null;
    }

    private function recordPackageKeys(string $key, mixed $value, int $depth = 0): void
    {
        if ($key === '' || strlen($key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return;
        }
        if (is_array($value) && $value !== [] && $depth < 16) {
            foreach ($value as $name => $child) {
                if (is_string($name) || is_int($name)) {
                    $this->recordPackageKeys($key . '.' . $name, $child, $depth + 1);
                }
            }
            return;
        }
        $this->contributions?->record('config', $key, null, ['layer' => 'package_default']);
    }

    /** @return list<string> */
    private function segments(string $key): array
    {
        $segments = explode('.', $key);
        if (in_array('', $segments, true)) {
            throw new InvalidArgumentException('Configuration key must contain nonempty segments.');
        }
        return $segments;
    }
}
