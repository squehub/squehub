<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Every registered command must let Symfony render help without executing it. */
final class CliHelpTest extends TestCase
{
    public function testBareHelpAndShortAliasListEveryCommand(): void
    {
        $root = dirname(__DIR__, 2);
        $list = new Process([PHP_BINARY, 'squehub', 'list', '--no-ansi'], $root);
        $list->run();
        self::assertTrue($list->isSuccessful(), $list->getErrorOutput());

        $rawList = new Process([PHP_BINARY, 'squehub', 'list', '--raw', '--no-ansi'], $root);
        $rawList->run();
        self::assertTrue($rawList->isSuccessful(), $rawList->getErrorOutput());

        $registered = [];
        foreach (preg_split('/\R/', $rawList->getOutput()) as $line) {
            if (preg_match('/^([a-z][a-z0-9:-]*)\s{2,}/', $line, $matches)) {
                $registered[] = $matches[1];
            }
        }
        self::assertNotEmpty($registered);

        foreach (['help', 'h'] as $name) {
            $help = new Process([PHP_BINARY, 'squehub', $name, '--no-ansi'], $root);
            $help->run();
            self::assertTrue($help->isSuccessful(), $name . ': ' . $help->getErrorOutput());
            self::assertSame($list->getOutput(), $help->getOutput(), $name);

            // Compare the raw registry with the readable listing. A command
            // added later must appear in both views without a curated list.
            foreach ($registered as $command) {
                self::assertMatchesRegularExpression(
                    '/(?m)^\s+' . preg_quote($command, '/') . '\s+/',
                    $help->getOutput(),
                    $name . ' omitted ' . $command
                );
            }

            $rawHelp = new Process([PHP_BINARY, 'squehub', $name, '--raw', '--no-ansi'], $root);
            $rawHelp->run();
            self::assertTrue($rawHelp->isSuccessful(), $name . ' --raw: ' . $rawHelp->getErrorOutput());
            self::assertSame($rawList->getOutput(), $rawHelp->getOutput(), $name . ' --raw');
        }

        $commandHelp = new Process([PHP_BINARY, 'squehub', 'help', 'route:list', '--no-ansi'], $root);
        $commandHelp->run();
        self::assertTrue($commandHelp->isSuccessful(), $commandHelp->getErrorOutput());
        self::assertStringContainsString('route:list', $commandHelp->getOutput());
        self::assertStringNotContainsString('Available commands:', $commandHelp->getOutput());
    }

    public function testRegisteredCommandHelpPaths(): void
    {
        $root = dirname(__DIR__, 2);
        $list = new Process([PHP_BINARY, 'squehub', 'list', '--raw', '--no-ansi'], $root);
        $list->run();
        self::assertTrue($list->isSuccessful(), $list->getErrorOutput());

        $names = [];
        foreach (preg_split('/\R/', $list->getOutput()) as $line) {
            if (preg_match('/^([a-z][a-z0-9:-]*)\s{2,}/', $line, $matches)) $names[] = $matches[1];
        }
        self::assertContains('seed', $names);
        self::assertContains('setup', $names);
        self::assertContains('seed:rollback', $names);
        self::assertContains('seed:status', $names);
        self::assertNotContains('dump:run', $names);
        self::assertNotContains('dump:rollback', $names);
        self::assertContains('cache:clear', $names);
        foreach (['config:cache', 'config:clear', 'route:cache', 'route:clear'] as $name) {
            self::assertContains($name, $names);
        }
        self::assertContains('view:cache', $names);
        self::assertContains('view:clear', $names);
        self::assertContains('route:list', $names);
        self::assertContains('studio', $names);
        foreach (['list', 'install', 'enable', 'disable', 'upgrade', 'remove'] as $operation) {
            self::assertContains('package:' . $operation, $names);
        }
        foreach (['list', 'inspect', 'install', 'enable', 'disable', 'upgrade', 'remove'] as $operation) {
            self::assertContains('kit:' . $operation, $names);
        }

        foreach ($names as $name) {
            $help = new Process([PHP_BINARY, 'squehub', $name, '--help', '--no-ansi'], $root);
            $help->run();
            self::assertTrue($help->isSuccessful(), $name . ': ' . $help->getErrorOutput());
            self::assertStringContainsString('Usage:', $help->getOutput(), $name);
            self::assertStringNotContainsString('Fatal error', $help->getOutput() . $help->getErrorOutput());
        }
    }

    public function testInvalidArgumentsFailWithoutFatalErrors(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            ['start', 'invalid/host', '8000'],
            ['start', 'localhost', '1e4'],
            ['make:controller'],
            ['seed:rollback'],
            ['package:install'],
            ['package:install', '../escape'],
            ['package:remove'],
            ['package:remove', '../escape'],
            ['package:enable'],
            ['package:disable'],
            ['package:upgrade'],
            ['package:upgrade', 'Weather'],
            ['kit:install'],
            ['kit:install', '../escape'],
            ['kit:inspect'],
            ['kit:enable'],
            ['kit:disable'],
            ['kit:upgrade'],
            ['kit:upgrade', 'Ecommerce'],
            ['kit:remove'],
            ['kit:remove', '../escape'],
        ] as $arguments) {
            $process = new Process([PHP_BINARY, 'squehub', ...$arguments, '--no-ansi'], $root);
            $process->run();
            self::assertSame(1, $process->getExitCode(), implode(' ', $arguments));
            self::assertStringNotContainsString('Fatal error', $process->getOutput() . $process->getErrorOutput());
        }
    }

    public function testBackupReportsMissingOptionalZipBeforeWriting(): void
    {
        $root = dirname(__DIR__, 2);
        $backupPath = $root . '/Storage/Backups/Dev';
        $existed = is_dir($backupPath);
        // -n suppresses optional extensions in this child without changing
        // the host's php.ini or running the archive-writing branch.
        $process = new Process([PHP_BINARY, '-n', 'squehub', 'backup:dev', '--no-ansi'], $root);
        $process->run();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Enable zip in your PHP CLI php.ini', $process->getOutput());
        self::assertSame($existed, is_dir($backupPath));
    }

}
