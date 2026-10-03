<?php

declare(strict_types=1);

namespace App\Api\Sdk;

use App\Api\Contract\ContractManager;

/** Detached, language-neutral input shared by all SDK emitters. */
final class SdkModel
{
    /**
     * @param array<string,array<string,mixed>|bool> $schemas
     * @param array<string,string> $schemaSymbols
     * @param list<array<string,mixed>> $operations
     * @param list<array<string,mixed>> $webhooks
     */
    public function __construct(
        public readonly ?string $apiVersion,
        public readonly array $schemas,
        public readonly array $schemaSymbols,
        public readonly array $operations,
        public readonly array $webhooks,
        public readonly string $fingerprint
    ) {
    }

    public static function fromContract(ContractManager $manager, ?string $apiVersion = null): self
    {
        return SdkNormalizer::normalize($manager, $apiVersion);
    }
}
