<?php

declare(strict_types=1);

namespace App\Queue;

/** A job attempt exceeded the configured worker execution limit. */
final class QueueTimeoutException extends QueueException
{
}
