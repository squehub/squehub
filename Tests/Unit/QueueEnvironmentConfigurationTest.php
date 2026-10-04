<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Environment;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The distributed Queue template may leave its optional Database connection blank. */
final class QueueEnvironmentConfigurationTest extends TestCase
{
    public function testBlankConnectionUsesTheDefaultDatabaseConnection(): void
    {
        self::assertNull($this->settings('QUEUE_DATABASE_CONNECTION=')['connections']['database']['database_connection']);
    }

    public function testNamedConnectionRemainsExplicit(): void
    {
        self::assertSame('reporting', $this->settings('QUEUE_DATABASE_CONNECTION=reporting')['connections']['database']['database_connection']);
    }

    private function settings(string $contents): array
    {
        $project = new TemporaryProject();
        $key = 'QUEUE_DATABASE_CONNECTION';
        $hadEnvironmentValue = array_key_exists($key, $_ENV);
        $environmentValue = $_ENV[$key] ?? null;
        $processValue = getenv($key);
        try {
            // Environment publishes parsed values for legacy readers; isolate
            // each configuration probe from prior and subsequent test cases.
            unset($_ENV[$key]);
            putenv($key);
            $project->write('.env', $contents . "\n");
            return (static function (Environment $environment): array {
                return require dirname(__DIR__, 2) . '/Config/Queue.php';
            })(new Environment($project->path()));
        } finally {
            if ($hadEnvironmentValue) {
                $_ENV[$key] = $environmentValue;
            } else {
                unset($_ENV[$key]);
            }
            putenv($processValue === false ? $key : $key . '=' . $processValue);
            $project->remove();
        }
    }
}
