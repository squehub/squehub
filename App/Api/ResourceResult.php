<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\JsonResponse;
use JsonSerializable;
use SensitiveParameter;
use SplObjectStorage;
use Throwable;

/**
 * Shared resolution boundary for single and collection representations.
 *
 * Resolution accepts explicit JSON values and nested resource results only;
 * arbitrary objects never reach PHP's automatic object serialization. Ancestor
 * tracking belongs to each resolution, so reusable resources and independent
 * Applications cannot inherit another request's metadata or traversal state.
 *
 * @internal Applications extend ApiResource and obtain collections through it.
 */
abstract class ResourceResult implements JsonSerializable
{
    private const MAX_DEPTH = 64;

    /** Null means no envelope was requested; an empty array is explicit metadata. */
    private ?array $metadata = null;

    /** Supply intentional data without serializing the underlying source wholesale. */
    abstract protected function representation(): ?array;

    /** Existing Page metadata is supplied by collections without converting its items. */
    protected function paginationMetadata(): ?array
    {
        return null;
    }

    /**
     * Return a separate representation with replacement metadata. Pagination
     * fields remain authoritative; callers cannot disguise its counts or bounds.
     */
    final public function withMeta(#[SensitiveParameter] array $metadata): static
    {
        $reserved = $this->paginationMetadata() ?? [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '' || array_key_exists($key, $reserved)) {
                throw new ResourceException('Resource metadata requires named fields without pagination overrides.');
            }
        }
        $copy = clone $this;
        $copy->metadata = $metadata;
        return $copy;
    }

    /**
     * Resolve independently of HTTP, authentication, the container, or Diagnostics.
     * Application transformation failures retain their cause but not their message
     * at the public boundary. Encoding validation also rejects malformed UTF-8 keys.
     */
    final public function resolve(): ?array
    {
        try {
            $data = self::normalize($this, new SplObjectStorage(), 0);
            json_encode($data, JSON_THROW_ON_ERROR);
            return $data;
        } catch (Throwable $exception) {
            throw new ResourceException(
                'API resource resolution failed; check its explicit transformation and JSON values.',
                0,
                $exception
            );
        }
    }

    final public function jsonSerialize(): ?array
    {
        return $this->resolve();
    }

    /** Reuse the established HTTP encoder, status validation, and header safety. */
    final public function response(int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($this->resolve(), $status, $headers);
    }

    /**
     * Only active ancestors are tracked: sharing a nested resource between fields
     * is valid, but a cycle is not. The depth bound also terminates recursive PHP
     * arrays and definitions that construct fresh resource instances recursively.
     *
     * @param SplObjectStorage<ResourceResult, null> $ancestors
     */
    private static function normalize(
        #[SensitiveParameter] mixed $value,
        SplObjectStorage $ancestors,
        int $depth
    ): mixed {
        if ($depth > self::MAX_DEPTH) {
            throw new ResourceException('Resource nesting exceeds the supported depth.');
        }

        if ($value instanceof self) {
            if ($ancestors->contains($value)) {
                throw new ResourceException('Resource representation contains a circular reference.');
            }
            $ancestors->attach($value);
            try {
                $data = self::normalize($value->representation(), $ancestors, $depth + 1);
                $pagination = $value->paginationMetadata();
                if ($pagination !== null || $value->metadata !== null) {
                    $metadata = ($pagination ?? []) + ($value->metadata ?? []);
                    return [
                        'data' => $data,
                        'meta' => self::normalize($metadata, $ancestors, $depth + 1),
                    ];
                }
                return $data;
            } finally {
                $ancestors->detach($value);
            }
        }

        if (is_array($value)) {
            $list = array_is_list($value);
            $resolved = [];
            foreach ($value as $key => $item) {
                if ($item === OmittedValue::Field) {
                    continue;
                }
                $item = self::normalize($item, $ancestors, $depth + 1);
                if ($list) {
                    $resolved[] = $item;
                } else {
                    $resolved[$key] = $item;
                }
            }
            return $resolved;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new ResourceException('Resource output must contain JSON scalars, arrays, or explicit nested resources.');
    }
}
