<?php

declare(strict_types=1);

namespace App\Contributions;

use App\Packages\PackageName;
use InvalidArgumentException;
use LogicException;

/** Application-owned context and observed contribution records. */
final class ContributionRegistry
{
    /** @var list<array{owner:ContributionOwner,source:?string}> */
    private array $owners = [];

    /** @var array<string, Contribution> */
    private array $contributions = [];

    /** Only public structural labels are permitted, never contribution values. */
    private const METADATA_KEYS = [
        'route' => ['name', 'method', 'path', 'controller', 'middleware', 'api_version',
            'operation_id', 'host', 'fallback'],
        'middleware' => ['class'],
        'view' => ['root', 'selected', 'overrides', 'namespace', 'source_kind'],
        'view_namespace' => [],
        'config' => ['layer', 'overridden'],
        'service' => ['binding'],
        'scheduler' => ['schedule', 'mode'],
    ];

    public function beginOwner(ContributionOwner $owner, ?string $source = null): void
    {
        $this->owners[] = ['owner' => $owner, 'source' => self::safeSource($source)];
    }

    public function endOwner(): void
    {
        if ($this->owners === []) {
            throw new LogicException('Contribution owner context is not active.');
        }
        array_pop($this->owners);
    }

    public function withOwner(ContributionOwner $owner, callable $action, ?string $source = null): mixed
    {
        $this->beginOwner($owner, $source);
        try {
            return $action();
        } finally {
            $this->endOwner();
        }
    }

    public function currentOwner(): ?ContributionOwner
    {
        return $this->owners[count($this->owners) - 1]['owner'] ?? null;
    }

    public function currentSource(): ?string
    {
        return $this->owners[count($this->owners) - 1]['source'] ?? null;
    }

    /**
     * Observe a completed registration. No record is made outside an owner
     * context unless a reliable owner is passed explicitly.
     *
     * @param array<string, mixed> $metadata Untrusted input validated before recording.
     */
    public function record(string $type, string $identifier, ?string $source = null,
        array $metadata = [], ?ContributionOwner $owner = null): void
    {
        $owner ??= $this->currentOwner();
        if ($owner === null) {
            return;
        }
        if (!isset(self::METADATA_KEYS[$type]) || $identifier === '' || strlen($identifier) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
            throw new InvalidArgumentException('Contribution identity is invalid.');
        }
        if ($type === 'view_namespace') {
            if (!PackageName::valid($identifier) || $owner->type !== 'package'
                || $owner->name !== $identifier) {
                throw new InvalidArgumentException('View namespace must match its Package owner.');
            }
            foreach ($this->contributions as $existing) {
                if ($existing->type === 'view_namespace'
                    && strcasecmp($existing->identifier, $identifier) === 0) {
                    throw new LogicException('Package View namespace is already registered.');
                }
            }
        }
        $allowed = self::METADATA_KEYS[$type];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)
                || (!is_string($value) && !is_bool($value) && !is_int($value))
                || (is_string($value) && (strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value)))) {
                throw new InvalidArgumentException('Contribution metadata is invalid.');
            }
            if ($type === 'view' && in_array($key, ['root', 'selected', 'overrides'], true)
                && (!is_string($value) || self::safeSource($value) === null)) {
                throw new InvalidArgumentException('Contribution view path is invalid.');
            }
            if ($type === 'view' && $key === 'namespace'
                && (!is_string($value) || !PackageName::valid($value))) {
                throw new InvalidArgumentException('Contribution View namespace is invalid.');
            }
            if ($type === 'view' && $key === 'source_kind'
                && !in_array($value, ['override', 'package'], true)) {
                throw new InvalidArgumentException('Contribution View source kind is invalid.');
            }
        }
        ksort($metadata, SORT_STRING);
        $source = self::safeSource($source ?? $this->currentSource());
        $key = $type . "\0" . $identifier;
        $this->contributions[$key] = new Contribution($type, $identifier, $owner, $source, $metadata);
    }

    /** @return list<Contribution> */
    public function all(): array
    {
        $items = array_values($this->contributions);
        usort($items, static fn (Contribution $a, Contribution $b): int =>
            [$a->owner->type, $a->owner->name, $a->type, $a->identifier, $a->source ?? '']
            <=> [$b->owner->type, $b->owner->name, $b->type, $b->identifier, $b->source ?? '']);
        return $items;
    }

    /** @return list<Contribution> */
    public function byOwner(ContributionOwner $owner): array
    {
        return array_values(array_filter($this->all(), static fn (Contribution $item): bool =>
            $item->owner->type === $owner->type && $item->owner->name === $owner->name));
    }

    /** @return list<Contribution> */
    public function byType(string $type): array
    {
        return array_values(array_filter($this->all(), static fn (Contribution $item): bool => $item->type === $type));
    }

    public function ownerOf(string $type, string $identifier): ?ContributionOwner
    {
        return $this->contributions[$type . "\0" . $identifier]->owner ?? null;
    }

    /** Remove attribution when an unowned later mutation replaces a registration. */
    public function forget(string $type, string $identifier): void
    {
        unset($this->contributions[$type . "\0" . $identifier]);
    }

    private static function safeSource(?string $source): ?string
    {
        if ($source === null) {
            return null;
        }
        if ($source === '' || strlen($source) > 512 || str_starts_with($source, '/')
            || str_contains($source, '\\') || str_contains($source, ':')
            || preg_match('/[\x00-\x1F\x7F]/', $source)
            || preg_match('~(^|/)\.\.?(?:/|$)~', $source)) {
            throw new InvalidArgumentException('Contribution source must be an application-relative path.');
        }
        return $source;
    }
}
