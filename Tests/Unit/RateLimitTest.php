<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Container\Container;
use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitException;
use App\RateLimit\RateLimitRule;
use App\RateLimit\RateLimitResult;
use App\RateLimit\RateLimitStore;
use App\RateLimit\Stores\ArrayRateLimitStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Controlled-clock fixed-window, policy, registry, and privacy contracts. */
final class RateLimitTest extends TestCase
{
    public function testFixedWindowDenialsAndExactExpiry(): void
    {
        $time = new DateTimeImmutable('@1000');
        $limiter = new RateLimiter(new ArrayRateLimitStore(), 'test', clock: static function () use (&$time): DateTimeImmutable { return $time; });
        $first = $limiter->consume('login', 'person@example.test', 2, 60);
        self::assertTrue($first->allowed());
        self::assertSame(1, $first->remaining());
        self::assertSame(0, $first->retryAfter());
        self::assertSame(1060, $first->resetsAt()->getTimestamp());
        self::assertSame('UTC', $first->resetsAt()->getTimezone()->getName());
        self::assertSame(0, $limiter->consume('login', 'person@example.test', 2, 60)->remaining());
        $time = new DateTimeImmutable('@1010');
        $denied = $limiter->consume('login', 'person@example.test', 2, 60);
        self::assertTrue($denied->denied());
        self::assertSame(50, $denied->retryAfter());
        self::assertSame(1060, $limiter->consume('login', 'person@example.test', 2, 60)->resetsAt()->getTimestamp());
        $time = new DateTimeImmutable('@1060');
        self::assertSame(1, $limiter->consume('login', 'person@example.test', 2, 60)->remaining());
    }

