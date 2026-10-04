<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\View\LogicalViewName;
use PHPUnit\Framework\TestCase;

final class LogicalViewNameTest extends TestCase
{
    public function testOrdinaryAndNamespacedIdentitiesKeepTheirFullAndLocalNames(): void
    {
        $ordinary = LogicalViewName::parse('Orders.Index');
        self::assertNotNull($ordinary);
        self::assertSame('Orders.Index', $ordinary->name());
        self::assertNull($ordinary->namespace());
        self::assertSame('Orders.Index', $ordinary->local());
        self::assertSame('Components.Orders.Index', $ordinary->component()->name());

        $package = LogicalViewName::parse('Commerce::Orders.Index');
        self::assertNotNull($package);
        self::assertSame('Commerce::Orders.Index', $package->name());
        self::assertSame('Commerce', $package->namespace());
        self::assertSame('Orders.Index', $package->local());
        self::assertSame('Commerce::Components.Orders.Index', $package->component()->name());
    }

    public function testUnsafeOrAmbiguousNamesAreRejected(): void
    {
        foreach ([
            '', '::View', 'Commerce::', 'Commerce::::View', 'Commerce::Other::View',
            'commerce::Orders.Index', 'Commerce::Orders..Index', 'Commerce::../Orders',
            'Commerce::Orders/Index', 'Commerce::Orders\\Index', 'Commerce:Orders.Index',
            'Commerce::Orders.Index.squehub.php/', "Commerce::Orders.\0Index",
            'Orders/Index', 'Orders..Index',
        ] as $invalid) {
            self::assertNull(LogicalViewName::parse($invalid), $invalid);
        }
    }
}
