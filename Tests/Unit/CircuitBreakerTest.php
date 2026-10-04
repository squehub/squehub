<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\ModelClock;
use App\Foundation\Application;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpResponse;
use App\Reliability\CircuitBreaker;
use App\Reliability\CircuitException;
use App\Reliability\CircuitOpenException;
use App\Reliability\CircuitPolicy;
use App\Reliability\CircuitServiceProvider;
use App\Reliability\FileCircuitStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Deterministic single-server state, classification, and lifecycle contracts. */
final class CircuitBreakerTest extends TestCase
{
    private TemporaryProject $project;
    private CircuitTestClock $clock;
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->clock = new CircuitTestClock(1000);
        $this->breaker = new CircuitBreaker(
            new FileCircuitStore($this->project->path('Storage/Circuits'), 'one'),
            $this->clock
        );
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private static function policy(int $threshold = 2): CircuitPolicy
    {
        return new CircuitPolicy($threshold, 10, 5,
            [HttpConnectionException::class], [503]);
    }

    public function testExplicitFailuresAccumulateAndSuccessResetsConsecutiveCount(): void
    {
        $policy = self::policy();
        self::assertSame('ok', $this->breaker->run('billing', $policy, static fn (): string => 'ok'));
        self::assertSame(503, $this->breaker->run('billing', $policy,
            static fn (): HttpResponse => new HttpResponse(503))->status());
        self::assertSame(200, $this->breaker->run('billing', $policy,
            static fn (): HttpResponse => new HttpResponse(200))->status());
        self::assertSame(503, $this->breaker->run('billing', $policy,
            static fn (): HttpResponse => new HttpResponse(503))->status());
        self::assertSame(503, $this->breaker->run('billing', $policy,
            static fn (): HttpResponse => new HttpResponse(503))->status());
        $called = false;
        try {
            $this->breaker->run('billing', $policy, static function () use (&$called): void {
                $called = true;
            });
            self::fail('Open circuit should reject before invoking the operation.');
        } catch (CircuitOpenException $open) {
            self::assertSame(10, $open->retryAfterSeconds());
        }
        self::assertFalse($called);
    }

    public function testCooldownSingleProbeSuccessAndProbeFailure(): void
    {
        $policy = self::policy(1);
        $this->breaker->run('peer', $policy, static fn (): HttpResponse => new HttpResponse(503));
        $this->clock->second = 1009;
        try {
            $this->breaker->run('peer', $policy, static fn (): string => 'unreachable');
            self::fail('Circuit should still be in cooldown.');
        } catch (CircuitOpenException $open) {
            self::assertSame(1, $open->retryAfterSeconds());
        }
        $this->clock->second = 1010;
        try {
            $this->breaker->run('peer', $policy, static fn (): HttpResponse => new HttpResponse(503));
        } catch (CircuitOpenException) {
            self::fail('Cooldown should admit one probe.');
        }
        try {
            $this->breaker->run('peer', $policy, static fn (): string => 'unreachable');
            self::fail('Failed probe should reopen the circuit.');
        } catch (CircuitOpenException $open) {
            self::assertSame(10, $open->retryAfterSeconds());
        }
        $this->clock->second = 1020;
        self::assertSame('recovered', $this->breaker->run('peer', $policy,
            static fn (): string => 'recovered'));
        self::assertSame('normal', $this->breaker->run('peer', $policy,
            static fn (): string => 'normal'));
    }

    public function testOnlyDeclaredExceptionsCountAndNeutralProbeDoesNotClaimHealth(): void
    {
        $policy = self::policy(2);
        $propagated = false;
        try {
            $this->breaker->run('mail', $policy, static function (): void {
                throw new RuntimeException('application failure');
            });
        } catch (RuntimeException $failure) {
            $propagated = true;
            self::assertSame('application failure', $failure->getMessage());
        }
        self::assertTrue($propagated);
        $this->breaker->run('mail', $policy,
            static fn (): HttpResponse => new HttpResponse(503));
        // The unrelated exception did not consume the first failure slot.
        self::assertSame('called', $this->breaker->run('mail', $policy,
            static fn (): string => 'called'));

        $this->breaker->run('mail', $policy,
            static fn (): HttpResponse => new HttpResponse(503));
        $propagated = false;
        try {
            $this->breaker->run('mail', $policy, static function (): void {
                throw new HttpConnectionException('network detail');
            });
        } catch (HttpConnectionException) {
            $propagated = true;
        }
        self::assertTrue($propagated);
        $this->clock->second = 1010;
        $propagated = false;
        try {
            $this->breaker->run('mail', $policy, static function (): void {
                throw new RuntimeException('unrelated probe failure');
            });
        } catch (RuntimeException) {
            $propagated = true;
        }
        self::assertTrue($propagated);
        $this->expectException(CircuitOpenException::class);
        $this->breaker->run('mail', $policy, static fn (): string => 'blocked');
    }

