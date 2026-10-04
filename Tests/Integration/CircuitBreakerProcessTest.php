<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Database\ModelClock;
use App\HttpClient\HttpResponse;
use App\Reliability\CircuitBreaker;
use App\Reliability\CircuitOpenException;
use App\Reliability\CircuitPolicy;
use App\Reliability\FileCircuitStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Native multi-process proof for the single-server file state transition. */
final class CircuitBreakerProcessTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private function breaker(CircuitProcessClock $clock): CircuitBreaker
    {
        return new CircuitBreaker(
            new FileCircuitStore($this->project->path('Storage/Circuits'), 'one'),
            $clock
        );
    }

    private static function policy(): CircuitPolicy
    {
        return new CircuitPolicy(1, 10, 5, [], [503]);
    }

    private function open(CircuitBreaker $breaker): void
    {
        $breaker->run('peer', self::policy(),
            static fn (): HttpResponse => new HttpResponse(503));
    }

    private function worker(string $marker, string $mode): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__) . '/Fixtures/CircuitProbeWorker.php',
            $this->project->path('Storage/Circuits'),
            'one', '1010', $marker, $mode,
        ]);
        $process->setTimeout(8);
        return $process;
    }

    public function testOnlyOneHalfOpenProbeRunsAcrossProcesses(): void
    {
        $clock = new CircuitProcessClock(1000);
        $this->open($this->breaker($clock));
        $firstMarker = $this->project->path('first-entered');
        $secondMarker = $this->project->path('second-entered');
        $first = $this->worker($firstMarker, 'hold');
        $first->start();
        try {
            $deadline = microtime(true) + 5;
            while (!file_exists($firstMarker) && microtime(true) < $deadline) {
                if (!$first->isRunning()) break;
                usleep(10000);
            }
            self::assertFileExists($firstMarker, $first->getErrorOutput());
            $second = $this->worker($secondMarker, 'success');
            $second->run();
            self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
            self::assertSame('blocked', trim($second->getOutput()));
            self::assertFileDoesNotExist($secondMarker);
            file_put_contents($firstMarker . '.release', 'go');
            $first->wait();
            self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
            self::assertSame('entered', trim($first->getOutput()));
            $clock->second = 1010;
            self::assertSame('closed', $this->breaker($clock)->run('peer',
                self::policy(), static fn (): string => 'closed'));
        } finally {
            if (file_exists($firstMarker)) file_put_contents($firstMarker . '.release', 'go');
            if ($first->isRunning()) $first->stop(1);
        }
    }

    public function testCrashedHalfOpenProbeExpiresAndAllowsRecovery(): void
    {
        $clock = new CircuitProcessClock(1000);
        $breaker = $this->breaker($clock);
        $this->open($breaker);
        $marker = $this->project->path('crashed-entered');
        $worker = $this->worker($marker, 'crash');
        $worker->run();
        self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        self::assertFileExists($marker);
        $clock->second = 1014;
        try {
            $breaker->run('peer', self::policy(), static fn (): string => 'too early');
            self::fail('Crashed probe lease should deny until expiry.');
        } catch (CircuitOpenException $open) {
            self::assertSame(1, $open->retryAfterSeconds());
        }
        $clock->second = 1015;
        self::assertSame('recovered', $breaker->run('peer', self::policy(),
            static fn (): string => 'recovered'));
    }
}

final class CircuitProcessClock implements ModelClock
{
    public function __construct(public int $second)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->second);
    }
}
