<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The public Dev command remains discoverable and rejects invalid sessions safely. */
final class DevCliTest extends TestCase
{
    public function testHelpListsOnlyImplementedModesAndKeepsStartIndependent(): void
    {
        $root = dirname(__DIR__, 2);
        $help = new Process([PHP_BINARY, 'squehub', 'dev', '--help', '--no-ansi'], $root);
        $help->run();
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        self::assertStringContainsString('SqueHub development session', $help->getOutput());
        foreach (['Node required:', 'Writes files:', 'Starts processes:',
            'Development-only:'] as $boundary) {
            self::assertStringContainsString($boundary, $help->getOutput());
        }
        foreach (['--host', '--port', '--queue', '--frontend', '--frontend-port'] as $option) {
            self::assertStringContainsString($option, $help->getOutput());
        }
        self::assertStringNotContainsString('--scheduler', $help->getOutput());

        $list = new Process([PHP_BINARY, 'squehub', 'help', '--raw', '--no-ansi'], $root);
        $list->run();
        self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        self::assertMatchesRegularExpression('/(?m)^dev\s{2,}/', $list->getOutput());
        self::assertMatchesRegularExpression('/(?m)^start\s{2,}/', $list->getOutput());

        $start = new Process([PHP_BINARY, 'squehub', 'start', '--help', '--no-ansi'], $root);
        $start->run();
        self::assertSame(0, $start->getExitCode(), $start->getErrorOutput());
        self::assertStringNotContainsString('--queue', $start->getOutput());
        foreach (['Node required:', 'Writes files:', 'Starts processes:',
            'Development-only:'] as $boundary) {
            self::assertStringContainsString($boundary, $start->getOutput());
        }
    }

    public function testCliPreflightBlocksMissingEnvironmentBeforeAnyServerStarts(): void
    {
        $project = new TemporaryProject();
        try {
            $root = dirname(__DIR__, 2);
            $project->write('Config/App.php', '<?php return ["env"=>"testing","debug"=>false];');
            $project->write('CliRunner.php', '<?php declare(strict_types=1); require '
                . var_export($root . '/vendor/autoload.php', true) . '; '
                . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
                . '$squehubApp->register(\\App\\Health\\HealthServiceProvider::class); '
                . '$squehubApp->bootstrap(); require '
                . var_export($root . '/App/Clis/Clis.php', true) . ';');
            $process = new Process([PHP_BINARY, $project->path('CliRunner.php'),
                'dev', '--port=not-a-port', '--no-ansi'], $project->path());
            $process->run();
            self::assertSame(1, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('SqueHub Dev', $process->getOutput());
            self::assertStringContainsString('Application preflight failed', $process->getOutput());
            self::assertStringNotContainsString('Server:', $process->getOutput());
            self::assertFileDoesNotExist($project->path('.env'));
            self::assertFileDoesNotExist($project->path('Project/Activation.json'));
        } finally {
            $project->remove();
        }
    }
}
