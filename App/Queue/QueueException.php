<?php

declare(strict_types=1);

namespace App\Queue;

use RuntimeException;

/** Queue configuration, payload, or persistence failure with a safe public message. */
class QueueException extends RuntimeException
{
}
