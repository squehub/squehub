<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Cache\CacheEntry;
use App\Cache\CacheException;
use App\Cache\CacheStore;
use App\Cache\Drivers\ArrayCacheDriver;
use App\Cache\Drivers\FileCacheDriver;
use App\Database\ModelClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Verifies the public store contract against both local driver behaviors. */
final class CacheTest extends TestCase
{
    private string $root;
    private CacheTestClock $clock;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/squehub-cache-' . bin2hex(random_bytes(6));
        $this->clock = new CacheTestClock(1000);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $remove = static function (string $path) use (&$remove): void {
            if (is_link($path)) {
                // Windows can represent a directory link as a directory for
                // unlink(); rmdir() removes only the link, never its target.
                if (!@unlink($path) && !@rmdir($path)) {
                    throw new RuntimeException('Cache test link could not be removed.');
                }
            } elseif (is_dir($path)) {
                foreach (new \DirectoryIterator($path) as $item) {
                    if (!$item->isDot()) $remove($item->getPathname());
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->root);
    }

    /** @dataProvider driverNames */
    public function testBasicOperationsAndCachedNull(string $driver): void
    {
        $cache = $this->store($driver);
        self::assertSame('fallback', $cache->read('missing', 'fallback'));
        $called = false;
        $default = static function () use (&$called): string { $called = true; return 'computed'; };
        self::assertSame($default, $cache->read('missing', $default));
        self::assertFalse($called);
        $cache->store('null.value', null);
        self::assertTrue($cache->has('null.value'));
        self::assertNull($cache->read('null.value', 'fallback'));
        self::assertNull($cache->take('null.value', 'fallback'));
        self::assertFalse($cache->has('null.value'));
        self::assertFalse($cache->remove('null.value'));
        $cache->store('binary', "\0\xff", 10);
        self::assertSame("\0\xff", $cache->read('binary'));
        self::assertTrue($cache->remove('binary'));
        self::assertFalse($cache->remove('binary'));
        $cache->store('lasting', ['active' => true]);
        $this->clock->second = 900000;
        self::assertSame(['active' => true], $cache->read('lasting'));
        $cache->clear();
        self::assertFalse($cache->has('lasting'));
    }

    /** @dataProvider driverNames */
    public function testTtlExpiresAtExactBoundaryAndExpiredRemovalIsFalse(string $driver): void
    {
        $cache = $this->store($driver);
        $cache->store('item', 7, 10);
        $this->clock->second = 1009;
        self::assertSame(7, $cache->read('item'));
        $this->clock->second = 1010;
        self::assertSame('gone', $cache->read('item', 'gone'));
        self::assertFalse($cache->remove('item'));
        $calls = 0;
        self::assertSame(1, $cache->remember('remembered', 2,
            static function () use (&$calls): int { return ++$calls; }));
        $this->clock->second = 1012;
        self::assertSame(2, $cache->remember('remembered', 2,
            static function () use (&$calls): int { return ++$calls; }));
    }

    /** @dataProvider driverNames */
    public function testRememberCachesNullAndReleasesAfterResolverException(string $driver): void
    {
        $cache = $this->store($driver);
        $calls = 0;
        self::assertNull($cache->remember('key', 10, static function () use (&$calls): mixed {
            ++$calls;
            return null;
        }));
        self::assertNull($cache->remember('key', 10, static function () use (&$calls): mixed {
            ++$calls;
            return 'wrong';
        }));
        self::assertSame(1, $calls);
        try {
            $cache->remember('throws', null, static function (): never {
                throw new RuntimeException('application problem');
            });
            self::fail('Resolver exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('application problem', $exception->getMessage());
        }
        self::assertSame('recovered', $cache->remember('throws', null, static fn (): string => 'recovered'));
    }

    /** @dataProvider driverNames */
    public function testSupportedValuesAreCopiedAndUnsupportedValuesFail(string $driver): void
    {
        $cache = $this->store($driver);
        $value = ['nested' => ['count' => 1], 'float' => INF];
        $cache->store('copy', $value);
        $value['nested']['count'] = 2;
        $read = $cache->read('copy');
        $read['nested']['count'] = 3;
        self::assertSame(1, $cache->read('copy')['nested']['count']);
        self::assertTrue(is_infinite($cache->read('copy')['float']));
        foreach ([new \stdClass(), new DateTimeImmutable(), fopen('php://memory', 'r+'),
            ['nested' => new \stdClass()]] as $bad) {
            try {
                $cache->store('invalid', $bad);
                self::fail('Unsupported value should fail.');
            } catch (CacheException) {
                self::assertFalse($cache->has('invalid'));
            } finally {
                if (is_resource($bad)) fclose($bad);
            }
        }
        $recursive = [];
        $recursive['self'] = &$recursive;
        $this->expectException(CacheException::class);
        $cache->store('recursive', $recursive);
    }

    public function testInputValidationDoesNotExposeKeyInError(): void
    {
        $cache = $this->store('array');
        foreach (['', "secret\0token", "bad\nkey", str_repeat('x', 513)] as $key) {
            try {
                $cache->read($key);
                self::fail('Invalid key should fail.');
            } catch (InvalidArgumentException $exception) {
                if ($key !== '') self::assertStringNotContainsString($key, $exception->getMessage());
            }
        }
        foreach ([0, -1] as $ttl) {
            try {
                $cache->store('valid', 1, $ttl);
                self::fail('Invalid TTL should fail.');
            } catch (InvalidArgumentException) {
                self::assertFalse($cache->has('valid'));
            }
        }
        $this->expectException(\TypeError::class);
        $cache->store('valid', 1, '10');
    }

    public function testFilePersistenceNamespacingAndClearContainment(): void
    {
        $first = $this->store('file', 'app-one');
        $second = $this->store('file', 'app-one');
        $other = $this->store('file', 'app-two');
        $first->store('../../private:😀', ['answer' => 42]);
        self::assertSame(['answer' => 42], $second->read('../../private:😀'));
        self::assertFalse($other->has('../../private:😀'));
        $other->store('other', true);
        file_put_contents($this->root . '/compiled-view.php', '<?php echo 1;');
        $first->clear();
        self::assertFalse($second->has('../../private:😀'));
        self::assertTrue($other->has('other'));
        self::assertFileExists($this->root . '/compiled-view.php');
        $files = glob($this->root . '/*/*.cache');
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('~[/\\\\][a-f0-9]{64}\.cache\z~', $files[0]);
    }

    public function testCorruptionAndObjectPayloadBecomeMissesWithoutInstantiation(): void
    {
        $cache = $this->store('file');
        $cache->store('record', 'safe');
        $path = glob($this->root . '/*/*.cache')[0];
        file_put_contents($path, 'truncated');
        self::assertSame('fallback', $cache->read('record', 'fallback'));
        self::assertFileDoesNotExist($path);
        $cache->store('record', 'safe');
        CacheWakeupProbe::$called = false;
        file_put_contents($path, serialize(['version' => 1, 'expires_at' => null,
            'payload' => serialize(new CacheWakeupProbe())]));
        self::assertFalse($cache->has('record'));
        self::assertFalse(CacheWakeupProbe::$called);
        self::assertFileDoesNotExist($path);
        $cache->store('record', 'safe');
        file_put_contents($path, serialize(['version' => 2, 'expires_at' => null,
            'payload' => serialize('stale')]));
        self::assertSame('fallback', $cache->read('record', 'fallback'));
    }

    public function testFileDriverRejectsNamespaceSymlink(): void
    {
        if (!function_exists('symlink')) self::markTestSkipped('Symlinks unavailable.');
        $driver = new FileCacheDriver($this->root, 'namespace');
        mkdir($this->root);
        $outside = sys_get_temp_dir();
        $namespace = $this->root . '/' . hash('sha256', 'namespace');
        if (!@symlink($outside, $namespace)) self::markTestSkipped('Symlink creation unavailable.');
        $this->expectException(CacheException::class);
        $driver->clear();
    }

    public function testFileBackendFailureIsNotReportedAsAMiss(): void
    {
        file_put_contents($this->root, 'not a directory');
        $cache = $this->store('file');
        try {
            $cache->read('private:key', 'fallback');
            self::fail('A regular file cannot serve as a cache root.');
        } catch (CacheException $exception) {
            self::assertStringNotContainsString('private:key', $exception->getMessage());
        } finally {
            unlink($this->root);
        }
    }

    /** @return array<string, array{string}> */
    public static function driverNames(): array
    {
        return ['array' => ['array'], 'file' => ['file']];
    }

    private function store(string $driver, string $namespace = 'test'): CacheStore
    {
        return new CacheStore($driver === 'file'
            ? new FileCacheDriver($this->root, $namespace) : new ArrayCacheDriver(),
            $namespace, $this->clock);
    }
}

/** Mutable test clock proves exact expiry without sleeping. */
final class CacheTestClock implements ModelClock
{
    public function __construct(public int $second)
    {
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->second))->setTimezone(new DateTimeZone('UTC'));
    }
}

/** Poisoned cache payloads must never invoke a serialized class hook. */
final class CacheWakeupProbe
{
    public static bool $called = false;

    public function __wakeup(): void
    {
        self::$called = true;
    }
}
