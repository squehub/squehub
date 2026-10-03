<?php

declare(strict_types=1);

namespace App\Scheduler;

use RuntimeException;

/** Reports invalid definitions or Scheduler infrastructure failures without task data. */
final class SchedulerException extends RuntimeException
{
}
