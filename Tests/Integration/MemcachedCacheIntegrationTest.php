<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Cache\Cache;
use App\Cache\CacheException;
use App\Cache\CacheServiceProvider;
use App\Cache\CacheStore;
use App\Cache\Drivers\MemcachedCacheDriver;
use App\Cache\Drivers\PhpMemcachedClient;
use App\Database\SystemModelClock;
use App\Foundation\Application;
use App\Health\CoreHealthChecks;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class MemcachedCacheIntegrationTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        Cache::setResolver(null);
        $this->project->remove();
    }

    public function testUnusedMemcachedConfigurationDoesNotRequireExtension(): void
    {
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "array", '
            . '"memcached" => ["host" => "invalid host", "port" => -1]];');
        $app = new Application($this->project->path());
        $app->register(CacheServiceProvider::class);
        $app->bootstrap();
        $cache = $app->container()->make(CacheStore::class);
        $cache->store('key', 'works');
        self::assertSame('works', $cache->read('key'));
        self::assertSame('array', $cache->infrastructure()?->selected());
    }

    /** @dataProvider invalidSettings */
    public function testSelectedMemcachedRejectsInvalidSettings(array $settings): void
    {
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "memcached", '
            . '"memcached" => ' . var_export($settings, true) . '];');
        $app = new Application($this->project->path());
        $app->register(CacheServiceProvider::class);
        $this->expectException(\InvalidArgumentException::class);
        $app->bootstrap();
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidSettings(): array
    {
        return [
            'unsafe host' => [['host' => 'host with space']],
            'invalid domain' => [['host' => 'a..b']],
            'bad port' => [['port' => '65536']],
            'bad timeout' => [['timeout_ms' => '0']],
        ];
    }

    public function testSelectedMemcachedRequiresExtensionOnlyWhenSelected(): void
    {
        if (extension_loaded('memcached')) self::markTestSkipped('ext-memcached is available.');
        $this->project->write('Config/Cache.php', '<?php return ["driver" => "memcached"];');
        $app = new Application($this->project->path());
        $app->register(CacheServiceProvider::class);
        $app->bootstrap();
        $check = (new CoreHealthChecks($app))->cache();
        self::assertSame('extension_missing', $check->code());
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('ext-memcached');
        $app->container()->make(CacheStore::class);
    }

    /** A real server and extension are both required, and the opt-in is explicit. */
    public function testGuardedLiveMemcachedContract(): void
    {
        if (getenv('SQUEHUB_TEST_MEMCACHED_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MEMCACHED_ENABLED=1 for live qualification.');
        }
        if (!extension_loaded('memcached')) {
            self::markTestSkipped('Live qualification requires ext-memcached.');
        }
        $host = getenv('SQUEHUB_TEST_MEMCACHED_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('SQUEHUB_TEST_MEMCACHED_PORT') ?: '11211');
        $prefix = getenv('SQUEHUB_TEST_MEMCACHED_PREFIX') ?: 'squehub-live-test';
        $namespace = $prefix . ':' . bin2hex(random_bytes(8));
        $clock = new SystemModelClock();
        $client = new PhpMemcachedClient($host, $port, 1000);
        $first = new CacheStore(new MemcachedCacheDriver($client, $namespace, $clock), $namespace, $clock);
        $secondNamespace = $namespace . ':other';
        $other = new CacheStore(new MemcachedCacheDriver($client, $secondNamespace, $clock),
            $secondNamespace, $clock);

        $first->store('value', ['live' => true], 10);
        $other->store('value', 'isolated', 10);
        self::assertSame(['live' => true], $first->read('value'));
        self::assertSame(['live' => true], $first->remember('value', 10,
            static fn (): array => ['live' => false]));
        self::assertSame(['live' => true], $first->take('value'));
        self::assertFalse($first->has('value'));
        self::assertSame('new', $first->remember('value', 10, static fn (): string => 'new'));
        self::assertTrue($first->remove('value'));
        $first->store('clear-me', true, 10);
        $first->clear();
        self::assertFalse($first->has('clear-me'));
        self::assertSame('isolated', $other->read('value'));
        $other->clear();
    }
}
