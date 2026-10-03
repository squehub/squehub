<?php

declare(strict_types=1);

namespace App\Contributions;

use Throwable;

/** Immutable, value-free metadata about one observed registration. */
final readonly class Contribution
{
    /** @param array<string, string|bool|int> $metadata */
    public function __construct(
        public string $type,
        public string $identifier,
        public ContributionOwner $owner,
        public ?string $source = null,
        public array $metadata = [],
    ) {
    }

    /** @return array{type:string,identifier:string,owner:array{type:string,name:string},source:?string,metadata:array<string,string|bool|int>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'identifier' => $this->identifier,
            'owner' => $this->owner->toArray(),
            'source' => $this->source,
            'metadata' => $this->metadata,
        ];
    }

    /** Reuse the runtime schema validation when reading an untrusted snapshot. */
    public static function fromArray(array $data): ?self
    {
        if (array_keys($data) !== ['type', 'identifier', 'owner', 'source', 'metadata']
            || !is_string($data['type']) || !is_string($data['identifier'])
            || !is_array($data['owner'])
            || array_keys($data['owner']) !== ['type', 'name']
            || !is_string($data['owner']['type']) || !is_string($data['owner']['name'])
            || ($data['source'] !== null && !is_string($data['source']))
            || !is_array($data['metadata'])) {
            return null;
        }
        try {
            $owner = new ContributionOwner($data['owner']['type'], $data['owner']['name']);
            $registry = new ContributionRegistry();
            $registry->record($data['type'], $data['identifier'], $data['source'], $data['metadata'], $owner);
            return $registry->all()[0] ?? null;
        } catch (Throwable) {
            return null;
        }
    }
}
