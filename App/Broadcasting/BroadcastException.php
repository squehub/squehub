<?php

declare(strict_types=1);

namespace App\Broadcasting;

use RuntimeException;

/** A safe configuration, payload, authorization, or delivery failure. */
final class BroadcastException extends RuntimeException
{
}
