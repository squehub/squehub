<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RouteListCliTest extends TestCase
{
    public function testRouteListIncludesLegacyProjectRouteWithoutWebBootstrap(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, 'squehub', 'route:list', '--no-ansi'], $root);
        $process->setEnv([
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'squehub_route_list_no_database',
            'DB_USER' => 'squehub_route_list_no_database',
            'DB_PASSWORD' => 'invalid',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        foreach (['METHOD', 'URI', 'NAME', 'HANDLER', 'MIDDLEWARE', 'HOST', 'FALLBACK', 'welcome.page'] as $value) {
            self::assertStringContainsString($value, $process->getOutput());
        }
        self::assertMatchesRegularExpression('/GET\s*\|\s*\/\s*\|\s*welcome\.page/', $process->getOutput());
        self::assertStringNotContainsString('Database connection failed', $process->getOutput());
    }

    public function testRouteListIsRegisteredWithConsole(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, 'squehub', 'list', '--raw'], $root);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('route:list', $process->getOutput());
    }
}
