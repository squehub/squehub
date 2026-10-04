<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Cache\CacheStore;
use App\Cache\Drivers\MemcachedCacheDriver;
use App\Cache\Drivers\MemcachedClient;
use App\Cache\Drivers\MemcachedRecord;
use App\Database\ModelClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MemcachedCacheTest extends TestCase
{
    private MemcachedTestClock $clock;
    private FakeMemcachedClient $client;

    protected function setUp(): void
    {
        $this->clock = new MemcachedTestClock(1_700_000_000);
        $this->client = new FakeMemcachedClient($this->clock);
    }

    public function testCacheContractAndNamespaceClear(): void
    {
        $first = $this->store('shared');
        $second = $this->store('shared');
        $other = $this->store('other');
        $first->store('private key:😀', ['number' => 42], 60);
        $first->store('cached null', null);
        $other->store('private key:😀', 'other', 60);

        self::assertSame(['number' => 42], $second->read('private key:😀'));
        self::assertTrue($second->has('cached null'));
        self::assertNull($second->read('cached null', 'fallback'));
        self::assertSame('fallback', $second->read('missing', 'fallback'));
        self::assertSame(['number' => 42], $second->take('private key:😀'));
        self::assertFalse($first->has('private key:😀'));
        self::assertSame('fallback', $first->take('private key:😀', 'fallback'));
        self::assertSame('made', $first->remember('once', 60, static fn (): string => 'made'));
        self::assertSame('made', $second->remember('once', 60, static fn (): string => 'wrong'));
        self::assertTrue($second->remove('once'));
        self::assertFalse($first->remove('once'));

        $first->clear();
        self::assertFalse($second->has('cached null'));
        self::assertSame('other', $other->read('private key:😀'));
        self::assertSame(1, $this->client->increments);
        self::assertStringNotContainsString('private key', implode(' ', $this->client->keys()));
        foreach ($this->client->keys() as $key) self::assertLessThan(250, strlen($key));
    }

    public function testTtlBoundaryAndExpiry(): void
    {
        $store = $this->store('ttl');
        $store->store('short', 'short', 10);
        self::assertSame(10, $this->client->lastExpiration);
        $store->store('thirty', 'thirty', 2_592_000);
        self::assertSame(2_592_000, $this->client->lastExpiration);
        $store->store('long', 'long', 2_592_001);
        self::assertSame(1_702_592_001, $this->client->lastExpiration);
        $store->store('sixty days', 'sixty', 5_184_000);
        self::assertSame(1_705_184_000, $this->client->lastExpiration);
        $store->store('forever', 'forever');
        self::assertSame(0, $this->client->lastExpiration);

        $this->clock->second += 10;
        self::assertFalse($store->has('short'));
        self::assertTrue($store->has('thirty'));
        self::assertTrue($store->has('long'));
        self::assertTrue($store->has('sixty days'));
        self::assertTrue($store->has('forever'));
    }

    public function testRememberAndTakeUseAtomicPublication(): void
    {
        $first = $this->store('contended');
        $second = $this->store('contended');
        $this->client->beforeEntryAdd = static function () use ($second): void {
            $second->store('value', 'winner', 60);
        };
        $producerCalls = 0;
        self::assertSame('winner', $first->remember('value', 60, static function () use (&$producerCalls): string {
            $producerCalls++;
            return 'loser';
        }));
        self::assertSame(1, $producerCalls);
        self::assertSame('winner', $second->read('value'));

        $this->client->beforeEntryCas = static function () use ($second): void {
            $second->store('value', 'newer', 60);
        };
        self::assertSame('newer', $first->take('value'));
        self::assertFalse($second->has('value'));
        self::assertSame('replaced', $second->remember('value', 60,
            static fn (): string => 'replaced'));
        self::assertSame('replaced', $first->read('value'));
        self::assertGreaterThanOrEqual(2, $this->client->casAttempts);
    }

    public function testEvictedGenerationCannotReviveOlderEntries(): void
    {
        $store = $this->store('eviction');
        $store->store('old', 'hidden');
        $oldKey = $this->client->entryKeys()[0];
        $this->client->evictGeneration();
        self::assertSame('missing', $store->read('old', 'missing'));
        self::assertContains($oldKey, $this->client->keys());
        $store->store('new', 'visible');
        self::assertSame('visible', $store->read('new'));
        self::assertNotSame($oldKey, $this->client->entryKeys()[1]);
    }

    public function testCorruptPayloadIsMissWithoutObjectInstantiation(): void
    {
        $store = $this->store('corrupt');
        $store->store('entry', 'safe');
        $key = $this->client->entryKeys()[0];
        MemcachedWakeupProbe::$called = false;
        $this->client->set($key, serialize(['version' => 1, 'expires_at' => null,
            'value' => serialize(new MemcachedWakeupProbe())]), 0);
        self::assertSame('missing', $store->read('entry', 'missing'));
        self::assertFalse(MemcachedWakeupProbe::$called);
        $this->client->set($key, 'truncated', 0);
        self::assertFalse($store->has('entry'));
    }

    private function store(string $namespace): CacheStore
    {
        return new CacheStore(new MemcachedCacheDriver($this->client, $namespace, $this->clock),
            $namespace, $this->clock);
    }
}

