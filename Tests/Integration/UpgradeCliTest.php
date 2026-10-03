<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The upgrade command must boot in inspection mode and never apply a plan. */
final class UpgradeCliTest extends TestCase
{
    /** @param list<string> $arguments */
    private function runCommand(array $arguments): Process
    {
        $process = new Process([PHP_BINARY, 'squehub', ...$arguments, '--no-ansi'],
            dirname(__DIR__, 2), ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false']);
        $process->run();
        return $process;
    }

    public function testIdenticalLocalTargetIsCompatibleAndHelpListsTheCommand(): void
    {
        $help = $this->runCommand(['help', '--raw']);
        self::assertSame(0, $help->getExitCode());
        self::assertStringContainsString('upgrade:check', $help->getOutput());
        $checked = $this->runCommand(['upgrade:check', '.', '--json']);
        self::assertSame(0, $checked->getExitCode(), $checked->getOutput() . $checked->getErrorOutput());
        $report = json_decode($checked->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('compatible', $report['status']);
        self::assertSame([], $report['plan']['actions']);
        self::assertSame([], $report['findings']);
    }

    public function testUnavailableTargetFailsWithoutPrintingPhysicalPath(): void
    {
        $secretPath = dirname(__DIR__, 2) . '/SQUEHUB_UPGRADE_SECRET_MISSING';
        $checked = $this->runCommand(['upgrade:check', $secretPath]);
        self::assertSame(1, $checked->getExitCode());
        self::assertStringNotContainsString($secretPath,
            $checked->getOutput() . $checked->getErrorOutput());
    }

    public function testDifferentTargetIsReportedWithoutExecutingItsPhpOrPrintingContents(): void
    {
        $target = new TemporaryProject();
        $marker = $target->path('executed.txt');
        $secret = 'SQUEHUB_UPGRADE_SOURCE_SECRET';
        try {
            rmdir($target->path('config')); // The fixture's legacy lowercase dir would be a separate case-collision test.
            $target->write('composer.json', '{"name":"squehub/squehub","require":{"php":"^8.2"}}');
            $target->write('App/Incoming.php', '<?php file_put_contents('
                . var_export($marker, true) . ', ' . var_export($secret, true) . ');');
            $checked = $this->runCommand(['upgrade:check', $target->path(), '--json']);
            self::assertSame(1, $checked->getExitCode());
            $report = json_decode($checked->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('unknown', $report['status']);
            self::assertContains('App/Incoming.php', array_column($report['plan']['actions'], 'subject'));
            self::assertFileDoesNotExist($marker);
            self::assertStringNotContainsString($secret,
                $checked->getOutput() . $checked->getErrorOutput());
        } finally {
            $target->remove();
        }
    }
}