    public function testExpiredProbeAndOlderClosedCallCannotOverwriteNewGeneration(): void
    {
        $policy = self::policy(1);
        $this->breaker->run('remote', $policy,
            static fn (): HttpResponse => new HttpResponse(503));
        $this->clock->second = 1010;
        $propagated = false;
        try {
            $this->breaker->run('remote', $policy, function (): void {
                // The original probe was paused past its lease. A newer one
                // succeeds, then the stale probe reports a classified failure.
                $this->clock->second = 1016;
                self::assertSame('new probe', $this->breaker->run('remote',
                    self::policy(1), static fn (): string => 'new probe'));
                throw new HttpConnectionException('stale probe');
            });
        } catch (HttpConnectionException) {
            $propagated = true;
            self::assertSame('still closed', $this->breaker->run('remote', $policy,
                static fn (): string => 'still closed'));
        }
        self::assertTrue($propagated);

        // A closed call begun before the open cycle cannot re-open a recovered
        // generation when it eventually finishes.
        $this->breaker->run('remote', $policy, function () use ($policy): string {
            $this->breaker->run('remote', $policy,
                static fn (): HttpResponse => new HttpResponse(503));
            $this->clock->second = 1026;
            $this->breaker->run('remote', $policy, static fn (): string => 'probe ok');
            return 'old success';
        });
        self::assertSame('healthy', $this->breaker->run('remote', $policy,
            static fn (): string => 'healthy'));
    }

    public function testRetryAttemptsInsideOneLogicalCallCountOnce(): void
    {
        $policy = self::policy(2);
        $http = new HttpClient();
        $http->fake(['GET https://example.test/phase24' => array_fill(0, 6, new HttpResponse(503))]);
        $call = static fn (): HttpResponse =>
            $http->pending()->retry(3, 0)->get('https://example.test/phase24');
        self::assertSame(503, $this->breaker->run('http-dependency', $policy, $call)->status());
        self::assertCount(3, $http->captured());
        self::assertSame(503, $this->breaker->run('http-dependency', $policy, $call)->status());
        self::assertCount(6, $http->captured());
        $this->expectException(CircuitOpenException::class);
        $this->breaker->run('http-dependency', $policy, $call);
    }

    public function testPolicyChangeKeepsExistingDeadlineAndUsesNewPolicyForLaterTransitions(): void
    {
        $original = new CircuitPolicy(1, 10, 5, [], [503]);
        $changed = new CircuitPolicy(2, 20, 2, [], [502]);
        $this->breaker->run('config-change', $original,
            static fn (): HttpResponse => new HttpResponse(503));

        $this->clock->second = 1005;
        try {
            $this->breaker->run('config-change', $changed,
                static fn (): string => 'not admitted');
            self::fail('Existing cooldown must survive a policy change.');
        } catch (CircuitOpenException $open) {
            self::assertSame(5, $open->retryAfterSeconds());
        }

        $this->clock->second = 1010;
        self::assertSame(502, $this->breaker->run('config-change', $changed,
            static fn (): HttpResponse => new HttpResponse(502))->status());
        try {
            $this->breaker->run('config-change', $original,
                static fn (): string => 'not admitted');
            self::fail('The new policy sets the next cooldown.');
        } catch (CircuitOpenException $open) {
            self::assertSame(20, $open->retryAfterSeconds());
        }
        $this->clock->second = 1030;
        self::assertSame('healthy', $this->breaker->run('config-change', $changed,
            static fn (): string => 'healthy'));
        self::assertSame(502, $this->breaker->run('config-change', $changed,
            static fn (): HttpResponse => new HttpResponse(502))->status());
        self::assertSame('still admitted', $this->breaker->run('config-change', $changed,
            static fn (): string => 'still admitted'));
    }

