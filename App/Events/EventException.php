<?php

declare(strict_types=1);

namespace App\Events;

use RuntimeException;

/** Reports invalid event wiring or listener resolution without serializing event data. */
final class EventException extends RuntimeException
{
}
