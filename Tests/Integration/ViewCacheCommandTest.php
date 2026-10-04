<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises View maintenance commands in an isolated application process. */
final class ViewCacheCommandTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testHelpDoesNotCreateCompiledState(): void
    {
        foreach (['view:cache', 'view:clear'] as $name) {
            $help = $this->runCli($name, '--help', '--no-ansi');
            self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
            self::assertStringContainsString($name, $help->getOutput());
            self::assertStringContainsString('Usage:', $help->getOutput());
            self::assertDirectoryDoesNotExist($this->project->path('Storage/Views'));
        }
    }

    public function testWarmCompilesWithoutRenderingAndClearPreservesOtherStorage(): void
    {
        $marker = $this->project->path('template-executed');
        $this->project->write('Project/Views/Pages/Welcome.squehub.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', "executed"); ?>Welcome');
        $this->project->write('Project/Views/Components/Card.squehub.php',
            "@props(['label' => 'Card'])<div>{{ \$label }}</div>");
        $this->project->write('Project/Views/Partials/Small.squehub.php',
            '<small>Partial</small>');
        $this->project->write('Project/Views/Layouts/Main.squehub.php',
            '<main>@yield(\'content\')</main>');

        $first = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput() . $first->getOutput());
        self::assertStringContainsString('Compiled: 4', $first->getOutput());
        self::assertStringContainsString('Reused:   0', $first->getOutput());
        self::assertStringContainsString('Failed:   0', $first->getOutput());
        self::assertFileDoesNotExist($marker);

        $second = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput() . $second->getOutput());
        self::assertStringContainsString('Compiled: 0', $second->getOutput());
        self::assertStringContainsString('Reused:   4', $second->getOutput());
        self::assertFileDoesNotExist($marker);

        $this->project->write('Storage/Views/keep.txt', 'unrelated');
        $this->project->write('Storage/Cache/data.txt', 'application cache');
        $clear = $this->runCli('view:clear', '--no-ansi');
        self::assertSame(0, $clear->getExitCode(), $clear->getErrorOutput() . $clear->getOutput());
        self::assertStringContainsString('Compiled Views cleared.', $clear->getOutput());
        self::assertStringContainsString('Removed:', $clear->getOutput());
        self::assertSame('unrelated', file_get_contents($this->project->path('Storage/Views/keep.txt')));
        self::assertSame('application cache', file_get_contents($this->project->path('Storage/Cache/data.txt')));

        $afterClear = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(0, $afterClear->getExitCode(), $afterClear->getErrorOutput() . $afterClear->getOutput());
        self::assertStringContainsString('Compiled: 4', $afterClear->getOutput());
        self::assertFileDoesNotExist($marker);
    }

    public function testWarmReportsSourceAwareFailureAndNonzeroExit(): void
    {
        $this->project->write('Project/Views/Pages/Valid.squehub.php', 'Valid');
        $this->project->write('Project/Views/Pages/Broken.squehub.php', '@if($ready)');

        $warm = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(1, $warm->getExitCode(), $warm->getErrorOutput() . $warm->getOutput());
        self::assertStringContainsString('Compiled: 1', $warm->getOutput());
        self::assertStringContainsString('Failed:   1', $warm->getOutput());
        self::assertStringContainsString('Pages.Broken', $warm->getOutput());
        self::assertStringContainsString('line 1', $warm->getOutput());
        self::assertStringNotContainsString($this->project->path(), $warm->getOutput());
    }

    public function testClearMissingCacheIsIdempotent(): void
    {
        foreach ([0, 1] as $_) {
            $clear = $this->runCli('view:clear', '--no-ansi');
            self::assertSame(0, $clear->getExitCode(), $clear->getErrorOutput() . $clear->getOutput());
            self::assertStringContainsString('Removed: 0', $clear->getOutput());
        }
        self::assertDirectoryDoesNotExist($this->project->path('Storage/Views'));
    }

    public function testClearRejectsAnUnusableCacheRootWithoutExposingItsPath(): void
    {
        $this->project->write('Storage/Views', 'not a directory');
        $this->project->write('Project/Views/Pages/Welcome.squehub.php', 'Welcome');

        $warm = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(1, $warm->getExitCode(), $warm->getErrorOutput() . $warm->getOutput());
        self::assertStringNotContainsString($this->project->path(), $warm->getOutput());

        $clear = $this->runCli('view:clear', '--no-ansi');
        self::assertSame(1, $clear->getExitCode(), $clear->getErrorOutput() . $clear->getOutput());
        self::assertStringContainsString('could not be cleared', $clear->getOutput());
        self::assertStringNotContainsString($this->project->path(), $clear->getOutput());
        self::assertSame('not a directory', file_get_contents($this->project->path('Storage/Views')));
    }

    /** A separate PHP process proves command bootstrap uses the selected app. */
    private function runCli(string ...$arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $runner = $this->project->path('RunCli.php');
        $this->project->write('RunCli.php', '<?php declare(strict_types=1);' . PHP_EOL
            . 'require_once ' . var_export($root . '/vendor/autoload.php', true) . ';' . PHP_EOL
            . 'require_once ' . var_export($root . '/App/Core/Helper.php', true) . ';' . PHP_EOL
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__);' . PHP_EOL
            . '\\App\\Foundation\\CliBootstrapMode::configure($squehubApp, $_SERVER[\'argv\'] ?? []);' . PHP_EOL
            . '$squehubApp->bootstrap();' . PHP_EOL
            . '\\App\\Support\\RuntimeContext::select($squehubApp);' . PHP_EOL
            . 'require ' . var_export($root . '/App/Clis/Clis.php', true) . ';' . PHP_EOL);
        $process = new Process([PHP_BINARY, $runner, ...$arguments], $this->project->path());
        $process->run();
        return $process;
    }
}
