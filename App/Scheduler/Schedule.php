<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Queue\QueueJob;
use Closure;

/** Static gateway to the currently bootstrapped Application's Scheduler. */
final class Schedule
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): Scheduler
    {
        if (self::$resolver === null) throw new SchedulerException('Scheduler is unavailable before Application bootstrap.');
        return (self::$resolver)();
    }

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
