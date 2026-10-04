<?php

declare(strict_types=1);

namespace App\Http;

/** Return value and direct output from a dispatcher, kept separate for normalization. */
final readonly class DispatchResult
{
    public function __construct(
        public mixed $value = null,
        public string $output = '',
        public bool $matched = true
    ) {
    }
}
