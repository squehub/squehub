<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Cache\CacheStore;
use App\Cache\Drivers\RedisCacheDriver;
use App\Database\ModelClock;
use App\RateLimit\RateLimiter;
use App\RateLimit\Stores\RedisRateLimitStore;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisClient;
use App\Redis\RedisManager;
use App\Session\Drivers\RedisSessionHandler;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exercises shared Redis command contracts without an optional client/server. */
final class RedisInfrastructureTest extends TestCase
{
    private function manager(InfraRedisClient $client, string $prefix = 'app:'): RedisManager
    {
        return new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost', 'prefix' => $prefix]]], null,
            static fn (): RedisClient => $client,
            static fn (string $class): bool => $class === \Redis::class);
    }

    public function testCacheUsesNativeExpiryAndScopedClear(): void
    {
        $client = new InfraRedisClient();
        $manager = $this->manager($client);
        $clock = new InfraClock($client);
        $cache = new CacheStore(new RedisCacheDriver($manager->connection(), 'A', $clock), 'A', $clock);
        $other = new CacheStore(new RedisCacheDriver($manager->connection(), 'B', $clock), 'B', $clock);
        self::assertSame('miss', $cache->read('missing', 'miss'));
        $cache->store('null', null);
        self::assertTrue($cache->has('null'));
        $cache->store('binary', ["\0\xff", INF], 5);
        self::assertSame(["\0\xff", INF], $cache->read('binary'));
        self::assertSame(1005, max($client->expiry));
        $other->store('keep', 'other');
        $manager->connection()->set('session:fixture', 'private');
        $manager->connection()->set('rate_limit:fixture', 'counter');
        self::assertSame(["\0\xff", INF], $cache->take('binary'));
        self::assertFalse($cache->has('binary'));
        $cache->store('expires', 'now', 2);
        $cache->store('expires', 'renewed', 4);
        $client->now += 2;
        self::assertSame('renewed', $cache->read('expires'));
        $client->now += 2;
        self::assertFalse($cache->has('expires'));
        $calls = 0;
        self::assertSame('created', $cache->remember('computed', null,
            static function () use (&$calls): string { ++$calls; return 'created'; }));
        self::assertSame('created', $cache->remember('computed', null,
            static function () use (&$calls): string { ++$calls; return 'changed'; }));
        self::assertSame(1, $calls);
        $cache->clear();
        self::assertSame('other', $other->read('keep'));
        self::assertSame('private', $manager->connection()->get('session:fixture'));
        self::assertSame('counter', $manager->connection()->get('rate_limit:fixture'));
        self::assertFalse($cache->has('null'));
        self::assertNotContains('KEYS', $client->commands);
        self::assertNotContains('FLUSHDB', $client->commands);
    }

    public function testRedisCacheRejectsOversizedPayloadBeforePersistence(): void
    {
        $client = new InfraRedisClient();
        $clock = new InfraClock($client);
        $cache = new CacheStore(new RedisCacheDriver($this->manager($client)->connection(), 'app', $clock),
            'app', $clock);
        try {
            $cache->store('large', str_repeat('x', 8 * 1024 * 1024));
            self::fail('A Redis Cache record above the size bound must be rejected.');
        } catch (\App\Cache\CacheException) {
            self::assertFalse($cache->has('large'));
        }
    }

    public function testRateLimitUsesOneAtomicScriptPerDecision(): void
    {
        $client = new InfraRedisClient();
        $manager = $this->manager($client);
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('@' . $client->now);
        $first = new RateLimiter(new RedisRateLimitStore($manager->connection(), 'app'), 'app', clock: $clock);
        $second = new RateLimiter(new RedisRateLimitStore($manager->connection(), 'app'), 'app', clock: $clock);
        self::assertSame(1, $first->consume('login', 'private@example.test', 2, 60)->remaining());
        self::assertSame(0, $second->consume('login', 'private@example.test', 2, 60)->remaining());
        $client->now += 10;
        $denied = $first->consume('login', 'private@example.test', 2, 60);
        self::assertTrue($denied->denied());
        self::assertSame(50, $denied->retryAfter());
        self::assertSame(1060, $denied->resetsAt()->getTimestamp());
        self::assertTrue($second->consume('reset', 'private@example.test', 1, 60)->allowed());
        self::assertTrue($first->clear('login', 'private@example.test'));
        self::assertTrue($second->consume('login', 'private@example.test', 2, 60)->allowed());
        $client->now = 1070;
        self::assertTrue($first->consume('login', 'private@example.test', 2, 60)->allowed());
        self::assertStringNotContainsString('private@example.test', implode('', array_keys($client->data)));
    }

    public function testRedisRateLimitPolicyChangeExactExpiryAndCorruptionFailure(): void
    {
        $client = new InfraRedisClient();
        $store = new RedisRateLimitStore($this->manager($client)->connection(), 'app');
        $fingerprint = str_repeat('a', 64);
        self::assertTrue($store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1000'))->allowed());
        self::assertTrue($store->consume($fingerprint, 1, 60, new DateTimeImmutable('@1000'))->denied());
        $changed = $store->consume($fingerprint, 2, 120, new DateTimeImmutable('@1010'));
        self::assertTrue($changed->allowed());
        self::assertSame(1130, $changed->resetsAt()->getTimestamp());
        $client->now = 1130;
        self::assertTrue($store->consume($fingerprint, 2, 120,
            new DateTimeImmutable('@1130'))->allowed());
        $key = array_key_first($client->data);
        $client->data[$key] = '{broken';
        $this->expectException(\App\RateLimit\RateLimitException::class);
        $store->consume($fingerprint, 2, 120, new DateTimeImmutable('@1131'));
    }

    public function testSessionHandlerPreservesPayloadAndTouchesOnlyItsIdentity(): void
    {
        $client = new InfraRedisClient();
        $connection = $this->manager($client)->connection();
        $session = new RedisSessionHandler($connection, 'app', 120);
        self::assertSame('', $session->read('abc123'));
        self::assertTrue($session->write('abc123', 'theme|s:4:"dark";'));
        self::assertTrue($session->validateId('abc123'));
        self::assertSame('theme|s:4:"dark";', $session->read('abc123'));
        $sessionKeys = array_keys(array_filter($client->data,
            static fn (string $key): bool => str_starts_with($key, 'app:session:'), ARRAY_FILTER_USE_KEY));
        self::assertCount(1, $sessionKeys);
        self::assertSame(1120, $client->expiry[$sessionKeys[0]]);
        self::assertTrue($session->updateTimestamp('abc123', 'theme|s:4:"dark";'));
        $connection->set('cache:fixture', 'keep');
        self::assertTrue($session->destroy('abc123'));
        self::assertFalse($session->validateId('abc123'));
        self::assertSame('keep', $connection->get('cache:fixture'));
        self::assertTrue($session->write('expires123', 'temporary'));
        $client->now += 120;
        self::assertSame('', $session->read('expires123'));
        $session->close();
        self::assertStringNotContainsString('abc123', implode('', array_keys($client->data)));
    }

    public function testSessionLeaseSerializesRequestsAndReleasesOnClose(): void
    {
        $client = new InfraRedisClient();
        $connection = $this->manager($client)->connection();
        $first = new RedisSessionHandler($connection, 'app', 120, lockWaitMilliseconds: 1);
        $second = new RedisSessionHandler($connection, 'app', 120, lockWaitMilliseconds: 1);
        self::assertSame('', $first->read('session-one'));
        try {
            $second->read('session-one');
            self::fail('A concurrent reader must not bypass the lease.');
        } catch (\App\Session\SessionException) {
            self::assertTrue(true);
        }
        $first->close();
        self::assertSame('', $second->read('session-one'));
        $second->close();
        self::assertSame([], $client->data);
    }

    public function testAutoSelectionIsLazyAndFixed(): void
    {
        $client = new InfraRedisClient();
        $manager = $this->manager($client);
        $selection = new InfrastructureSelection('auto', 'file', $manager);
        self::assertNull($selection->selected());
        self::assertSame([], $client->commands);
        self::assertSame('redis', $selection->resolve());
        $client->fail = true;
        self::assertSame('redis', $selection->resolve());
        self::assertSame('file', (new InfrastructureSelection('auto', 'file', $manager))->resolve());
        $unconfigured = new RedisManager(['default' => 'main', 'connections' => ['main' => []]]);
        self::assertSame('native', (new InfrastructureSelection('auto', 'native', $unconfigured))->resolve());
        $noClient = new RedisManager(['default' => 'main', 'connections' => [
            'main' => ['host' => 'localhost']]], null, null, static fn (): bool => false);
        self::assertSame('file', (new InfrastructureSelection('auto', 'file', $noClient))->resolve());
        self::assertSame('redis', (new InfrastructureSelection('redis', 'file', null))->resolve());
    }

    public function testExplicitRedisBackendFailuresStayInSubsystemExceptionDomains(): void
    {
        $client = new InfraRedisClient();
        $connection = $this->manager($client)->connection();
        $rate = new RedisRateLimitStore($connection, 'app');
        $session = new RedisSessionHandler($connection, 'app', 120);
        $client->fail = true;
        try {
            $rate->consume(str_repeat('a', 64), 1, 60, new DateTimeImmutable('@1000'));
            self::fail('Rate limiting must not fail open.');
        } catch (\App\RateLimit\RateLimitException $failure) {
            self::assertStringNotContainsString('fake unavailable', $failure->getMessage());
        }
        try {
            $session->read('private123');
            self::fail('Session must not fall back after Redis fails.');
        } catch (\App\Session\SessionException $failure) {
            self::assertStringNotContainsString('private123', $failure->getMessage());
        }
    }
}

