<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Queue\QueueJob;
use App\Scheduler\Schedule as ScheduleGateway;
use App\Scheduler\ScheduledTask;
use App\Scheduler\Scheduler;
use Closure;

/** Application-facing gateway to the same Application-owned Scheduler. */
final class Schedule
{
    public static function manager(): Scheduler { return ScheduleGateway::manager(); }

    public static function job(QueueJob $job, ?string $connection = null, string $queue = 'default',
        int $delay = 0): ScheduledTask
    {
        return self::manager()->job($job, $connection, $queue, $delay);
    }

    /** @param Closure|array{class-string,string}|class-string $callback */
    public static function call(Closure|array|string $callback): ScheduledTask
    {
        return self::manager()->call($callback);
    }
}
