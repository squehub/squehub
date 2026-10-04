<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\ModelClock;
use App\Locks\LockBusyException;
use App\Locks\LockBackendException;
use App\Locks\LockConfigurationException;
use App\Locks\LockManager;
use App\Locks\LockOwnershipException;
use App\Locks\LockStore;
use App\Locks\Stores\ArrayLockStore;
use App\Locks\Stores\RedisLockStore;
use App\Redis\RedisClient;
use App\Redis\RedisConnection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LockTestClock implements ModelClock
{
    public function __construct(public int $epoch = 1_800_000_000) {}
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->epoch); }
    public function advance(int $seconds): void { $this->epoch += $seconds; }
}

final class LockFakeRedisClient implements RedisClient
{
    /** @var array<string,string> */
    public array $values = [];
    /** @var list<list<string>> */
    public array $commands = [];

    public function execute(array $arguments): mixed
    {
        $this->commands[] = $arguments;
        if ($arguments[0] === 'SET') {
            if (isset($this->values[$arguments[1]])) return null;
            $this->values[$arguments[1]] = $arguments[2];
            return 'OK';
        }
        if ($arguments[0] === 'EVAL') {
            $key = $arguments[3];
            if (!isset($this->values[$key])) return 0;
            if ($this->values[$key] !== $arguments[4]) return -1;
            unset($this->values[$key]);
            return 1;
        }
        throw new \RuntimeException('Unexpected Redis command.');
    }

    public function close(): void {}
}

/** Contract, expiry, wait bounds, key safety, and Redis atomic operation shape. */
final class LockTest extends TestCase
{
    private function manager(?LockStore $store = null, ?LockTestClock $clock = null): LockManager
    {
        return new LockManager($store ?? new ArrayLockStore(), 'unit-app',
            $clock ?? new LockTestClock(), 'array');
    }

