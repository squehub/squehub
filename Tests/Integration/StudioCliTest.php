<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Studio CLI exposes help but rejects non-development or unapproved starts. */
final class StudioCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => $environment->get("APP_ENV", "development")];');
        $this->project->write('Config/Studio.php',
            '<?php return ["enabled" => $environment->boolean("STUDIO_ENABLED", false)];');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testHelpIsListedWithoutStartingAListener(): void
    {
        $help = $this->runCli(['studio', '--help', '--no-ansi'],
            ['APP_ENV' => 'production', 'STUDIO_ENABLED' => 'false']);
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        self::assertStringContainsString('studio', $help->getOutput());
        self::assertStringContainsString('--port', $help->getOutput());
        self::assertStringNotContainsString('--host', $help->getOutput());
        self::assertDirectoryDoesNotExist($this->project->path('Storage'));
    }

    public function testProductionAndMissingOptInAreRejectedBeforeServerPreparation(): void
    {
        foreach ([
            ['APP_ENV' => 'production', 'STUDIO_ENABLED' => 'true'],
            ['APP_ENV' => 'development', 'STUDIO_ENABLED' => 'false'],
        ] as $environment) {
            $run = $this->runCli(['studio', '--no-ansi'], $environment);
            self::assertSame(1, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
            self::assertStringContainsString('APP_ENV=development and STUDIO_ENABLED=true',
                $run->getOutput());
            self::assertDirectoryDoesNotExist($this->project->path('Storage'));
        }
    }

    public function testExplicitOptInStillRejectsInvalidListenerPort(): void
    {
        $run = $this->runCli(['studio', '--port=1', '--no-ansi'],
            ['APP_ENV' => 'development', 'STUDIO_ENABLED' => 'true']);
        self::assertSame(1, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertStringContainsString('Invalid port', $run->getOutput());
        self::assertDirectoryDoesNotExist($this->project->path('Storage'));
    }

    /** Run command registration with one disposable Application and shell settings. */
    private function runCli(array $arguments, array $environment): Process
    {
        $root = dirname(__DIR__, 2);
        $runner = $this->project->path('RunCli.php');
        $this->project->write('RunCli.php', '<?php declare(strict_types=1);' . PHP_EOL
            . 'require_once ' . var_export($root . '/vendor/autoload.php', true) . ';' . PHP_EOL
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__);' . PHP_EOL
            . '\\App\\Foundation\\CliBootstrapMode::configure($squehubApp, $_SERVER["argv"] ?? []);' . PHP_EOL
            . '$squehubApp->bootstrap();' . PHP_EOL
            . 'require ' . var_export($root . '/App/Clis/Clis.php', true) . ';' . PHP_EOL);
        $process = new Process([PHP_BINARY, $runner, ...$arguments], $this->project->path(),
            $environment);
        $process->run();
        return $process;
    }
}
