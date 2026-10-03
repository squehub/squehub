<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Reliability\RetryPolicy;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** A retry policy plans bounded timing without executing or classifying work. */
final class RetryPolicyTest extends TestCase
{
    public function testFixedAndExponentialDelayStopAtAttemptAndDelayBounds(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4, initialDelayMs: 100,
            multiplier: 2.0, maxDelayMs: 250);
        self::assertSame(100, $policy->nextDelayMs(1, 0));
        self::assertSame(200, $policy->nextDelayMs(2, 0));
        self::assertSame(250, $policy->nextDelayMs(3, 0));
        self::assertNull($policy->nextDelayMs(4, 0));
        self::assertSame(4, $policy->maxAttempts());
    }

    public function testJitterIsBoundedAndDeterministicWithSuppliedSample(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, initialDelayMs: 100,
            maxDelayMs: 200, jitter: 0.5);
        self::assertSame(50, $policy->nextDelayMs(1, 0, jitterSample: 0.0));
        self::assertSame(100, $policy->nextDelayMs(1, 0, jitterSample: 0.5));
        self::assertSame(150, $policy->nextDelayMs(1, 0, jitterSample: 1.0));
        self::assertSame(200, $policy->nextDelayMs(1, 0, 5000, 1.0));
    }

    public function testElapsedBoundStopsBeforeSchedulingAnotherAttempt(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4, initialDelayMs: 100,
            maxElapsedMs: 250);
        self::assertSame(100, $policy->nextDelayMs(1, 100));
        self::assertNull($policy->nextDelayMs(2, 200));
        self::assertNull($policy->nextDelayMs(1, 250));
        self::assertNull($policy->nextDelayMs(1, 200, 1000));
    }

    public function testRetryAfterParsesSecondsAndHttpDateWithinBound(): void
    {
        $now = new DateTimeImmutable('2026-10-02 12:00:00', new DateTimeZone('UTC'));
        self::assertSame(0, RetryPolicy::retryAfterMs('000', $now));
        self::assertSame(2000, RetryPolicy::retryAfterMs('2', $now));
        self::assertSame(30000, RetryPolicy::retryAfterMs('999999999999999', $now));
        self::assertSame(5000, RetryPolicy::retryAfterMs('Fri, 02 Oct 2026 12:00:05 GMT', $now));
        self::assertSame(0, RetryPolicy::retryAfterMs('Fri, 02 Oct 2026 11:59:00 GMT', $now));
        self::assertNull(RetryPolicy::retryAfterMs('Fri, 32 Oct 2026 12:00:05 GMT', $now));
        self::assertNull(RetryPolicy::retryAfterMs("5\r\nX-Leak: yes", $now));
    }

    public function testInvalidPoliciesAndAttemptInputsFailClearly(): void
    {
        foreach ([
            static fn () => new RetryPolicy(maxAttempts: 0),
            static fn () => new RetryPolicy(initialDelayMs: -1),
            static fn () => new RetryPolicy(initialDelayMs: 100, maxDelayMs: 99),
            static fn () => new RetryPolicy(multiplier: INF),
            static fn () => new RetryPolicy(jitter: 1.1),
            static fn () => new RetryPolicy(maxElapsedMs: 0),
            static fn () => (new RetryPolicy())->nextDelayMs(0, 0),
            static fn () => (new RetryPolicy())->nextDelayMs(1, -1),
            static fn () => (new RetryPolicy())->nextDelayMs(1, 0, -1),
            static fn () => (new RetryPolicy())->nextDelayMs(1, 0, jitterSample: 2.0),
        ] as $make) {
            try {
                $make();
                self::fail('An invalid retry policy or attempt was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Retry', $exception->getMessage());
            }
        }
    }

    public function testPluginsSymbolIsTheCanonicalPolicyClass(): void
    {
        self::assertSame(RetryPolicy::class, (new \App\Plugins\RetryPolicy())::class);
    }
}
