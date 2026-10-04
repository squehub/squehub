<?php

declare(strict_types=1);

namespace App\Queue\Drivers;

use App\Queue\QueueDriver;
use App\Queue\QueueJob;
use App\Queue\QueueAttemptContextAware;

/** Executes immediately; delay has no scheduling effect on this driver. */
final class SyncQueueDriver implements QueueDriver
{
    public function dispatch(QueueJob $job, string $queue, int $delay): void
    {
        // Sync has no future reservation even if the caller requested delay.
        if ($job instanceof QueueAttemptContextAware) {
            $job->setQueueAttemptContext(1, 1);
        }
        $job->handle();
    }
}
