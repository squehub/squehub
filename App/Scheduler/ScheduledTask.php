<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Container\Container;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * An in-memory schedule definition; only its hashed stable name and run state
 * reach the lock store. Fluent methods change recurrence, never execute work.
 * Queued jobs delegate execution to Queue; overlap protection is reserved for
 * synchronous calls because Queue has no job-completion callback in this phase.
 */
final class ScheduledTask
{
    private ?string $name = null;
    private ?string $frequency = null;
    private int $interval = 1;
    private ?int $hour = null;
    private ?int $minute = null;
    private ?int $weekday = null;
    private ?int $monthDay = null;
    /** @var list<int>|null */
    private ?array $allowedDays = null;
    private DateTimeZone $zone;
    private ?int $overlapSeconds = null;

    /** @param Closure|array<int|string,mixed>|class-string|null $callback */
    public function __construct(private ?QueueJob $job, private Closure|array|string|null $callback,
        string $timezone, private ?string $connection = null, private string $queue = 'default',
        private int $delay = 0)
    {
        $this->timezone($timezone);
        if ($job !== null) {
            $this->name = 'job-' . substr(hash('sha256', $job::class), 0, 32);
            QueueManager::name($queue);
            if ($connection !== null) QueueManager::name($connection, 'connection');
            if ($delay < 0 || $delay > 31536000) throw new SchedulerException('Schedule job delay is invalid.');
        } elseif (is_array($callback) && count($callback) === 2
            && is_string($callback[0] ?? null) && is_string($callback[1] ?? null)) {
            $this->name = 'call-' . substr(hash('sha256', $callback[0] . '::' . $callback[1]), 0, 32);
        } elseif (is_string($callback) && class_exists($callback)) {
            $this->name = 'call-' . substr(hash('sha256', $callback), 0, 32);
        } elseif (!$callback instanceof Closure) {
            throw new SchedulerException('Scheduled call must be a closure, class method, or invokable class.');
        }
    }