final class MemcachedTestClock implements ModelClock
{
    public function __construct(public int $second)
    {
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->second))->setTimezone(new DateTimeZone('UTC'));
    }
}

/** Models Memcached expiry and CAS without an extension or running server. */
final class FakeMemcachedClient implements MemcachedClient
{
    /** @var array<string, array{value:string|int,expires:?int,token:int}> */
    private array $items = [];
    private int $nextToken = 1;
    public int $lastExpiration = -1;
    public int $increments = 0;
    public int $casAttempts = 0;
    public mixed $beforeEntryAdd = null;
    public mixed $beforeEntryCas = null;

    public function __construct(private MemcachedTestClock $clock)
    {
    }

    public function read(string $key): ?MemcachedRecord
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) return null;
        if ($item['expires'] !== null && $this->clock->second >= $item['expires']) {
            unset($this->items[$key]);
            return null;
        }
        return new MemcachedRecord($item['value'], $item['token']);
    }

    public function set(string $key, string|int $value, int $expiration): void
    {
        $this->lastExpiration = $expiration;
        $this->items[$key] = ['value' => $value, 'expires' => $this->expiry($expiration),
            'token' => $this->nextToken++];
    }

    public function add(string $key, string|int $value, int $expiration): bool
    {
        if (str_ends_with($key, ':generation') === false && is_callable($this->beforeEntryAdd)) {
            $hook = $this->beforeEntryAdd;
            $this->beforeEntryAdd = null;
            $hook();
        }
        if ($this->read($key) !== null) return false;
        $this->set($key, $value, $expiration);
        return true;
    }

    public function cas(string|int|float $token, string $key, string|int $value, int $expiration): bool
    {
        $this->casAttempts++;
        if (str_ends_with($key, ':generation') === false && is_callable($this->beforeEntryCas)) {
            $hook = $this->beforeEntryCas;
            $this->beforeEntryCas = null;
            $hook();
        }
        $current = $this->read($key);
        if ($current === null || $current->token !== $token) return false;
        $this->set($key, $value, $expiration);
        return true;
    }

    public function increment(string $key): ?int
    {
        $current = $this->read($key);
        if ($current === null || !is_int($current->value)) return null;
        $this->increments++;
        $next = $current->value + 1;
        $this->set($key, $next, 0);
        return $next;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /** @return list<string> */
    public function entryKeys(): array
    {
        return array_values(array_filter($this->keys(),
            static fn (string $key): bool => !str_ends_with($key, ':generation')));
    }

    public function evictGeneration(): void
    {
        foreach ($this->keys() as $key) {
            if (str_ends_with($key, ':generation')) unset($this->items[$key]);
        }
    }

    private function expiry(int $expiration): ?int
    {
        if ($expiration === 0) return null;
        return $expiration <= 2_592_000 ? $this->clock->second + $expiration : $expiration;
    }
}

final class MemcachedWakeupProbe
{
    public static bool $called = false;

    public function __wakeup(): void
    {
        self::$called = true;
    }
}
