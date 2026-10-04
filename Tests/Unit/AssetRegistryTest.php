<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\Assets\AssetException;
use App\View\Assets\AssetRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Stringable;

/** Application registrations are explicit owner metadata, not active assets. */
final class AssetRegistryTest extends TestCase
{
    public function testFluentRegistrationsKeepMultipleLogicalOwnersAndDeclarationOrder(): void
    {
        $registry = new AssetRegistry();
        self::assertSame([], $registry->snapshot());

        $registry->for(['Pages.Dashboard', 'Pages.Reports', 'Pages.Dashboard'])
            ->style('/assets/shared.css')
            ->script('/assets/shared.js', once: 'shared-runtime');

        self::assertSame([
            [
                'owners' => ['Pages.Dashboard', 'Pages.Reports'],
                'kind' => 'style', 'url' => '/assets/shared.css', 'once' => null,
            ],
            [
                'owners' => ['Pages.Dashboard', 'Pages.Reports'],
                'kind' => 'script', 'url' => '/assets/shared.js',
                'once' => 'shared-runtime',
            ],
        ], $registry->snapshot());
    }

    public function testUnknownOwnerMayBeRegisteredAndSnapshotIsStableDuringLaterMutation(): void
    {
        $registry = new AssetRegistry();
        $registry->for('Pages.InstalledLater')->style('/assets/first.css');
        $snapshot = $registry->snapshot();

        $registry->for('Pages.InstalledLater')->script('/assets/second.js');

        self::assertCount(1, $snapshot);
        self::assertCount(2, $registry->snapshot());
        self::assertSame('Pages.InstalledLater', $snapshot[0]['owners'][0]);
    }

    /** @dataProvider invalidOwners */
    public function testMalformedAndPhysicalOwnerNamesFailClearly(string|array $owners): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssetRegistry())->for($owners);
    }

    /** @return iterable<string, array{string|array}> */
    public static function invalidOwners(): iterable
    {
        yield 'empty' => [''];
        yield 'no owners' => [[]];
        yield 'empty segment' => ['Pages..Dashboard'];
        yield 'traversal' => ['../Pages.Dashboard'];
        yield 'unix path' => ['/home/app/Dashboard.squehub.php'];
        yield 'windows path' => ['D:\\Projects\\Dashboard.squehub.php'];
        yield 'control' => ["Pages.\nDashboard"];
        yield 'invalid array member' => [['Pages.Good', 'Pages..Bad']];
    }

    public function testExternalUrlsUseTheSameValidationAndAcceptStringableValues(): void
    {
        $registry = new AssetRegistry();
        $url = new class implements Stringable {
            public function __toString(): string { return 'https://example.test/app.css?v=1'; }
        };
        $registry->for('Pages.Dashboard')->style($url);
        self::assertSame('https://example.test/app.css?v=1',
            $registry->snapshot()[0]['url']);

        foreach (['', '   ', "\x00bad", "\x1Fbad", 17, new \stdClass()] as $invalid) {
            try {
                $registry->for('Pages.Dashboard')->style($invalid);
                self::fail('Expected an invalid asset URL to fail.');
            } catch (AssetException $exception) {
                self::assertStringContainsString('asset URL', $exception->getMessage());
                self::assertStringNotContainsString('bad', $exception->getMessage());
            }
        }
        self::assertCount(1, $registry->snapshot(), 'Rejected URLs must not be registered.');
    }

    public function testExternalOnceKeyMustBeNonemptyAndSafe(): void
    {
        $registry = new AssetRegistry();
        foreach (['', '   ', "\x00bad", str_repeat('x', 257)] as $invalid) {
            try {
                $registry->for('Pages.Dashboard')->script('/assets/app.js', $invalid);
                self::fail('Expected an invalid once key to fail.');
            } catch (AssetException $exception) {
                self::assertStringContainsString('once key', $exception->getMessage());
            }
        }
        self::assertSame([], $registry->snapshot());
    }
}