/** A controlled clock shared by Redis's fake TTL and CacheStore expiry. */
final class InfraClock implements ModelClock
{
    public function __construct(private InfraRedisClient $client) {}
    public function now(): DateTimeImmutable { return new DateTimeImmutable('@' . $this->client->now); }
}

/** Minimal command double; EVAL emulates the fixed script contracts, not Redis itself. */
final class InfraRedisClient implements RedisClient
{
    public int $now = 1000;
    public bool $fail = false;
    /** @var array<string,string> */
    public array $data = [];
    /** @var array<string,int> Absolute fake expiry. */
    public array $expiry = [];
    /** @var list<string> */
    public array $commands = [];

    public function execute(array $arguments): mixed
    {
        $command = $arguments[0];
        $this->commands[] = $command;
        if ($this->fail) throw new RuntimeException('fake unavailable');
        $key = $arguments[1] ?? '';
        if (isset($this->expiry[$key]) && $this->now >= $this->expiry[$key]) {
            unset($this->data[$key], $this->expiry[$key]);
        }
        return match ($command) {
            'PING' => 'PONG',
            'GET' => $this->data[$key] ?? null,
            'EXISTS' => isset($this->data[$key]) ? 1 : 0,
            'DEL' => $this->delete($key),
            'SET' => $this->set($arguments),
            'EVAL' => $this->evaluate($arguments),
            'SCAN' => ['0', array_values(array_filter(array_keys($this->data),
                static fn (string $item): bool => str_starts_with($item, substr($arguments[3], 0, -1))))],
            default => throw new RuntimeException('Unsupported fake command.'),
        };
    }

