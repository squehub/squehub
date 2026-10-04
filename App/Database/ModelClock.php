<?php

declare(strict_types=1);

namespace App\Database;

use DateTimeImmutable;

/** Supplies the time used for model timestamps. */
interface ModelClock
{
    public function now(): DateTimeImmutable;
}
