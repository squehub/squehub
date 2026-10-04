<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Core\Service;
use PHPUnit\Framework\TestCase;

final class LegacyServiceTest extends TestCase
{
    public function testStaticRegistryStillReturnsRegisteredInstance(): void
    {
        $service = new \stdClass();
        Service::register('container_milestone_fixture', $service);
        self::assertSame($service, Service::get('container_milestone_fixture'));
        self::assertSame($service, Service::container_milestone_fixture());
        self::assertNull(Service::get('container_milestone_missing'));
    }
}
