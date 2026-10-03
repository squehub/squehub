<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Packages\PackageName;
use PHPUnit\Framework\TestCase;

final class PackageNameTest extends TestCase
{
    public function testAcceptsOnlyPortableExactPhpIdentifiers(): void
    {
        foreach (['Weather', 'Commerce2', 'Api_Connector'] as $name) {
            self::assertTrue(PackageName::valid($name), $name);
        }
        foreach (['weather', '9Weather', '../Weather', 'Weather/Other', 'Weather\\Other',
            'Weather-Other', 'Weather.', 'Class', 'CON', 'Lpt1', "Bad\nName",
        ] as $name) {
            self::assertFalse(PackageName::valid($name), $name);
        }
    }
}
