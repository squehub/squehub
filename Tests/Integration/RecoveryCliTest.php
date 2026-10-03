<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Recovery CLI reports boundaries only; no restore operation is registered. */
final class RecoveryCliTest extends TestCase
{
    /** @param list<string> $arguments */
    private function runCommand(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'squehub', ...$arguments, '--no-ansi'],
            dirname(__DIR__, 2), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_DATABASE' => ':memory:']);
        $process->run();
        return $process;
    }

    public function testJsonInventoryKeepsDataAndSecretsOutsideSource(): void
    {
        $help = $this->runCommand(['help', '--raw']);
        self::assertSame(0, $help->getExitCode());
        self::assertStringContainsString('recovery:plan', $help->getOutput());
        $plan = $this->runCommand(['recovery:plan', '--json']);
        self::assertSame(0, $plan->getExitCode(), $plan->getOutput() . $plan->getErrorOutput());
        $data = json_decode($plan->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(false, $data['boundaries']['source']['included']);
        self::assertSame(false, $data['boundaries']['database']['restored']);
        self::assertSame(false, $data['boundaries']['secrets']['included']);
        self::assertSame('at_least_once', $data['boundaries']['queue']['delivery']);
        self::assertStringNotContainsString('APP_KEY', $plan->getOutput());
    }

    public function testInvalidSnapshotFailsWithGenericOutput(): void
    {
        $marker = 'SQUEHUB_RECOVERY_SECRET_MISSING';
        $process = $this->runCommand(['recovery:plan',
            '--database-snapshot=' . dirname(__DIR__, 2) . '/' . $marker,
            '--snapshot-driver=sqlite']);
        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString($marker,
            $process->getOutput() . $process->getErrorOutput());
    }

    public function testExpectedSnapshotChecksumIsComparedWithoutRestoring(): void
    {
        $snapshot = tempnam(sys_get_temp_dir(), 'squehub-recovery-');
        self::assertIsString($snapshot);
        try {
            file_put_contents($snapshot, 'operator-produced SQLite snapshot fixture');
            $matched = $this->runCommand(['recovery:plan', '--json',
                '--database-snapshot=' . $snapshot, '--snapshot-driver=sqlite',
                '--snapshot-sha256=' . hash_file('sha256', $snapshot)]);
            self::assertSame(0, $matched->getExitCode(), $matched->getOutput() . $matched->getErrorOutput());
            $report = json_decode($matched->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('verified_against_expected',
                $report['boundaries']['database']['checksum']);
            self::assertFalse($report['boundaries']['database']['restored']);
            $mismatch = $this->runCommand(['recovery:plan',
                '--database-snapshot=' . $snapshot, '--snapshot-driver=sqlite',
                '--snapshot-sha256=' . str_repeat('0', 64)]);
            self::assertSame(1, $mismatch->getExitCode());
            self::assertStringNotContainsString($snapshot,
                $mismatch->getOutput() . $mismatch->getErrorOutput());
        } finally {
            unlink($snapshot);
        }
    }
}