    public function close(): void {}

    private function delete(string $key): int
    {
        $exists = isset($this->data[$key]);
        unset($this->data[$key], $this->expiry[$key]);
        return $exists ? 1 : 0;
    }

    private function set(array $arguments): ?string
    {
        $key = $arguments[1];
        if (in_array('NX', $arguments, true) && isset($this->data[$key])) return null;
        $this->data[$key] = $arguments[2];
        $position = array_search('EX', $arguments, true);
        if ($position !== false) $this->expiry[$key] = $this->now + (int) $arguments[$position + 1];
        else unset($this->expiry[$key]);
        return 'OK';
    }

    private function evaluate(array $arguments): mixed
    {
        $key = $arguments[3];
        if (!str_contains($arguments[1], 'cjson.encode') && str_contains($arguments[1], 'ARGV[1]')) {
            if (($this->data[$key] ?? null) !== ($arguments[4] ?? null)) return 0;
            return $this->delete($key);
        }
        if (!str_contains($arguments[1], 'cjson.encode')) {
            $old = $this->data[$key] ?? null;
            $this->delete($key);
            return $old;
        }
        $limit = (int) $arguments[4];
        $seconds = (int) $arguments[5];
        $now = (int) $arguments[6];
        $old = isset($this->data[$key])
            ? json_decode($this->data[$key], true, 512, JSON_THROW_ON_ERROR) : null;
        $reset = $now + $seconds;
        $count = 0;
        if (is_array($old) && $now < $old['resets_at'] && $limit === $old['limit']
            && $seconds === $old['window_seconds']) {
            $reset = $old['resets_at'];
            $count = $old['count'];
        }
        $count = min($limit + 1, $count + 1);
        $this->data[$key] = json_encode(['version' => 1, 'count' => $count,
            'limit' => $limit, 'window_seconds' => $seconds, 'resets_at' => $reset]);
        $this->expiry[$key] = $reset;
        return [$count, $reset];
    }
}
