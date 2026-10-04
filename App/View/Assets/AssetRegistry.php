<?php

declare(strict_types=1);

namespace App\View\Assets;

use App\View\LogicalViewName;
use InvalidArgumentException;

/**
 * Application-owned declarations for named Views. A render snapshots these
 * registrations; only Views that actually participate activate their assets.
 */
final class AssetRegistry
{
    /** @var list<array{owners: list<string>, kind: string, url: string, once: ?string}> */
    private array $entries = [];

    /** @param string|list<string> $owners */
    public function for(string|array $owners): AssetOwnerRegistration
    {
        $owners = is_string($owners) ? [$owners] : $owners;
        if ($owners === []) {
            throw new InvalidArgumentException('An asset registration requires a View owner.');
        }
        $accepted = [];
        foreach ($owners as $owner) {
            if (!is_string($owner) || LogicalViewName::parse($owner) === null) {
                throw new InvalidArgumentException('Asset owner must be a logical View name.');
            }
            if (!in_array($owner, $accepted, true)) {
                $accepted[] = $owner;
            }
        }
        return new AssetOwnerRegistration($this, $accepted);
    }

    /**
     * The returned value is copied into a render-local collector. Later
     * registrations affect subsequent renders, never one already underway.
     *
     * @return list<array{owners: list<string>, kind: string, url: string, once: ?string}>
     */
    public function snapshot(): array
    {
        return $this->entries;
    }

    /** @param list<string> $owners */
    public function register(array $owners, string $kind, mixed $url, mixed $once = null): void
    {
        // Even direct calls to this bridge cannot create an unowned global
        // asset. Normal application code uses for()->style()/script().
        if ($owners === []) {
            throw new InvalidArgumentException('An asset registration requires a View owner.');
        }
        foreach ($owners as $owner) {
            if (!is_string($owner) || LogicalViewName::parse($owner) === null) {
                throw new InvalidArgumentException('Asset owner must be a logical View name.');
            }
        }
        if ($kind !== 'style' && $kind !== 'script') {
            throw new \LogicException('Unsupported asset type.');
        }
        $this->entries[] = [
            'owners' => $owners,
            'kind' => $kind,
            'url' => AssetRenderState::url($url),
            'once' => AssetRenderState::onceKey($once),
        ];
    }
}
