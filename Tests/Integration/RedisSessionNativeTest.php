<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Native session rotation is checked in a fresh process without HTTP headers. */
final class RedisSessionNativeTest extends TestCase
{
    public function testRedisHandlerKeepsSessionStateAndInvalidatesOldId(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
require 'Tests/Unit/RedisInfrastructureTest.php';
$client = new SqueHub\Tests\Unit\InfraRedisClient();
$connection = new App\Redis\RedisConnection(['prefix' => 'app:'], static fn () => $client);
$handler = new App\Session\Drivers\RedisSessionHandler($connection, 'app', 120);
$driver = new App\Session\Drivers\NativeSessionDriver([
    'name' => 'squehub_probe', 'lifetime' => 2, 'path' => '/', 'domain' => null,
    'secure' => false, 'http_only' => true, 'strict_mode' => true, 'same_site' => 'Lax',
], $handler);
$store = new App\Session\SessionStore($driver);
$store->put('thing', 'value');
$old = $store->id();
$store->regenerate();
$new = $store->id();
$store->close();
$result = [$old !== $new, !$handler->validateId($old),
    $handler->validateId($new), $store->get('thing')];
$store->invalidate();
$store->close();
$result[] = !$handler->validateId($new);
$result[] = $store->get('thing', 'gone');
$store->close();
echo json_encode($result);
PHP;
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2));
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame([true, true, true, 'value', true, 'gone'],
            json_decode($process->getOutput(), true));
    }
}
