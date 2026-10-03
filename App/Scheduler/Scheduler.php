<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Contributions\ContributionOwner;
use App\Contributions\ContributionRegistry;
use App\Container\Container;
use App\Database\Connection;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Diagnostics\Diagnostics;
use App\Observability\CorrelationContext;
use App\Queue\QueueJob;
use App\Queue\QueueManager;
use App\Scheduler\Stores\ArrayScheduleStore;
use App\Scheduler\Stores\DatabaseScheduleStore;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Owns one Application's definitions and evaluates them at one captured tick.
 * Persisted claims prevent duplicate occurrences; Queue is resolved only when
 * a due job needs dispatch. A task failure cannot prevent unrelated due work.
 */
final class Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];
    private ?ScheduleStore $store = null;
    /** Definition files belong to one Application and must not be included twice. */
    private string $definitionSourceState = 'new';
    private ModelClock $clock;
    private string $timezone;
    private string $prefix;

    /** @var array<int, array{owner:ContributionOwner,source:?string}> */
    private array $taskOwners = [];

    /**
     * @param array<string,mixed> $settings
     * @param ?Closure(?string):Connection $databaseConnection
     * @param ?Closure():QueueManager $queueResolver
     */
    public function __construct(private Container $container, private array $settings,
        private ?Closure $databaseConnection = null, private ?Closure $queueResolver = null,
        ?ModelClock $clock = null, private ?Diagnostics $diagnostics = null,
        private ?ContributionRegistry $contributions = null)
    {
        $this->clock = $clock ?? new SystemModelClock();
        $timezone = $settings['timezone'] ?? 'UTC';
        $prefix = $settings['prefix'] ?? 'squehub';
        if (!is_string($timezone) || $timezone === '') {
            throw new SchedulerException('Scheduler timezone is invalid.');
        }
        try { new DateTimeZone($timezone); }
        catch (Throwable $exception) { throw new SchedulerException('Scheduler timezone is invalid.', 0, $exception); }
        if (!is_string($prefix) || strlen($prefix) > 128
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $prefix) !== 1) {
            throw new SchedulerException('Scheduler prefix is invalid.');
        }
        $this->timezone = $timezone;
        $this->prefix = $prefix;
        if (!in_array($settings['store'] ?? 'database', ['database', 'array'], true)) {
            throw new SchedulerException('Scheduler store is unsupported.');
        }
    }

    public function job(QueueJob $job, ?string $connection = null, string $queue = 'default',
        int $delay = 0): ScheduledTask
    {
        $task = new ScheduledTask($job, null, $this->timezone, $connection, $queue, $delay);
        $this->rememberOwner($task);
        return $this->tasks[] = $task;
    }

    /** @param Closure|array{class-string,string}|class-string $callback */
    public function call(Closure|array|string $callback): ScheduledTask
    {
        $task = new ScheduledTask(null, $callback, $this->timezone);
        $this->rememberOwner($task);
        return $this->tasks[] = $task;
    }

    /** @return list<ScheduledTask> */
    public function definitions(): array
    {
        $seen = [];
        foreach ($this->tasks as $task) {
            $task->validate();
            $name = strtolower($task->taskName());
            if (isset($seen[$name])) throw new SchedulerException('Schedule names must be unique.');
            $seen[$name] = true;
            $provenance = $this->taskOwners[spl_object_id($task)] ?? null;
            if ($provenance !== null) {
                $this->contributions?->record('scheduler', $task->taskName(), $provenance['source'],
                    ['schedule' => $task->description(), 'mode' => $task->mode()], $provenance['owner']);
            }
        }
        return $this->tasks;
    }

    /**
     * Project validated declarations without resolving callbacks, jobs, or the
     * persistence store. CLI and Studio consume the same safe task metadata.
     *
     * @return list<array{name:string,schedule:string,timezone:string,mode:string,queue:string,overlap:?int}>
     */
    public function inspectDefinitions(): array
    {
        $rows = [];
        foreach ($this->definitions() as $task) {
            $rows[] = [
                'name' => $task->taskName(),
                'schedule' => $task->description(),
                'timezone' => $task->timezoneName(),
                'mode' => $task->mode(),
                'queue' => $task->mode() === 'job' ? $task->queueName() : '-',
                'overlap' => $task->overlapSeconds(),
            ];
        }
        return $rows;
    }

    /**
     * Claim the one source-loading pass for this Application. A partial failed
     * pass cannot be retried against definitions it may already have registered.
     */
    public function beginDefinitionSourceLoad(): bool
    {
        if ($this->definitionSourceState === 'loaded') return false;
        if ($this->definitionSourceState !== 'new') {
            throw new SchedulerException('Schedule definition loading cannot be retried.');
        }
        $this->definitionSourceState = 'loading';
        return true;
    }

    /** @internal Called by ScheduleLoader after all files register successfully. */
    public function completeDefinitionSourceLoad(): void
    {
        if ($this->definitionSourceState !== 'loading') {
            throw new SchedulerException('Schedule definition loading is not active.');
        }
        $this->definitionSourceState = 'loaded';
    }

    /** @internal A failed pass may have registered a subset of definitions. */
    public function failDefinitionSourceLoad(): void
    {
        $this->definitionSourceState = 'failed';
    }

    private function rememberOwner(ScheduledTask $task): void
    {
        $owner = $this->contributions?->currentOwner();
        if ($owner !== null) {
            $this->taskOwners[spl_object_id($task)] = [
                'owner' => $owner,
                'source' => $this->contributions?->currentSource(),
            ];
        }
    }

    /** One clock read ensures all definitions use the same minute boundary. */
    public function run(?DateTimeImmutable $instant = null): SchedulerRunResult
    {
        $tasks = $this->definitions();
        $now = ($instant ?? $this->clock->now())->setTimezone(new DateTimeZone('UTC'));
        $started = hrtime(true);
        $context = $this->diagnostics?->correlation();
        $previousCorrelation = $context?->current();
        $correlationId = CorrelationContext::generate();
        $context?->begin($correlationId);
        try { $scope = $this->diagnostics?->observability()?->begin('scheduler.tick', [], $correlationId); }
        catch (Throwable) { $scope = null; }
        $evaluated = $due = $executed = $queued = $skipped = $failed = 0;
        try {
            foreach ($tasks as $task) {
                ++$evaluated;
                $this->diagnostics?->scheduler('evaluated');
                $taskFailed = false;
                $taskResult = 'not_due';
                $taskScope = null;
                if ($this->diagnostics?->observability()?->enabled() === true) {
                    try {
                        $taskScope = $this->diagnostics->observability()->begin('scheduler.task',
                            ['task' => $task->taskName(), 'mode' => $task->mode()]);
                    } catch (Throwable) { /* Scheduling remains independent of telemetry. */ }
                }
                try {
                    if (!$task->isDue($now)) continue;
                    $taskResult = 'due';
                    ++$due;
                    $this->diagnostics?->scheduler('due');
                    $store = $this->store();
                    $key = $task->taskKey($this->prefix);
                    $occurrence = $task->occurrence($now);
                    $token = null;
                    $claimed = false;
                    $failureRecorded = false;
                    try {
                        if ($task->overlapSeconds() !== null) {
                            $token = $store->acquireOverlap($key, $now, $task->overlapSeconds());
                            if ($token === null) {
                                ++$skipped;
                                $taskResult = 'skipped';
                                $this->diagnostics?->scheduler('skipped');
                                continue;
                            }
                        }
                        if (!$store->claim($key, $occurrence, $now)) {
                            ++$skipped;
                            $taskResult = 'skipped';
                            $this->diagnostics?->scheduler('skipped');
                            continue;
                        }
                        $claimed = true;
                        $this->diagnostics?->scheduler('claimed');
                        if ($task->mode() === 'job') {
                            if ($this->queueResolver === null) throw new SchedulerException('Queue service is unavailable.');
                            $task->dispatch(($this->queueResolver)());
                        } else {
                            $task->runCall($this->container);
                        }
                        $store->finish($key, $occurrence, 'completed', $this->clock->now());
                        if ($task->mode() === 'job') {
                            ++$queued;
                            $taskResult = 'queued';
                            $this->diagnostics?->scheduler('queued');
                        } else {
                            ++$executed;
                            $taskResult = 'executed';
                            $this->diagnostics?->scheduler('executed');
                        }
                    } catch (Throwable) {
                        $taskFailed = true;
                        $taskResult = 'failed';
                        // Preserve the claim even on failure. Retrying the same
                        // minute automatically could repeat completed side effects.
                        if ($claimed) {
                            try { $store->finish($key, $occurrence, 'failed', $this->clock->now()); }
                            catch (Throwable) { /* The original failure still marks this tick failed. */ }
                        }
                        ++$failed;
                        $failureRecorded = true;
                        $this->diagnostics?->scheduler('failed');
                    } finally {
                        if ($token !== null) {
                            try { $store->releaseOverlap($key, $token); }
                            catch (Throwable) {
                                if (!$failureRecorded) {
                                    ++$failed;
                                    $taskFailed = true;
                                    $taskResult = 'failed';
                                    $this->diagnostics?->scheduler('failed');
                                }
                            }
                        }
                    }
                } catch (Throwable) {
                    $taskFailed = true;
                    $taskResult = 'failed';
                    // A broken store or one invalid task must not hide later due
                    // entries. CLI receives only aggregate, safe failure counts.
                    ++$failed;
                    $this->diagnostics?->scheduler('failed');
                } finally {
                    try { $taskScope?->finish(['result' => $taskResult], $taskFailed); }
                    catch (Throwable) { /* Scheduler state is authoritative. */ }
                }
            }
        } finally {
            $this->diagnostics?->schedulerTime((hrtime(true) - $started) / 1_000_000);
            try { $scope?->finish(failed: $failed > 0); } catch (Throwable) {}
            // A nested manual tick must not replace its caller's context; a
            // daemon tick ends with no live correlation for the next run.
            if ($previousCorrelation === null) $context?->clear();
            else $context?->begin($previousCorrelation);
        }
        return new SchedulerRunResult($evaluated, $due, $executed, $queued, $skipped, $failed);
    }

    private function store(): ScheduleStore
    {
        if ($this->store !== null) return $this->store;
        if (($this->settings['store'] ?? 'database') === 'array') {
            return $this->store = new ArrayScheduleStore();
        }
        if ($this->databaseConnection === null) throw new SchedulerException('Scheduler database service is unavailable.');
        $name = $this->settings['database_connection'] ?? null;
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new SchedulerException('Scheduler database connection is invalid.');
        }
        return $this->store = new DatabaseScheduleStore(($this->databaseConnection)($name),
            $this->settings['runs_table'] ?? 'schedule_runs',
            $this->settings['locks_table'] ?? 'schedule_locks');
    }
}
