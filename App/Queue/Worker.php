<?php

declare(strict_types=1);

namespace App\Queue;

use App\Auth\AuthManager;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\Application;
use App\Observability\CorrelationContext;
use App\Queue\Composition\CompositionDriver;
use App\Support\RuntimeContext;
use App\Translation\TranslationManager;
use Throwable;

/**
 * Polls a persistent driver and settles one owned reservation at a time.
 * A job exception is retried or recorded as a safe failure; a database or
 * reservation failure escapes so operators can address the infrastructure.
 * Cooperative stop requests take effect between jobs.
 */
final class Worker
{
    private bool $stop = false;
    private string $exitReason = 'completed';

    public function __construct(private QueueManager $manager, private ?Diagnostics $diagnostics = null,
        private ?Container $container = null, private ?RestartSignal $restartSignal = null)
    {
    }

    public function stop(): void { $this->stop = true; }
    public function exitReason(): string { return $this->exitReason; }

    /** Process at most one job; return false when no eligible job exists. */
    public function workOnce(string $queue = 'default', ?string $connection = null,
        int $tries = 3, int $backoff = 5, ?int $timeout = null): bool
    {
        $translation = null;
        try {
            $translation = $this->prepareJobContext();
            return $this->processOne($queue, $connection, $tries, $backoff, $timeout);
        } catch (Throwable $exception) {
            // Ordinary job exceptions are settled below; this counter records
            // infrastructure failures that escape the worker boundary.
            $this->diagnostics?->queue('errors');
            throw $exception;
        } finally {
            // A long-running worker must not retain a request identity from a
            // job that happened to resolve Auth through its Application.
            try {
                if ($this->container?->has(AuthManager::class)) {
                    $this->container->make(AuthManager::class)->resetRequestState();
                }
            } finally {
                // A queued job may choose its own locale. Even failed Auth
                // cleanup cannot carry that choice into the next reservation.
                try {
                    $translation?->endScope();
                } finally {
                    $this->correlation()?->clear();
                }
            }
        }
    }

    /** Rebind static developer APIs before each attempt, even after another Application ran. */
    private function prepareJobContext(): ?TranslationManager
    {
        if ($this->container?->has(Application::class)) {
            RuntimeContext::select($this->container->make(Application::class));
        }
        // An idle poll or a malformed reservation cannot inherit the previous
        // job's correlation, including on a long-running worker process.
        $this->correlation()?->clear();
        if ($this->container?->has(AuthManager::class)) {
            $this->container->make(AuthManager::class)->resetRequestState();
        }
        if ($this->container?->has(TranslationManager::class)) {
            $translation = $this->container->make(TranslationManager::class);
            $translation->beginScope();
            return $translation;
        }
        return null;
    }

    private function processOne(string $queue, ?string $connection, int $tries, int $backoff,
        ?int $timeout): bool
    {
        QueueManager::name($queue);
        if ($tries < 1 || $backoff < 0 || ($timeout !== null && $timeout < 1)) {
            throw new QueueException('Worker retry or timeout policy is invalid.');
        }
        $driver = $this->manager->driver($connection);
        if (!$driver instanceof PersistentQueueDriver) {
            throw new QueueException('Queue worker requires a persistent connection.');
        }
        $reservationStarted = hrtime(true);
        $reservationFailed = false;
        $connectionName = $connection ?? $this->manager->defaultName();
        try {
            $reserved = $driver->reserve($queue);
        } catch (Throwable $failure) {
            $reservationFailed = true;
            throw $failure;
        } finally {
            $this->diagnostics?->observe('queue.reserve',
                (hrtime(true) - $reservationStarted) / 1_000_000,
                $reservationFailed, ['queue' => $queue, 'connection' => $connectionName]);
        }
        if ($reserved === null) return false;
        try {
            $storedCorrelation = QueueCodec::correlationId($reserved->payload);
        } catch (QueueException) {
            $driver->fail($reserved, 'unknown', 'InvalidPayload', 'Invalid queue payload.');
            $this->diagnostics?->queue('failed');
            return true;
        }
        $correlationId = $this->correlation()?->begin($storedCorrelation)
            ?? $storedCorrelation ?? CorrelationContext::generate();
        try {
            $scope = $this->diagnostics?->observability()?->begin('queue.job',
                ['queue' => $queue, 'connection' => $connectionName,
                    'attempt' => $reserved->attempts], $correlationId);
        } catch (Throwable) { $scope = null; }
        $jobFailed = false;
        try {
            if ($reserved->compositionId !== null && $driver instanceof CompositionDriver
                && !$driver->shouldRunComposition($reserved)) {
                // Cancellation is cooperative: a reservation that has not entered
                // its handler can be settled without exposing its stored payload.
                $driver->skipComposition($reserved);
                return true;
            }
            try {
                $job = QueueCodec::decode($reserved->payload);
                if ($job instanceof QueueContextAware && $this->container !== null) {
                    $job->setQueueContainer($this->container);
                }
                if ($job instanceof QueueAttemptContextAware) {
                    $job->setQueueAttemptContext($reserved->attempts, $tries);
                }
                try {
                    $this->diagnostics?->observability()?->annotateRoot(['job_class' => $job::class]);
                } catch (Throwable) { /* Job execution remains independent of telemetry. */ }
            } catch (QueueException) {
                // Corrupt records cannot become valid through another attempt.
                $jobFailed = true;
                $driver->fail($reserved, 'unknown', 'InvalidPayload', 'Invalid queue payload.');
                $this->diagnostics?->queue('failed');
                return true;
            }
            try {
                $started = hrtime(true);
                $hardTimeout = $timeout !== null && function_exists('pcntl_alarm')
                    && function_exists('pcntl_signal') && defined('SIGALRM');
                if ($hardTimeout) {
                    $previous = function_exists('pcntl_signal_get_handler')
                        ? pcntl_signal_get_handler(SIGALRM) : SIG_DFL;
                    pcntl_async_signals(true);
                    pcntl_signal(SIGALRM, static function (): void {
                        throw new QueueTimeoutException('Queue job exceeded its time limit.');
                    });
                    pcntl_alarm($timeout);
                }
                try {
                    $job->handle();
                } finally {
                    if ($hardTimeout) {
                        pcntl_alarm(0);
                        pcntl_signal(SIGALRM, $previous);
                    }
                }
                // On platforms without pcntl, this is a soft limit. A blocked job
                // cannot be interrupted, and its side effects may already exist.
                if ($timeout !== null && (hrtime(true) - $started) >= $timeout * 1_000_000_000) {
                    throw new QueueTimeoutException('Queue job exceeded its time limit.');
                }
            } catch (Throwable $exception) {
                $jobFailed = true;
                if ($exception instanceof QueueTimeoutException) $this->diagnostics?->queue('timeouts');
                if ($reserved->attempts >= $tries) {
                    // Do not persist arbitrary exception messages: job code can
                    // place payload secrets in them, even after configured redaction.
                    $driver->fail($reserved, $job::class, $exception::class,
                        'Job failed after maximum attempts.');
                    $this->diagnostics?->queue('failed');
                } else {
                    $driver->release($reserved, $backoff);
                    $this->diagnostics?->queue('retried');
                }
                return true;
            }
            $driver->acknowledge($reserved);
            $this->diagnostics?->queue('processed');
            return true;
        } catch (Throwable $failure) {
            $jobFailed = true;
            throw $failure;
        } finally {
            try { $scope?->finish(failed: $jobFailed); } catch (Throwable) {}
        }
    }