    public function testSettlementFailureTakesPrecedenceOverCallbackFailureAndFailsClosed(): void
    {
        $this->breaker->run('settlement', self::policy(), static fn (): string => 'ready');
        $record = $this->project->path('Storage/Circuits') . '/'
            . hash('sha256', 'one') . '/' . hash('sha256', 'settlement') . '.json';
        $callbackRan = false;
        $settlementFailed = false;
        try {
            $this->breaker->run('settlement', self::policy(),
                static function () use ($record, &$callbackRan): void {
                    $callbackRan = true;
                    file_put_contents($record, '{broken');
                    throw new HttpConnectionException('remote failure');
                });
        } catch (CircuitException $failure) {
            $settlementFailed = true;
            self::assertStringContainsString('corrupt', $failure->getMessage());
        }
        self::assertTrue($settlementFailed);
        self::assertTrue($callbackRan);
        $laterRan = false;
        try {
            $this->breaker->run('settlement', self::policy(),
                static function () use (&$laterRan): void { $laterRan = true; });
            self::fail('Corrupt state must fail closed before a later callback.');
        } catch (CircuitException) {
            self::assertFalse($laterRan);
        }
    }

    public function testValidationCorruptionPrivacyAndClear(): void
    {
        foreach (['', '../secret', 'bad/name', "bad\0name", str_repeat('x', 129)] as $name) {
            try {
                $this->breaker->run($name, self::policy(),
                    static fn (): string => 'unexpected');
                self::fail('Invalid circuit name should fail.');
            } catch (CircuitException) {
                self::assertTrue(true);
            }
        }
        foreach ([
            [0, 10, 5, [HttpConnectionException::class], []],
            [1, 0, 5, [HttpConnectionException::class], []],
            [1, 10, 0, [HttpConnectionException::class], []],
            [1, 10, 5, [], []],
            [1, 10, 5, ['not-a-class'], []],
            [1, 10, 5, [], [200]],
        ] as $arguments) {
            try { new CircuitPolicy(...$arguments); self::fail('Invalid policy should fail.'); }
            catch (CircuitException) { self::assertTrue(true); }
        }
        $root = $this->project->path('Storage/Circuits');
        self::assertDirectoryDoesNotExist($root);
        $this->breaker->run('private-service', self::policy(1),
            static fn (): HttpResponse => new HttpResponse(503));
        $record = $root . '/' . hash('sha256', 'one') . '/'
            . hash('sha256', 'private-service') . '.json';
        self::assertFileExists($record);
        self::assertStringNotContainsString('private-service', (string) file_get_contents($record));
        file_put_contents($record, '{broken');
        $called = false;
        try {
            $this->breaker->run('private-service', self::policy(),
                static function () use (&$called): void { $called = true; });
            self::fail('Corrupt state should fail closed.');
        } catch (CircuitException $failure) {
            self::assertStringContainsString('corrupt', $failure->getMessage());
        }
        self::assertFalse($called);
        $this->breaker->clear('private-service');
        self::assertFileDoesNotExist($record);
        self::assertSame('recovered', $this->breaker->run('private-service',
            self::policy(), static fn (): string => 'recovered'));
    }

    public function testProviderAndPluginsAliasesKeepApplicationsIsolated(): void
    {
        $second = new TemporaryProject();
        try {
            $firstApp = new Application($this->project->path());
            $secondApp = new Application($second->path());
            $firstApp->register(CircuitServiceProvider::class);
            $secondApp->register(CircuitServiceProvider::class);
            $firstApp->bootstrap();
            $secondApp->bootstrap();
            $first = $firstApp->container()->make(CircuitBreaker::class);
            $other = $secondApp->container()->make(CircuitBreaker::class);
            self::assertNotSame($first, $other);
            self::assertSame($first,
                $firstApp->container()->make(\App\Plugins\CircuitBreaker::class));
            self::assertSame(CircuitPolicy::class,
                (new \ReflectionClass(\App\Plugins\CircuitPolicy::class))->getName());
            self::assertSame(CircuitOpenException::class,
                (new \ReflectionClass(\App\Plugins\CircuitOpenException::class))->getName());
            $policy = self::policy(1);
            $first->run('same', $policy, static fn (): HttpResponse => new HttpResponse(503));
            self::assertSame('isolated', $other->run('same', $policy,
                static fn (): string => 'isolated'));
        } finally {
            $second->remove();
        }
    }
}

final class CircuitTestClock implements ModelClock
{
    public function __construct(public int $second)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->second);
    }
}
