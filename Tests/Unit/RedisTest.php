<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Redis\RedisClient;
use App\Redis\RedisConfigurationException;
use App\Redis\RedisException;
use App\Redis\RedisManager;
use App\Redis\Redis as RedisGateway;
use App\Plugins\Redis as PluginRedis;
use App\Support\SecretRedactor;
use App\Support\SensitiveKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A deterministic command double tests framework policy without a Redis server. */
final class MemoryRedisClient implements RedisClient
{
    /** @var array<string,string> */
    public array $data = [];
    /** @var array<string,int> */
    public array $expiry = [];
    /** @var list<list<string>> */
    public array $commands = [];
    public bool $fail = false;

    public function execute(array $arguments): mixed
    {
        $this->commands[] = $arguments;
        if ($this->fail) throw new RuntimeException('SQUEHUB_REDIS_SECRET_DO_NOT_LEAK');
        [$command] = $arguments;
        $key = $arguments[1] ?? '';
        return match ($command) {
            'PING' => 'PONG',
            'GET' => $this->data[$key] ?? null,
            'SET' => $this->set($arguments),
            'DEL' => $this->delete($key),
            'EXISTS' => isset($this->data[$key]) ? 1 : 0,
            'EXPIRE' => $this->expire($key, (int) $arguments[2]),
            'TTL' => isset($this->data[$key]) ? ($this->expiry[$key] ?? -1) : -2,
            'INCR' => $this->integer($key, 1),
            'DECR' => $this->integer($key, -1),
            default => throw new RuntimeException('Unexpected test command.'),
        };
    }

    public function close(): void {}

    private function set(array $arguments): ?string
    {
        $key = $arguments[1];
        if (in_array('NX', $arguments, true) && isset($this->data[$key])) return null;
        $this->data[$key] = $arguments[2];
        if (($position = array_search('EX', $arguments, true)) !== false) {
            $this->expiry[$key] = (int) $arguments[$position + 1];
        }
        return 'OK';
    }

    private function delete(string $key): int
    {
        $existed = isset($this->data[$key]);
        unset($this->data[$key], $this->expiry[$key]);
        return $existed ? 1 : 0;
    }

    private function expire(string $key, int $seconds): int
    {
        if (!isset($this->data[$key])) return 0;
        $this->expiry[$key] = $seconds;
        return 1;
    }

    private function integer(string $key, int $step): int
    {
        $next = (int) ($this->data[$key] ?? '0') + $step;
        $this->data[$key] = (string) $next;
        return $next;
    }
}

