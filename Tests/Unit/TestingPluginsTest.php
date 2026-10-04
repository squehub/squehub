<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Plugins\TestApplication as PluginTestApplication;
use App\Plugins\TestCase as PluginTestCase;
use App\Plugins\TestClient as PluginTestClient;
use App\Plugins\TestResponse as PluginTestResponse;
use App\Testing\TestApplication;
use App\Testing\TestCase;
use App\Testing\TestClient;
use App\Testing\TestResponse;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/** The Plugins surface exposes the same test services, not parallel state. */
final class TestingPluginsTest extends PHPUnitTestCase
{
    public function test_testing_symbols_bridge_to_the_canonical_implementations(): void
    {
        self::assertTrue(is_subclass_of(PluginTestCase::class, TestCase::class));
        self::assertTrue(is_subclass_of(PluginTestCase::class, PHPUnitTestCase::class));
        self::assertTrue(class_exists(PluginTestApplication::class));
        self::assertSame(TestApplication::class, (new \ReflectionClass(PluginTestApplication::class))->getName());
        self::assertTrue(class_exists(PluginTestClient::class));
        self::assertSame(TestClient::class, (new \ReflectionClass(PluginTestClient::class))->getName());
        self::assertTrue(class_exists(PluginTestResponse::class));
        self::assertSame(TestResponse::class, (new \ReflectionClass(PluginTestResponse::class))->getName());
    }
}
