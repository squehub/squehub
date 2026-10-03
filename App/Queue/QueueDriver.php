<?php

declare(strict_types=1);

namespace App\Queue;

/** A dispatch target; persistent drivers additionally implement reservation. */
interface QueueDriver
{
    public function dispatch(QueueJob $job, string $queue, int $delay): void;
}
