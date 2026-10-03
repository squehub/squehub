<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Migration source planning must remain usable without a database connection. */
final class MigrationPlanCliTest extends TestCase
{
    /** @param list<string> $arguments */
    private function runCommand(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'squehub', ...$arguments, '--no-ansi'],
            dirname(__DIR__, 2), [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'false',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => 'squehub-phase21-unreachable.invalid',
            ]);
        $process->run();
        return $process;
    }

    public function testHelpAndJsonPlanRemainReadOnlyWithUnreachableDatabase(): void
    {
        $help = $this->runCommand(['help', '--raw']);
        self::assertSame(0, $help->getExitCode());
        self::assertStringContainsString('migrate:plan', $help->getOutput());

        $planned = $this->runCommand(['migrate:plan', '--json']);
        self::assertSame(0, $planned->getExitCode(),
            $planned->getOutput() . $planned->getErrorOutput());
        $plan = json_decode($planned->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('migrate:plan', $plan['operation']);
        self::assertSame('migration', $plan['metadata']['source']);
        self::assertContains('Migration history was not inspected; listed files are not confirmed pending.',
            $plan['warnings']);
        self::assertNotEmpty($plan['actions']);
        self::assertSame('migration', $plan['actions'][0]['category']);
    }
}