/** Redis policy, privacy, and lazy lifecycle tests independent of optional clients. */
final class RedisTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function manager(array $overrides = [], ?MemoryRedisClient $client = null,
        ?Diagnostics $diagnostics = null): RedisManager
    {
        $client ??= new MemoryRedisClient();
        return new RedisManager(array_replace_recursive([
            'default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost', 'prefix' => 'app:']],
        ], $overrides), $diagnostics,
            static fn (): RedisClient => $client,
            static fn (string $class): bool => $class === \Redis::class);
    }

    public function testOperationsAreLazyAndConnectionIsReused(): void
    {
        $client = new MemoryRedisClient();
        $created = 0;
        $manager = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost', 'prefix' => 'app:']]],
            null, static function () use ($client, &$created): RedisClient {
                ++$created;
                return $client;
            }, static fn (string $class): bool => $class === \Redis::class);
        self::assertSame($manager->connection(), $manager->connection('main'));
        self::assertSame(['status' => 'not_probed', 'client' => 'phpredis'], $manager->capability());
        self::assertSame(0, $created);
        self::assertNull($manager->connection()->get('missing'));
        self::assertSame(1, $created);
        self::assertSame('app:missing', $client->commands[0][1]);
        self::assertTrue($manager->available());
        self::assertSame(1, $created);
    }

    public function testStringBinaryConditionalSetExpiryAndAtomicIntegerCommands(): void
    {
        $client = new MemoryRedisClient();
        $connection = $this->manager([], $client)->connection();
        foreach (['', 'hello', 'Grüße', "\0\xff\x01"] as $value) {
            self::assertTrue($connection->set('binary', $value));
            self::assertSame($value, $connection->get('binary'));
        }
        self::assertFalse($connection->set('binary', 'replaced', ttl: 30, onlyIfMissing: true));
        self::assertTrue($connection->set('new', 'value', ttl: 30, onlyIfMissing: true));
        self::assertSame(['SET', 'app:new', 'value', 'EX', '30', 'NX'], end($client->commands));
        self::assertSame(30, $connection->ttl('new'));
        self::assertTrue($connection->expire('new', 15));
        self::assertSame(15, $connection->ttl('new'));
        self::assertSame(-2, $connection->ttl('absent'));
        self::assertSame(1, $connection->increment('counter'));
        self::assertSame(2, $connection->increment('counter'));
        self::assertSame(1, $connection->decrement('counter'));
        self::assertTrue($connection->exists('new'));
        self::assertTrue($connection->delete('new'));
        self::assertFalse($connection->delete('new'));
        self::assertFalse($connection->exists('new'));
        self::assertSame('INCR', $client->commands[14][0]);
    }

    public function testNamedConnectionsAndInstancesAreIsolated(): void
    {
        $first = $this->manager(['connections' => [
            'main' => ['host' => 'localhost', 'prefix' => 'first:'],
            'cache' => ['host' => 'localhost', 'prefix' => 'cache:'],
        ]]);
        $second = $this->manager();
        self::assertNotSame($first->connection(), $first->connection('cache'));
        self::assertNotSame($first->connection(), $second->connection());
        self::assertTrue($first->connection('cache')->set('key', 'one'));
        self::assertSame('one', $first->connection('cache')->get('key'));
        self::assertNull($second->connection()->get('key'));
        $this->expectException(RedisConfigurationException::class);
        $first->connection('unknown');
    }

    public function testUnavailableAndUnconfiguredStatesDoNotConnect(): void
    {
        $unconfigured = new RedisManager(['default' => 'main', 'connections' => ['main' => []]]);
        self::assertSame('not_configured', $unconfigured->capability(probe: true)['status']);
        self::assertFalse($unconfigured->available());
        $this->expectException(RedisConfigurationException::class);
        $unconfigured->connection()->get('key');
    }

    public function testClientSelectionRespectsExplicitChoiceAndAutoOrder(): void
    {
        $available = static fn (string $class): bool => $class === 'Predis\\Client';
        $config = ['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost']]];
        $auto = new RedisManager($config, null, null, $available);
        self::assertSame('predis', $auto->capability()['client']);
        $config['client'] = 'phpredis';
        $explicit = new RedisManager($config, null, null, $available);
        self::assertSame('client_unavailable', $explicit->capability()['status']);
        $this->expectException(RedisConfigurationException::class);
        $explicit->connection()->get('key');
    }

    public function testAutoSelectionDoesNotChangeDuringOneManagerLifetime(): void
    {
        $phpRedisAvailable = true;
        $manager = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost']]], null, null,
            static function (string $class) use (&$phpRedisAvailable): bool {
                return $class === \Redis::class ? $phpRedisAvailable : true;
            });
        self::assertSame('phpredis', $manager->capability()['client']);
        $phpRedisAvailable = false;
        self::assertSame('phpredis', $manager->capability()['client']);

        $missing = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost']]], null, null,
            static fn (): bool => false);
        self::assertSame('client_unavailable', $missing->capability()['status']);
    }

    public function testPluginsGatewayUsesTheSameManagerAndNamedCommands(): void
    {
        $manager = $this->manager(['connections' => [
            'main' => ['host' => 'localhost', 'prefix' => 'main:'],
            'cache' => ['host' => 'localhost', 'prefix' => 'cache:'],
        ]]);
        RedisGateway::setResolver(static fn (): RedisManager => $manager);
        try {
            self::assertSame($manager, PluginRedis::manager());
            self::assertTrue(PluginRedis::set('name', 'SqueHub'));
            self::assertSame('SqueHub', PluginRedis::get('name'));
            self::assertTrue(PluginRedis::connection('cache')->set('name', 'cache'));
            self::assertSame('cache', PluginRedis::connection('cache')->get('name'));
            self::assertSame('SqueHub', PluginRedis::get('name'));
        } finally {
            RedisGateway::setResolver(null);
        }
    }

    public function testUnreachableProbeIsExplicitAndSafe(): void
    {
        $attempts = 0;
        $manager = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['host' => 'localhost']]], null,
            static function () use (&$attempts): RedisClient {
                ++$attempts;
                throw new RuntimeException('SQUEHUB_REDIS_SECRET_DO_NOT_LEAK');
            }, static fn (string $class): bool => $class === \Redis::class);
        self::assertSame(0, $attempts);
        self::assertSame('not_probed', $manager->capability()['status']);
        self::assertSame(0, $attempts);
        self::assertSame('unreachable', $manager->capability(probe: true)['status']);
        self::assertSame(1, $attempts);
        self::assertStringNotContainsString('SQUEHUB_REDIS_SECRET_DO_NOT_LEAK', print_r($manager, true));
    }

    public function testDiagnosticsResetAndFailuresContainNoSecrets(): void
    {
        $diagnostics = new Diagnostics(new Repository());
        $diagnostics->begin(new Request());
        $client = new MemoryRedisClient();
        $manager = $this->manager(['connections' => ['main' => ['host' => 'localhost',
            'prefix' => 'private:', 'password' => 'SQUEHUB_REDIS_SECRET_DO_NOT_LEAK']]],
            $client, $diagnostics);
        $connection = $manager->connection();
        $connection->set('private-key', 'private-value');
        $connection->get('private-key');
        $client->fail = true;
        try {
            $connection->get('private-key');
            self::fail('The fake backend should fail.');
        } catch (RedisException $exception) {
            self::assertSame('Redis operation failed.', $exception->getMessage());
        }
        self::assertSame(['operations' => 3, 'reads' => 2, 'writes' => 1,
            'failures' => 1], array_diff_key($diagnostics->snapshot()['redis'], ['time_ms' => true]));
        $dump = print_r($diagnostics->snapshot(), true) . print_r($manager, true)
            . print_r($connection, true);
        foreach (['private-key', 'private-value', 'private:', 'SQUEHUB_REDIS_SECRET_DO_NOT_LEAK'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        $diagnostics->begin(new Request());
        self::assertSame(0, $diagnostics->snapshot()['redis']['operations']);
    }

    public function testInvalidConfigurationFailsWithoutEchoingValues(): void
    {
        $invalid = [
            ['port' => 0], ['port' => 65536], ['database' => -1],
            ['connect_timeout' => 0], ['read_timeout' => 'never'],
            ['url' => 'http://secret:secret@example.test:6379/0'],
            ['url' => 'redis://secret:secret@example.test:bad/0'],
            ['url' => 'rediss://example.test/abc'],
            ['url' => 'redis://example.test/0', 'host' => 'other.test'],
            ['host' => 'bad host'], ['username' => 'someone'],
            ['tls' => 'maybe'], ['prefix' => 'unsafe key'],
        ];
        foreach ($invalid as $connection) {
            try {
                $this->manager(['connections' => ['main' => array_merge(['host' => 'localhost'], $connection)]]);
                self::fail('Invalid Redis configuration was accepted.');
            } catch (RedisConfigurationException $exception) {
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    public function testBlankOptionalConnectionValuesUseDefaults(): void
    {
        $manager = $this->manager(['client' => '', 'connections' => ['main' => [
            'host' => 'localhost', 'client' => '', 'port' => '', 'database' => '',
            'connect_timeout' => '', 'read_timeout' => '', 'tls' => '',
        ]]]);
        self::assertSame('not_probed', $manager->capability()['status']);
        self::assertTrue($manager->connection()->ping());
    }

    public function testUrlSetsTlsAuthAndDatabaseWithoutPublishingCredentials(): void
    {
        $seen = null;
        $manager = new RedisManager(['default' => 'main', 'client' => 'auto',
            'connections' => ['main' => ['url' => 'rediss://alice:password@example.test:6380/3',
                'prefix' => 'site:']]], null,
            static function (string $client, array $settings) use (&$seen): RedisClient {
                $seen = $settings;
                return new MemoryRedisClient();
            }, static fn (string $class): bool => $class === \Redis::class);
        self::assertSame('not_probed', $manager->capability()['status']);
        self::assertTrue($manager->connection()->ping());
        self::assertSame('example.test', $seen['host']);
        self::assertSame(6380, $seen['port']);
        self::assertSame(3, $seen['database']);
        self::assertTrue($seen['tls']);
        self::assertSame('alice', $seen['username']);
        self::assertSame('password', $seen['password']);
        self::assertStringNotContainsString('password', print_r($manager, true));
        self::assertSame('not_probed', $this->manager(['connections' => [
            'main' => ['host' => 'localhost', 'tls' => 'yes'],
        ]])->capability()['status']);
    }

    public function testInputValidationRejectsInvalidKeysAndExpiry(): void
    {
        $connection = $this->manager()->connection();
        foreach (['', "bad\0key", str_repeat('a', 1025)] as $key) {
            try {
                $connection->get($key);
                self::fail('Invalid key was accepted.');
            } catch (RedisConfigurationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(RedisConfigurationException::class);
        $connection->set('key', 'value', ttl: 0);
    }

    public function testSharedRedactorCoversNamedRedisCredentialsAndUrls(): void
    {
        $url = 'rediss://alice:encoded%21secret@example.test:6380/0';
        $config = new Repository(['redis' => ['connections' => [
            'main' => ['url' => $url],
            'other' => ['username' => 'operator', 'password' => 'separate-secret'],
        ]]]);
        $redactor = new SecretRedactor($config);
        $redacted = $redactor->redact($url . ' encoded!secret operator separate-secret');
        foreach ([$url, 'encoded!secret', 'operator', 'separate-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $redacted);
        }
        self::assertTrue(SensitiveKey::matches('REDIS_URL'));
    }
}
