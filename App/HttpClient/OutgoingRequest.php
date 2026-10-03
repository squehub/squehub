<?php

declare(strict_types=1);

namespace App\HttpClient;

/** One validated transport attempt. Test captures deliberately retain its sensitive values. */
final class OutgoingRequest
{
    /** @param array<string,string> $headers @param list<array<string,mixed>> $parts */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly array $parts,
        public readonly float $connectTimeout,
        public readonly float $timeout,
        public readonly bool $verifyPeer,
        public readonly ?string $caBundle,
        public readonly int $maxBodyBytes,
        public readonly int $maxRequestBytes,
        public readonly ?string $sinkPath,
        public readonly mixed $sinkStream,
    ) {}
}
