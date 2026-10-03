<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Internal capability for durable drivers that can write correlation into
 * the outer Queue envelope. The public QueueDriver contract stays compatible.
 */
interface CorrelatedQueueDriver extends QueueDriver
{
    public function dispatchCorrelated(QueueJob $job, string $queue, int $delay,
        ?string $correlationId): void;
}
