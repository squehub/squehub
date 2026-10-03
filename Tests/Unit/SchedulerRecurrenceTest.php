<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Container\Container;
use App\Queue\QueueJob;
use App\Scheduler\ScheduledTask;
use App\Scheduler\Scheduler;
use App\Scheduler\SchedulerException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Clock-controlled recurrence and DST rules never depend on the host zone. */
final class SchedulerRecurrenceTest extends TestCase
{
    private function instant(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    private function task(): ScheduledTask
    {
        return (new ScheduledTask(null, static fn () => null, 'UTC'))->name('recurrence');
    }

    public function testMinuteAndHourlyFrequencies(): void
    {
        $at = $this->instant('2026-09-24 08:30:59');
        self::assertTrue($this->task()->everyMinute()->isDue($at));
        self::assertTrue($this->task()->everyFiveMinutes()->isDue($at));
        self::assertTrue($this->task()->everyTenMinutes()->isDue($at));
        self::assertTrue($this->task()->everyFifteenMinutes()->isDue($at));
        self::assertTrue($this->task()->everyThirtyMinutes()->isDue($at));
        self::assertFalse($this->task()->everyFiveMinutes()->isDue($this->instant('2026-09-24 08:31:00')));
        self::assertTrue($this->task()->hourlyAt(30)->isDue($at));
        self::assertFalse($this->task()->hourly()->isDue($at));
        self::assertTrue($this->task()->hourly()->isDue($this->instant('2026-09-24 09:00:00')));
    }

    public function testDailyWeeklyMonthlyAndDayRestrictions(): void
    {
        self::assertTrue($this->task()->dailyAt('08:30')->isDue($this->instant('2026-09-24 08:30:00')));
        self::assertFalse($this->task()->dailyAt('08:30')->isDue($this->instant('2026-09-24 08:31:00')));
        self::assertTrue($this->task()->daily()->isDue($this->instant('2026-09-24 00:00:00')));
        self::assertTrue($this->task()->weekdays()->at('08:30')->isDue($this->instant('2026-09-24 08:30:00')));
        self::assertFalse($this->task()->weekdays()->at('08:30')->isDue($this->instant('2026-09-26 08:30:00')));
        self::assertTrue($this->task()->weekends()->at('08:30')->isDue($this->instant('2026-09-26 08:30:00')));
        self::assertTrue($this->task()->weekly()->isDue($this->instant('2026-09-28 00:00:00')));
        self::assertTrue($this->task()->weeklyOn(4)->at('08:30')->isDue($this->instant('2026-09-24 08:30:00')));
        self::assertFalse($this->task()->weeklyOn(4)->at('08:30')->isDue($this->instant('2026-09-25 08:30:00')));
        self::assertTrue($this->task()->monthly()->isDue($this->instant('2026-10-01 00:00:00')));
        self::assertFalse($this->task()->monthly()->isDue($this->instant('2026-10-02 00:00:00')));
    }

    public function testTimezoneOffsetsAndDaylightSavingRules(): void
    {
        self::assertTrue($this->task()->dailyAt('09:00')->timezone('Africa/Lagos')
            ->isDue($this->instant('2026-09-24 08:00:00')));
        self::assertTrue($this->task()->dailyAt('08:00')->timezone('America/New_York')
            ->isDue($this->instant('2026-01-15 13:00:00')));
        $spring = $this->task()->dailyAt('02:30')->timezone('America/New_York');
        self::assertFalse($spring->isDue($this->instant('2026-03-08 07:30:00')));
        $fall = $this->task()->dailyAt('01:30')->timezone('America/New_York');
        $first = $this->instant('2026-11-01 05:30:00');
        $second = $this->instant('2026-11-01 06:30:00');
        self::assertTrue($fall->isDue($first));
        self::assertTrue($fall->isDue($second));
        self::assertNotSame($fall->occurrence($first), $fall->occurrence($second));
    }

    public function testInvalidDefinitionsAndDuplicateNames(): void
    {
        foreach (['8:30', '24:00', '09:60', '08:30:00'] as $invalid) {
            try { $this->task()->daily()->at($invalid); self::fail('Invalid time accepted.'); }
            catch (SchedulerException) { self::assertTrue(true); }
        }
        foreach ([0, 8] as $day) {
            try { $this->task()->weeklyOn($day); self::fail('Invalid weekday accepted.'); }
            catch (SchedulerException) { self::assertTrue(true); }
        }
        foreach ([
            static fn () => (new ScheduledTask(null, static fn () => null, 'Invalid/Zone'))->daily(),
            fn () => $this->task()->hourlyAt(60),
            fn () => $this->task()->validate(),
            fn () => $this->task()->name('bad/name'),
        ] as $invalidDefinition) {
            try { $invalidDefinition(); self::fail('Invalid schedule definition accepted.'); }
            catch (SchedulerException) { self::assertTrue(true); }
        }
        $scheduler = new Scheduler(new Container(), ['store' => 'array']);
        $scheduler->call(static fn () => null)->name('same')->daily();
        $scheduler->call(static fn () => null)->name('SAME')->daily();
        $this->expectException(SchedulerException::class);
        $scheduler->definitions();
    }

    public function testClosureNeedsNameAndQueuedOverlapIsRejected(): void
    {
        $unnamed = (new ScheduledTask(null, static fn () => null, 'UTC'))->daily();
        try { $unnamed->validate(); self::fail('Unnamed closure accepted.'); }
        catch (SchedulerException) { self::assertTrue(true); }
        $job = new class implements QueueJob {
            public function handle(): void {}
            public function toQueuePayload(): array { return []; }
            public static function fromQueuePayload(array $payload): static { return new static(); }
        };
        $this->expectException(SchedulerException::class);
        (new ScheduledTask($job, null, 'UTC'))->withoutOverlapping();
    }
}
