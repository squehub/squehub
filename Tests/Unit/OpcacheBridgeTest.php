<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Compiled\CompiledViewException;
use App\View\Compiled\OpcacheBridge;
use PHPUnit\Framework\TestCase;

/** Tests the optional, narrowly targeted OPcache policy without host settings. */
final class OpcacheBridgeTest extends TestCase
{
    public function testDisabledOrUnavailableOpcacheNeedsNoInvalidation(): void
    {
        $calls = 0;
        $bridge = new OpcacheBridge(static fn (): bool => false,
            static function (string $path) use (&$calls): bool {
                ++$calls;
                return false;
            });
        $bridge->invalidate('/not-cached.php');
        self::assertSame(0, $calls);
    }

    public function testEnabledOpcacheInvalidatesOnlyTheRequestedPath(): void
    {
        $paths = [];
        $bridge = new OpcacheBridge(static fn (): bool => true,
            static function (string $path) use (&$paths): bool {
                $paths[] = $path;
                return true;
            });
        $bridge->invalidate('/one/compiled.php');
        self::assertSame(['/one/compiled.php'], $paths);
    }

    public function testExplicitInvalidationFailureIsNotIgnored(): void
    {
        $bridge = new OpcacheBridge(static fn (): bool => true,
            static fn (string $path): bool => false);
        $this->expectException(CompiledViewException::class);
        $this->expectExceptionMessage('OPcache invalidation failed');
        $bridge->invalidate('/one/compiled.php');
    }
}