    public function testClearPolicyChangeAndIsolation(): void
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('@1000');
        $a = new RateLimiter(new ArrayRateLimitStore(), 'one', clock: $clock);
        $b = new RateLimiter(new ArrayRateLimitStore(), 'one', clock: $clock);
        self::assertTrue($a->consume('login', 'A', 1, 60)->allowed());
        self::assertTrue($a->consume('reset', 'A', 1, 60)->allowed());
        self::assertTrue($a->consume('login', 'B', 1, 60)->allowed());
        self::assertTrue($b->consume('login', 'A', 1, 60)->allowed());
        self::assertTrue($a->consume('login', 'A', 2, 120)->allowed());
        self::assertSame(2, $a->consume('login', 'A', 3, 120)->remaining());
        self::assertTrue($a->clear('login', 'A'));
        self::assertFalse($a->clear('login', 'A'));
        self::assertTrue($a->consume('login', 'A', 1, 60)->allowed());
    }

    public function testRuleAndResultAreImmutableAndKeepNoRawKey(): void
    {
        $rule = RateLimitRule::fixed('sensitive-user@example.test', 5, 60);
        self::assertSame(5, $rule->maxAttempts());
        self::assertSame(60, $rule->windowSeconds());
        self::assertStringNotContainsString('sensitive-user', var_export($rule, true));
        self::assertSame(64, strlen($rule->keyDigest()));
        $result = new RateLimitResult(true, 5, 4, 0, new DateTimeImmutable('2024-01-01T00:00:00-05:00'));
        self::assertSame('UTC', $result->resetsAt()->getTimezone()->getName());
        self::assertTrue($result->allowed());
        self::assertFalse($result->denied());
    }

    public function testInputValidation(): void
    {
        $limiter = new RateLimiter(new ArrayRateLimitStore(), 'test');
        foreach (['', 'bad/name', 'bad:name', 'bad name', str_repeat('x', 129)] as $bucket) {
            try { $limiter->consume($bucket, 'key', 1, 60); self::fail('Expected invalid bucket.'); }
            catch (RateLimitException) { self::assertTrue(true); }
        }
        foreach (['', "bad\0key", str_repeat('k', 1025)] as $key) {
            try { $limiter->consume('valid', $key, 1, 60); self::fail('Expected invalid key.'); }
            catch (RateLimitException) { self::assertTrue(true); }
        }
        foreach ([[0, 60], [-1, 60], [1, 0], [1, -1]] as [$attempts, $seconds]) {
            try { $limiter->consume('valid', 'key', $attempts, $seconds); self::fail('Expected invalid policy.'); }
            catch (RateLimitException) { self::assertTrue(true); }
        }
        self::assertTrue($limiter->consume('valid', 'üser@example.test', 1, 1)->allowed());
    }

    public function testNamedCallableClassResolutionAndFailures(): void
    {
        $container = new Container();
        RateLimitTestDependency::$resolved = 0;
        $container->instance(RateLimitTestDependency::class, new RateLimitTestDependency());
        $limiter = new RateLimiter(new ArrayRateLimitStore(), 'test', $container);
        $limiter->define('callable', static fn (Request $request): RateLimitRule =>
            RateLimitRule::fixed((string) $request->input('email', 'anonymous'), 1, 60));
        self::assertTrue($limiter->consumeNamed('callable', new Request())->allowed());
        self::assertTrue($limiter->consumeNamed('callable', new Request())->denied());
        $limiter->define('class', RateLimitTestResolver::class);
        self::assertSame(0, RateLimitTestDependency::$resolved);
        self::assertTrue($limiter->consumeNamed('class', new Request())->allowed());
        self::assertSame(1, RateLimitTestDependency::$resolved);
        $limiter->define('invalid', static fn (): array => []);
        $this->expectException(RateLimitException::class);
        $limiter->consumeNamed('invalid', new Request());
    }

    public function testDuplicateMissingAndResolverBusinessException(): void
    {
        $limiter = new RateLimiter(new ArrayRateLimitStore(), 'test', new Container());
        $limiter->define('test', static fn (): RateLimitRule => RateLimitRule::fixed('k', 1, 60));
        try { $limiter->define('test', static fn (): RateLimitRule => RateLimitRule::fixed('x', 1, 60)); self::fail(); }
        catch (RateLimitException) { self::assertTrue(true); }
        try { $limiter->consumeNamed('missing', new Request()); self::fail(); }
        catch (RateLimitException) { self::assertTrue(true); }
        $limiter->define('missing-class', 'Project\\RateLimits\\MissingLimiter');
        try { $limiter->consumeNamed('missing-class', new Request()); self::fail(); }
        catch (RateLimitException $failure) {
            self::assertSame('Named limiter class cannot be resolved.', $failure->getMessage());
            self::assertNotNull($failure->getPrevious());
        }
        $limiter->define('throws', static function (): never { throw new RuntimeException('business failure'); });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('business failure');
        $limiter->consumeNamed('throws', new Request());
    }

    public function testBackendFailureIsNotPermittedAndDiagnosticsRemainPrivate(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $store = new class implements RateLimitStore {
            public function consume(string $fingerprint, int $maxAttempts, int $windowSeconds, DateTimeImmutable $now): RateLimitResult
            {
                throw new RuntimeException('Disk unavailable.');
            }
            public function clear(string $fingerprint): bool { throw new RuntimeException('Disk unavailable.'); }
        };
        $limiter = new RateLimiter($store, 'test', diagnostics: $diagnostics);
        try { $limiter->consume('login', 'sensitive-user@example.test', 1, 60); self::fail(); }
        catch (RateLimitException $failure) {
            self::assertSame('Rate-limit backend consume failed.', $failure->getMessage());
            self::assertInstanceOf(RuntimeException::class, $failure->getPrevious());
        }
        try { $limiter->clear('login', 'sensitive-user@example.test'); self::fail(); }
        catch (RateLimitException $failure) {
            self::assertSame('Rate-limit backend clear failed.', $failure->getMessage());
        }
        $metrics = $diagnostics->snapshot()['rate_limit'];
        self::assertSame(1, $metrics['checks']);
        self::assertSame(0, $metrics['allowed']);
        self::assertSame(0, $metrics['denied']);
        self::assertSame(2, $metrics['errors']);
        self::assertGreaterThanOrEqual(0.0, $metrics['time_ms']);
        self::assertStringNotContainsString('sensitive-user', json_encode($metrics));
    }
}

/** Constructor injection witness for lazy named class resolution. */
final class RateLimitTestDependency
{
    public static int $resolved = 0;
}

/** Container builds this resolver only when route middleware executes. */
final class RateLimitTestResolver
{
    public function __construct(private RateLimitTestDependency $dependency)
    {
        ++RateLimitTestDependency::$resolved;
    }

    public function resolve(Request $request): RateLimitRule
    {
        return RateLimitRule::fixed('class-key', 1, 60);
    }
}
