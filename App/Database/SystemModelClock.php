<?php

declare(strict_types=1);

namespace App\Database;

use DateTimeImmutable;
use DateTimeZone;

/** Default per-application clock; model persistence formats its result to UTC seconds. */
final class SystemModelClock implements ModelClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
