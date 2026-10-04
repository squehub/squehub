<?php

declare(strict_types=1);

namespace App\Locks;

/** Lock::run() could not obtain the requested lease within its wait bound. */
final class LockBusyException extends LockException
{
}
