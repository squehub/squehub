<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Exercises the offline contract export boundary through the real CLI entry point. */
final class ContractCliTest extends TestCase
{
    public function testExportProducesStableOpenApiJsonWithoutDatabaseAccess(): void
    {
        $first = $this->runCommand('contract:export');
        $second = $this->runCommand('contract:export');

        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        self::assertSame($first->getOutput(), $second->getOutput());
        $document = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3.2.1', $document['openapi']);
        self::assertArrayHasKey('info', $document);
        self::assertArrayHasKey('paths', $document);
        self::assertStringNotContainsString('Database connection failed', $first->getOutput());
        self::assertStringNotContainsString('Contract export', $first->getOutput());
        self::assertStringNotContainsString('SQUEHUB_CONTRACT_SECRET_DO_NOT_LEAK',
            $first->getOutput() . $first->getErrorOutput());
    }

    public function testSqueHubExportAndPrettyOptionRemainMachineReadable(): void
    {
        $compact = $this->runCommand('contract:export', '--format=squehub');
        $pretty = $this->runCommand('contract:export', '--format=squehub', '--pretty');

        self::assertSame(0, $compact->getExitCode(), $compact->getErrorOutput());
        self::assertSame(0, $pretty->getExitCode(), $pretty->getErrorOutput());
        $first = json_decode($compact->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($pretty->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1', $first['squehub_contract']);
        self::assertSame($first, $second);
        self::assertStringContainsString("\n    ", $pretty->getOutput());
    }

    public function testInvalidFormatFailsWithoutMixingErrorTextIntoStdout(): void
    {
        $process = $this->runCommand('contract:export', '--format=unknown');

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('format must be openapi or squehub', $process->getErrorOutput());
    }

    public function testInvalidVersionFailsSafelyWithoutJsonOrPrivateConfiguration(): void
    {
        $process = $this->runCommand('contract:export', '--api-version=bad version');

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('Contract export failed', $process->getErrorOutput());
        self::assertStringNotContainsString('SQUEHUB_CONTRACT_SECRET_DO_NOT_LEAK',
            $process->getErrorOutput());
    }

    public function testCommandAppearsInListAndHasFocusedHelp(): void
    {
        $list = $this->runCommand('list', '--raw');
        $help = $this->runCommand('contract:export', '--help');

        self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        self::assertStringContainsString('contract:export', $list->getOutput());
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        foreach (['--format', '--api-version', '--pretty'] as $option) {
            self::assertStringContainsString($option, $help->getOutput());
        }
    }

    private function runCommand(string ...$arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, 'squehub', ...$arguments], $root);
        // Export must remain offline even when the configured database cannot
        // be reached. No actual application database is contacted by this test.
        $process->setEnv([
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'squehub_contract_no_database',
            'DB_USER' => 'squehub_contract_no_database',
            'DB_PASSWORD' => 'SQUEHUB_CONTRACT_SECRET_DO_NOT_LEAK',
        ]);
        $process->run();

        return $process;
    }
}