    /** Boundless mode can be stopped cooperatively; --once avoids sleeps. */
    public function run(string $queue = 'default', ?string $connection = null, int $tries = 3,
        int $backoff = 5, int $sleep = 1, bool $once = false, ?int $maxJobs = null,
        ?int $maxTime = null, ?int $memory = null, ?int $timeout = null,
        bool $stopWhenEmpty = false): int
    {
        if ($sleep < 1 || ($maxJobs !== null && $maxJobs < 1)
            || ($maxTime !== null && $maxTime < 1) || ($memory !== null && $memory < 1)
            || ($timeout !== null && $timeout < 1)) {
            throw new QueueException('Worker limits are invalid.');
        }
        $started = hrtime(true);
        $restart = $this->manager->restartSignal($connection) ?? $this->restartSignal;
        $generation = $restart?->current();
        $this->exitReason = 'completed';
        $this->diagnostics?->queue('worker_starts');
        $processed = 0;
        try {
            while (true) {
                if ($this->stop) { $this->exitReason = 'shutdown'; break; }
                if ($maxTime !== null && (hrtime(true) - $started) >= $maxTime * 1_000_000_000) {
                    $this->exitReason = 'max_time'; break;
                }
                if ($memory !== null && memory_get_usage(true) > $memory * 1048576) {
                    $this->exitReason = 'memory';
                    $this->diagnostics?->queue('memory_exits');
                    break;
                }
                if ($restart !== null && $restart->current() !== $generation) {
                    $this->exitReason = 'restart';
                    $this->diagnostics?->queue('restart_exits');
                    break;
                }
                $found = $this->workOnce($queue, $connection, $tries, $backoff, $timeout);
                if ($found) ++$processed;
                if ($once) break;
                if (!$found && $stopWhenEmpty) {
                    $this->exitReason = 'empty';
                    break;
                }
                if ($maxJobs !== null && $processed >= $maxJobs) {
                    $this->exitReason = 'max_jobs'; break;
                }
                // Preserve configured idle delay, but observe controls every second.
                if (!$found) {
                    for ($idle = 0; $idle < $sleep; ++$idle) {
                        sleep(1);
                        if ($this->idleControlRequested($started, $maxTime, $restart, $generation)) break;
                    }
                }
            }
        } catch (Throwable $failure) {
            $this->exitReason = 'error';
            throw $failure;
        } finally {
            $this->diagnostics?->queue('worker_stops');
        }
        return $processed;
    }

    /** Check signals and restart changes again after each second of idle sleep. */
    private function idleControlRequested(int $started, ?int $maxTime,
        RestartSignal|RedisRestartSignal|null $restart, ?string $generation): bool
    {
        return $this->stop
            || ($maxTime !== null && (hrtime(true) - $started) >= $maxTime * 1_000_000_000)
            || ($restart !== null && $restart->current() !== $generation);
    }

    private function correlation(): ?CorrelationContext
    {
        return $this->diagnostics?->correlation() ?? $this->manager->correlationContext();
    }
}