    public function testAcquireDuplicateReleaseAndReacquire(): void
    {
        $manager = $this->manager();
        $first = $manager->acquire('invoice:123', 30);
        self::assertTrue($first->acquired());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first->ownerToken());
        self::assertNotNull($first->expiresAt());
        self::assertFalse($manager->acquire('invoice:123', 30)->acquired());
        self::assertTrue($first->release());
        self::assertTrue($first->released());
        self::assertFalse($first->release());
        self::assertTrue($manager->acquire('invoice:123', 30)->acquired());
    }

    public function testExpiredLeaseCanBeReacquiredButOldOwnerCannotReleaseNewLease(): void
    {
        $clock = new LockTestClock();
        $manager = $this->manager(clock: $clock);
        $old = $manager->acquire('key', 1);
        $clock->advance(1);
        $new = $manager->acquire('key', 30);
        self::assertTrue($new->acquired());
        self::assertNotSame($old->ownerToken(), $new->ownerToken());
        $this->expectException(LockOwnershipException::class);
        try {
            $old->release();
        } finally {
            self::assertTrue($new->release());
        }
    }

    public function testReleaseAfterExpiryWithoutNewOwnerIsAFalseMiss(): void
    {
        $clock = new LockTestClock();
        $lock = $this->manager(clock: $clock)->acquire('key', 1);
        $clock->advance(1);
        self::assertFalse($lock->release());
    }

    public function testWaitTimesOutOnMonotonicBound(): void
    {
        $manager = $this->manager();
        $held = $manager->acquire('busy');
        $start = hrtime(true);
        $miss = $manager->acquire('busy', 30, 0.05);
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;
        self::assertFalse($miss->acquired());
        self::assertNull($miss->ownerToken());
        self::assertGreaterThanOrEqual(0.04, $elapsed);
        self::assertLessThan(0.5, $elapsed);
        self::assertTrue($held->release());
    }

    public function testWaitSucceedsWhenAStoreBecomesAvailable(): void
    {
        $store = new class implements LockStore {
            public int $attempts = 0;
            public function acquire(string $hash, string $token, int $now, int $expiresAt): bool
            {
                return ++$this->attempts >= 2;
            }
            public function release(string $hash, string $token, int $now): bool { return true; }
        };
        $manager = $this->manager($store);
        $handle = $manager->acquire('later', 30, 0.1);
        self::assertTrue($handle->acquired());
        self::assertSame(2, $store->attempts);
        self::assertTrue($handle->release());
    }

    public function testRunReleasesAfterCallbackFailure(): void
    {
        $manager = $this->manager();
        $caught = false;
        try {
            $manager->run('operation', static function (): void {
                throw new \RuntimeException('callback failed');
            });
        } catch (\RuntimeException $failure) {
            $caught = true;
            self::assertSame('callback failed', $failure->getMessage());
        }
        self::assertTrue($caught);
        self::assertTrue($manager->acquire('operation')->acquired());
    }

    public function testRunRejectsContention(): void
    {
        $manager = $this->manager();
        $manager->acquire('busy');
        $this->expectException(LockBusyException::class);
        $manager->run('busy', static fn (): int => 1);
    }

    public function testRunPreservesCallbackFailureIfBackendReleaseAlsoFails(): void
    {
        $store = new class implements LockStore {
            public function acquire(string $hash, string $token, int $now, int $expiresAt): bool
            {
                return true;
            }
            public function release(string $hash, string $token, int $now): bool
            {
                throw new LockBackendException('release failed');
            }
        };
        $caught = false;
        try {
            $this->manager($store)->run('key', static function (): void {
                throw new \RuntimeException('callback failed');
            });
        } catch (\RuntimeException $failure) {
            $caught = true;
            self::assertSame('callback failed', $failure->getMessage());
        }
        self::assertTrue($caught);
    }

    /** @dataProvider invalidNames */
    public function testInvalidNamesAreRejected(string $name): void
    {
        $this->expectException(LockConfigurationException::class);
        $this->manager()->acquire($name);
    }

    /** @return array<string,array{string}> */
    public static function invalidNames(): array
    {
        return ['empty' => [''], 'path' => ['../lock'], 'backslash' => ['a\\b'],
            'wildcard' => ['a*'], 'control' => ["a\0b"], 'oversize' => [str_repeat('a', 201)]];
    }

    /** @dataProvider invalidDurations */
    public function testInvalidDurationsAreRejected(int $ttl, float $wait): void
    {
        $this->expectException(LockConfigurationException::class);
        $this->manager()->acquire('key', $ttl, $wait);
    }

    /** @return array<string,array{int,float}> */
    public static function invalidDurations(): array
    {
        return ['zero TTL' => [0, 0.0], 'large TTL' => [86401, 0.0],
            'negative wait' => [30, -0.1], 'large wait' => [30, 61.0],
            'infinite wait' => [30, INF]];
    }

    public function testRedisUsesAtomicAcquireAndTokenCheckedLuaRelease(): void
    {
        $client = new LockFakeRedisClient();
        $connection = new RedisConnection(['prefix' => 'phase24:'], static fn (): RedisClient => $client);
        $store = new RedisLockStore($connection);
        $manager = new LockManager($store, 'unit-app', new LockTestClock(), 'redis', true);
        $first = $manager->acquire('redis:key', 7);
        self::assertTrue($first->acquired());
        self::assertFalse($manager->acquire('redis:key', 7)->acquired());
        self::assertSame('SET', $client->commands[0][0]);
        self::assertSame(['EX', '7', 'NX'], array_slice($client->commands[0], 3));
        self::assertStringStartsWith('phase24:locks:', $client->commands[0][1]);
        self::assertStringNotContainsString('redis:key', $client->commands[0][1]);
        $hash = substr($client->commands[0][1], strlen('phase24:locks:'));
        $this->expectException(LockOwnershipException::class);
        try {
            $store->release($hash, str_repeat('0', 64), 1_800_000_000);
        } finally {
            self::assertSame('EVAL', $client->commands[2][0]);
            self::assertStringContainsString("redis.call('GET'", $client->commands[2][1]);
            self::assertStringContainsString("redis.call('DEL'", $client->commands[2][1]);
            self::assertTrue($first->release());
        }
    }

    public function testRedisBackendFailureIsControlledAndNeverAFreeLock(): void
    {
        $client = new class implements RedisClient {
            public function execute(array $arguments): mixed { throw new \RuntimeException('secret-host'); }
            public function close(): void {}
        };
        $connection = new RedisConnection(['prefix' => 'phase24:'], static fn (): RedisClient => $client);
        $manager = new LockManager(new RedisLockStore($connection), 'unit-app',
            new LockTestClock(), 'redis', true);
        try {
            $manager->acquire('key');
            self::fail('Expected backend failure.');
        } catch (LockBackendException $failure) {
            self::assertStringNotContainsString('secret-host', $failure->getMessage());
        }
    }
}