    public function name(string $name): self
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $name) !== 1) {
            throw new SchedulerException('Schedule name is invalid.');
        }
        $this->name = $name;
        return $this;
    }

    public function taskName(): string
    {
        if ($this->name === null) throw new SchedulerException('A closure schedule requires an explicit name.');
        return $this->name;
    }

    public function everyMinute(): self { return $this->minutes(1); }
    public function everyFiveMinutes(): self { return $this->minutes(5); }
    public function everyTenMinutes(): self { return $this->minutes(10); }
    public function everyFifteenMinutes(): self { return $this->minutes(15); }
    public function everyThirtyMinutes(): self { return $this->minutes(30); }

    private function minutes(int $interval): self
    {
        $this->frequency = 'minutes';
        $this->interval = $interval;
        $this->hour = $this->minute = $this->weekday = $this->monthDay = null;
        return $this;
    }

    public function hourly(): self
    {
        $this->frequency = 'hourly';
        $this->hour = $this->weekday = $this->monthDay = null;
        $this->minute = 0;
        return $this;
    }

    public function hourlyAt(int $minute): self
    {
        if ($minute < 0 || $minute > 59) throw new SchedulerException('Schedule minute must be from 0 to 59.');
        return $this->hourly()->minute($minute);
    }

    private function minute(int $minute): self { $this->minute = $minute; return $this; }

    public function daily(): self
    {
        $this->frequency = 'daily';
        $this->hour = 0;
        $this->minute = 0;
        $this->weekday = $this->monthDay = null;
        return $this;
    }

    public function dailyAt(string $time): self { return $this->daily()->at($time); }

    public function weekly(): self
    {
        $this->frequency = 'weekly';
        $this->weekday = 1; // ISO Monday.
        $this->monthDay = null;
        $this->hour = $this->minute = 0;
        return $this;
    }

    /** ISO weekday: 1 is Monday and 7 is Sunday. */
    public function weeklyOn(int $weekday): self
    {
        if ($weekday < 1 || $weekday > 7) throw new SchedulerException('Schedule weekday must be from 1 to 7.');
        $this->weekly();
        $this->weekday = $weekday;
        return $this;
    }

    public function monthly(): self
    {
        $this->frequency = 'monthly';
        $this->monthDay = 1;
        $this->weekday = null;
        $this->hour = $this->minute = 0;
        return $this;
    }

    public function weekdays(): self
    {
        if ($this->frequency === null) $this->daily();
        $this->allowedDays = [1, 2, 3, 4, 5];
        return $this;
    }

    public function weekends(): self
    {
        if ($this->frequency === null) $this->daily();
        $this->allowedDays = [6, 7];
        return $this;
    }

    /** A strict 24-hour HH:MM value; impossible local DST times never match. */
    public function at(string $time): self
    {
        if (preg_match('/\A([01][0-9]|2[0-3]):([0-5][0-9])\z/D', $time, $parts) !== 1) {
            throw new SchedulerException('Schedule time must use a valid HH:MM value.');
        }
        if ($this->frequency === null) $this->daily();
        $this->hour = (int) $parts[1];
        $this->minute = (int) $parts[2];
        return $this;
    }

    public function timezone(string $timezone): self
    {
        if ($timezone === '') throw new SchedulerException('Schedule timezone is invalid.');
        try {
            $this->zone = new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new SchedulerException('Schedule timezone is invalid.', 0, $exception);
        }
        return $this;
    }

    /** The timeout is seconds; tasks exceeding it can overlap a later run. */
    public function withoutOverlapping(int $seconds = 3600): self
    {
        if ($this->job !== null) {
            throw new SchedulerException('Overlap protection requires a synchronous scheduled call.');
        }
        if ($seconds < 1 || $seconds > 31536000) throw new SchedulerException('Schedule overlap timeout is invalid.');
        $this->overlapSeconds = $seconds;
        return $this;
    }

    public function isDue(DateTimeImmutable $instant): bool
    {
        $this->validate();
        $local = $instant->setTimezone($this->zone);
        $hour = (int) $local->format('G');
        $minute = (int) $local->format('i');
        $weekday = (int) $local->format('N');
        if ($this->allowedDays !== null && !in_array($weekday, $this->allowedDays, true)) return false;
        if ($this->weekday !== null && $weekday !== $this->weekday) return false;
        if ($this->monthDay !== null && (int) $local->format('j') !== $this->monthDay) return false;
        if ($this->hour !== null && $hour !== $this->hour) return false;
        if ($this->minute !== null && $minute !== $this->minute) return false;
        return $this->frequency !== 'minutes' || $minute % $this->interval === 0;
    }

    public function validate(): void
    {
        $this->taskName();
        if ($this->frequency === null) throw new SchedulerException('Schedule frequency is missing.');
    }

    public function taskKey(string $prefix): string
    {
        return hash('sha256', $prefix . "\0" . $this->taskName());
    }

    /** UTC minute distinguishes the two occurrences of a repeated DST hour. */
    public function occurrence(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:00');
    }

    public function timezoneName(): string { return $this->zone->getName(); }
    public function mode(): string { return $this->job === null ? 'call' : 'job'; }
    public function queueName(): string { return $this->queue; }
    public function overlapSeconds(): ?int { return $this->overlapSeconds; }

    public function description(): string
    {
        $this->validate();
        $base = match ($this->frequency) {
            'minutes' => $this->interval === 1 ? 'every minute' : 'every ' . $this->interval . ' minutes',
            'hourly' => 'hourly',
            'daily' => 'daily',
            'weekly' => 'weekly on ISO day ' . $this->weekday,
            'monthly' => 'monthly on day 1',
            default => throw new SchedulerException('Schedule frequency is invalid.'),
        };
        if ($this->hour !== null && $this->frequency !== 'hourly') {
            $base .= sprintf(' at %02d:%02d', $this->hour, $this->minute);
        } elseif ($this->frequency === 'hourly') {
            $base .= sprintf(' at minute %02d', $this->minute);
        }
        if ($this->allowedDays === [1, 2, 3, 4, 5]) $base .= ' on weekdays';
        if ($this->allowedDays === [6, 7]) $base .= ' on weekends';
        return $base;
    }

    public function runCall(Container $container): void
    {
        if ($this->job !== null) throw new SchedulerException('A queued schedule cannot run as a call.');
        if ($this->callback instanceof Closure) {
            ($this->callback)();
            return;
        }
        if (is_array($this->callback)) {
            [$class, $method] = $this->callback;
            $container->make($class)->$method();
            return;
        }
        $callable = $container->make($this->callback);
        $callable();
    }

    public function dispatch(QueueManager $queue): void
    {
        if ($this->job === null) throw new SchedulerException('A call schedule has no Queue job.');
        $queue->dispatch($this->job, $this->queue, $this->delay, $this->connection);
    }
}
