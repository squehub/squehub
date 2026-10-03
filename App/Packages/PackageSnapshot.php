<?php

declare(strict_types=1);

namespace App\Packages;

use App\Contributions\Contribution;
use JsonException;

/** Portable metadata observed during one deliberate, trusted Package verification. */
final class PackageSnapshot
{
    private const VERSION = 1;
    private const MAX_ITEMS = 4096;
    private const MAX_BYTES = 2097152;

    /**
     * @param list<Contribution> $contributions
     * @return array{version:int,source_fingerprint:string,items:list<array<string,mixed>>}
     */
    public static function capture(string $name, string $directory, array $contributions): array
    {
        if (count($contributions) > self::MAX_ITEMS) {
            throw new PackageException('Package contribution snapshot is too large.');
        }
        $items = [];
        foreach ($contributions as $contribution) {
            if ($contribution->owner->type !== 'package' || $contribution->owner->name !== $name) {
                throw new PackageException('Package contribution has an unexpected owner.');
            }
            $item = Contribution::fromArray($contribution->toArray());
            if ($item === null) {
                throw new PackageException('Package contribution metadata is invalid.');
            }
            $items[] = $item->toArray();
        }
        self::sortItems($items);
        $snapshot = [
            'version' => self::VERSION,
            'source_fingerprint' => self::sourceFingerprint($directory),
            'items' => $items,
        ];
        if (strlen(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new PackageException('Package contribution snapshot is too large.');
        }
        return $snapshot;
    }

    /**
     * Invalid optional provenance is unavailable, rather than trusted as current.
     * @return array{version:int,source_fingerprint:string,items:list<array<string,mixed>>}|null
     */
    public static function parse(mixed $value, string $name): ?array
    {
        if (!is_array($value) || array_keys($value) !== ['version', 'source_fingerprint', 'items']
            || $value['version'] !== self::VERSION || !is_string($value['source_fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $value['source_fingerprint']) !== 1
            || !is_array($value['items']) || !array_is_list($value['items'])
            || count($value['items']) > self::MAX_ITEMS) {
            return null;
        }
        $items = [];
        $seen = [];
        foreach ($value['items'] as $raw) {
            $item = is_array($raw) ? Contribution::fromArray($raw) : null;
            if ($item === null || $item->owner->type !== 'package' || $item->owner->name !== $name) {
                return null;
            }
            $key = $item->type . "\0" . $item->identifier;
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $items[] = $item->toArray();
        }
        self::sortItems($items);
        $snapshot = [
            'version' => self::VERSION,
            'source_fingerprint' => $value['source_fingerprint'],
            'items' => $items,
        ];
        try {
            if (strlen(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
                return null;
            }
        } catch (JsonException) {
            return null;
        }
        return $snapshot;
    }

    /** @param array{source_fingerprint:string} $snapshot */
    public static function isCurrent(array $snapshot, string $directory): bool
    {
        try {
            return hash_equals($snapshot['source_fingerprint'], self::sourceFingerprint($directory));
        } catch (PackageException) {
            return false;
        }
    }

    /** The path/hash map is sorted by PackageFiles before serialization. */
    public static function sourceFingerprint(string $directory): string
    {
        try {
            $json = json_encode(PackageFiles::fingerprints($directory, false, true),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PackageException('Package source could not be fingerprinted.', 0, $exception);
        }
        return hash('sha256', $json);
    }

    /** @param list<array<string,mixed>> $items */
    private static function sortItems(array &$items): void
    {
        usort($items, static fn (array $left, array $right): int =>
            [$left['owner']['type'], $left['owner']['name'], $left['type'], $left['identifier'], $left['source'] ?? '']
            <=> [$right['owner']['type'], $right['owner']['name'], $right['type'], $right['identifier'], $right['source'] ?? '']);
    }
}
