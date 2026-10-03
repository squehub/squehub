<?php

declare(strict_types=1);

namespace App\Queue;

use App\Container\Container;

/**
 * Internal opt-in for framework jobs needing their worker Application services.
 * The container is attached after JSON reconstruction and is never persisted.
 */
interface QueueContextAware
{
    public function setQueueContainer(Container $container): void;
}
