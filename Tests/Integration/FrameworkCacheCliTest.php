<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Cache maintenance commands operate on one selected app and keep output private. */
final class FrameworkCacheCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["name" => "private-cli-secret"];');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testConfigBuildHelpAndCorruptArtifactRecovery(): void
    {
        $help = $this->runCli('config:cache', '--help', '--no-ansi');
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->project->path('Storage/Cache/Framework'));

        $build = $this->runCli('config:cache', '--no-ansi');
        self::assertSame(0, $build->getExitCode(), $build->getErrorOutput() . $build->getOutput());
        self::assertStringContainsString('1 source files', $build->getOutput());
        self::assertStringNotContainsString('private-cli-secret', $build->getOutput());
        $artifact = 'Storage/Cache/Framework/Config.json';
        self::assertFileExists($this->project->path($artifact));

        $this->project->write($artifact, '{broken private-cli-secret');
        $clear = $this->runCli('config:clear', '--no-ansi');
        self::assertSame(0, $clear->getExitCode(), $clear->getErrorOutput() . $clear->getOutput());
        self::assertStringContainsString('Configuration cache cleared', $clear->getOutput());
        self::assertStringNotContainsString('private-cli-secret', $clear->getOutput());
        self::assertFileDoesNotExist($this->project->path($artifact));

        $again = $this->runCli('config:clear', '--no-ansi');
        self::assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        self::assertStringContainsString('No configuration cache', $again->getOutput());
    }

    public function testConfigPreviewUsesSharedPlanWithoutPublishingTheArtifact(): void
    {
        $marker = $this->project->path('config-booted.txt');
        $routeMarker = $this->project->path('routes-booted.txt');
        $this->project->write('Config/App.php', '<?php file_put_contents('
            . var_export($marker, true)
            . ', "executed"); return ["name" => "private-cli-secret"];');
        $this->project->write('Project/Routes/Web.php', '<?php file_put_contents('
            . var_export($routeMarker, true) . ', "executed");');
        $preview = $this->runCli('config:cache', '--preview', '--no-ansi');
        self::assertSame(0, $preview->getExitCode(), $preview->getErrorOutput() . $preview->getOutput());
        self::assertStringContainsString('SqueHub Configuration Cache Plan', $preview->getOutput());
        self::assertStringContainsString('Storage/Cache/Framework/Config.json', $preview->getOutput());
        self::assertStringContainsString('Expected verification:', $preview->getOutput());
        self::assertStringNotContainsString('private-cli-secret', $preview->getOutput());
        self::assertFileDoesNotExist($marker, 'CLI preview executed Config PHP during bootstrap.');
        self::assertFileDoesNotExist($routeMarker, 'CLI preview booted routing providers.');
        self::assertDirectoryDoesNotExist($this->project->path('Storage/Cache/Framework'));

        // The ordinary explicit command keeps its established build behavior.
        $build = $this->runCli('config:cache', '--no-ansi');
        self::assertSame(0, $build->getExitCode(), $build->getErrorOutput() . $build->getOutput());
        self::assertStringContainsString('1 source files', $build->getOutput());
        self::assertFileExists($marker, 'Normal config:cache must still load Config PHP.');
        self::assertFileExists($this->project->path('Storage/Cache/Framework/Config.json'));
    }

    public function testRouteBuildAndClearUseOnlyTheSelectedPrivateArtifact(): void
    {
        $this->project->write('Project/Routes/Web.php',
            "<?php \\App\\Routing\\Route::path('/ready')->get('Project\\\\Controllers\\\\Home@show')->named('ready');");
        $help = $this->runCli('route:cache', '--help', '--no-ansi');
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        self::assertDirectoryDoesNotExist($this->project->path('Storage/Cache/Framework'));

        $build = $this->runCli('route:cache', '--no-ansi');
        self::assertSame(0, $build->getExitCode(), $build->getErrorOutput() . $build->getOutput());
        self::assertStringContainsString('1 routes from 1 source files', $build->getOutput());
        $routeArtifact = 'Storage/Cache/Framework/Routes.json';
        self::assertFileExists($this->project->path($routeArtifact));
        $this->project->write($routeArtifact, '{broken');
        $this->project->write('Storage/Cache/Framework/Config.json', 'config-owned');

        $clear = $this->runCli('route:clear', '--no-ansi');
        self::assertSame(0, $clear->getExitCode(), $clear->getErrorOutput() . $clear->getOutput());
        self::assertStringContainsString('Route cache cleared', $clear->getOutput());
        self::assertFileDoesNotExist($this->project->path($routeArtifact));
        self::assertSame('config-owned',
            file_get_contents($this->project->path('Storage/Cache/Framework/Config.json')));
    }

    public function testUncacheableRouteReportsAStableSourceAndLeavesNormalLoadingAvailable(): void
    {
        $this->project->write('Project/Routes/Web.php',
            "<?php \\App\\Routing\\Route::path('/hello')->get(static fn (): string => 'hello');");
        $build = $this->runCli('route:cache', '--no-ansi');
        self::assertSame(1, $build->getExitCode(), $build->getErrorOutput() . $build->getOutput());
        self::assertStringContainsString('Project/Routes/Web.php', $build->getOutput());
        self::assertFileDoesNotExist($this->project->path('Storage/Cache/Framework/Routes.json'));
        $ordinary = $this->runCli('route:list', '--no-ansi');
        self::assertSame(0, $ordinary->getExitCode(), $ordinary->getErrorOutput() . $ordinary->getOutput());
        self::assertStringContainsString('/hello', $ordinary->getOutput());
        $clear = $this->runCli('route:clear', '--no-ansi');
        self::assertSame(0, $clear->getExitCode(), $clear->getErrorOutput());
    }

    /** Run the real CLI registration against an isolated Application instance. */
    private function runCli(string ...$arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $runner = $this->project->path('RunCli.php');
        $this->project->write('RunCli.php', '<?php declare(strict_types=1);' . PHP_EOL
            . 'require_once ' . var_export($root . '/vendor/autoload.php', true) . ';' . PHP_EOL
            . 'require_once ' . var_export($root . '/App/Core/Helper.php', true) . ';' . PHP_EOL
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__);' . PHP_EOL
            . '\\App\\Foundation\\CliBootstrapMode::configure($squehubApp, $_SERVER["argv"] ?? []);' . PHP_EOL
            . '$squehubApp->register(\\App\\Routing\\RoutingServiceProvider::class);' . PHP_EOL
            . '$squehubApp->bootstrap();' . PHP_EOL
            . '\\App\\Support\\RuntimeContext::select($squehubApp);' . PHP_EOL
            . 'require ' . var_export($root . '/App/Clis/Clis.php', true) . ';' . PHP_EOL);
        $process = new Process([PHP_BINARY, $runner, ...$arguments], $this->project->path());
        $process->run();
        return $process;
    }
}
