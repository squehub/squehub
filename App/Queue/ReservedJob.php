<?php

declare(strict_types=1);

namespace App\Queue;

/** Reservation token fences a previous worker after timeout and reclaim. */
final class ReservedJob
{
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $payload,
        public readonly int $attempts,
        public readonly string $token,
        public readonly ?string $compositionId = null,
        public readonly ?int $compositionPosition = null
    ) {
    }
}
