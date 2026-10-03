<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\View\Compiled\CompiledViewStore;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The CLI warms the selected Application's effective Package View identities. */
final class PackageViewCacheCommandTest extends TestCase
{
    private TemporaryProject $project;
    private string $package;
    private string $disabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $suffix = bin2hex(random_bytes(4));
        $this->package = 'Commerce' . $suffix;
        $this->disabled = 'Dormant' . $suffix;
        foreach ([$this->package, $this->disabled] as $name) {
            $this->project->write("Project/Packages/{$name}/{$name}.php",
                '<?php namespace Packages\\' . $name . '; final class ' . $name
                . ' extends \\App\\Plugins\\ServiceProvider {}');
        }
        $manager = new PackageManager(new Application($this->project->path()));
        self::assertTrue($manager->apply($manager->planEnable($this->package))->complete());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testWarmUsesActiveNamespaceAndSafeOverrideWithoutExecutingTemplates(): void
    {
        $marker = $this->project->path('template-executed');
        $this->project->write('Project/Views/Orders/Index.squehub.php', 'ordinary');
        $this->project->write('Project/Packages/' . $this->package
            . '/Views/Orders/Index.squehub.php',
            '<?php file_put_contents(' . var_export($marker, true)
            . ', "executed"); ?>PACKAGE_SOURCE_SENTINEL');
        $this->project->write('Project/Packages/' . $this->disabled
            . '/Views/Orders/Index.squehub.php', 'DISABLED_SOURCE_SENTINEL');

        $first = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(0, $first->getExitCode(), $first->getOutput() . $first->getErrorOutput());
        self::assertStringContainsString('Compiled: 2', $first->getOutput());
        self::assertStringContainsString('Failed:   0', $first->getOutput());
        self::assertFileDoesNotExist($marker);
        self::assertCount(2, $this->artifacts());

        $second = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(0, $second->getExitCode(), $second->getOutput() . $second->getErrorOutput());
        self::assertStringContainsString('Reused:   2', $second->getOutput());

        $this->project->write('Project/PackagesViews/' . $this->package
            . '/Orders/Index.squehub.php', 'APPLICATION_OVERRIDE_SENTINEL');
        $clear = $this->runCli('view:clear', '--no-ansi');
        self::assertSame(0, $clear->getExitCode(), $clear->getOutput() . $clear->getErrorOutput());
        $override = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(0, $override->getExitCode(), $override->getOutput()
            . $override->getErrorOutput());
        self::assertStringContainsString('Failed:   0', $override->getOutput());
        self::assertFileDoesNotExist($marker);
        $compiled = implode("\n", array_map(static fn (string $file): string =>
            (string) file_get_contents($file), $this->artifacts()));
        self::assertStringContainsString('APPLICATION_OVERRIDE_SENTINEL', $compiled);
        self::assertStringNotContainsString('PACKAGE_SOURCE_SENTINEL', $compiled);
        self::assertStringNotContainsString('DISABLED_SOURCE_SENTINEL', $compiled);
    }

    public function testBrokenNamespacedTemplateFailsWarmWithLogicalDiagnostic(): void
    {
        $this->project->write('Project/Packages/' . $this->package
            . '/Views/Orders/Broken.squehub.php', '@if($ready)');
        $warm = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(1, $warm->getExitCode());
        self::assertStringContainsString($this->package . '::Orders.Broken', $warm->getOutput());
        // The legacy unqualified identity and explicit Package identity are
        // separate logical Views, so this one source yields two diagnostics.
        self::assertStringContainsString('Failed:   2', $warm->getOutput());
        self::assertStringNotContainsString($this->project->path(), $warm->getOutput());
    }

    public function testUnsafeNamespaceRootDoesNotPreventHealthyNamespaceWarm(): void
    {
        $healthy = 'Healthy' . bin2hex(random_bytes(4));
        $this->project->write("Project/Packages/{$healthy}/{$healthy}.php",
            '<?php namespace Packages\\' . $healthy . '; final class ' . $healthy
            . ' extends \\App\\Plugins\\ServiceProvider {}');
        $manager = new PackageManager(new Application($this->project->path()));
        self::assertTrue($manager->apply($manager->planEnable($healthy))->complete());

        // A present namespace directory of the wrong type is a configuration
        // failure. It must not abort enumeration of the next active Package.
        $this->project->write('Project/PackagesViews/' . $this->package, 'not a directory');
        $source = 'HEALTHY_NAMESPACED_VIEW_SENTINEL';
        $this->project->write('Project/Packages/' . $healthy
            . '/Views/Orders/Index.squehub.php', $source);

        $warm = $this->runCli('view:cache', '--no-ansi');
        self::assertSame(1, $warm->getExitCode());
        self::assertStringContainsString('Package View namespace "' . $this->package
            . '" has an unsafe source root.', $warm->getOutput());
        self::assertStringContainsString('Failed:   1', $warm->getOutput());
        self::assertStringNotContainsString($this->project->path(), $warm->getOutput());

        $fingerprint = (new CompiledViewStore($this->project->path()))->fingerprint(
            $healthy . '::Orders.Index', $source, $source, 'template');
        self::assertFileExists($this->project->path('Storage/Views/squehub-view-'
            . $fingerprint . '.php'));
    }

    public function testPackageVerificationSnapshotsNamespaceWithoutRenderingView(): void
    {
        $marker = $this->project->path('template-executed');
        $this->project->write('Project/Packages/' . $this->package
            . '/Views/Orders/Index.squehub.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', "executed"); ?>');
        $verify = $this->runCli('package:verify', $this->package, '--no-ansi');
        self::assertSame(0, $verify->getExitCode(), $verify->getOutput()
            . $verify->getErrorOutput());
        self::assertFileDoesNotExist($marker);

        $inspect = $this->runCli('package:inspect', $this->package,
            '--type=view_namespace', '--no-ansi');
        self::assertSame(0, $inspect->getExitCode(), $inspect->getOutput()
            . $inspect->getErrorOutput());
        self::assertStringContainsString('Provenance: current', $inspect->getOutput());
        self::assertStringContainsString($this->package, $inspect->getOutput());
        self::assertFileDoesNotExist($marker);
    }

    /** @return list<string> */
    private function artifacts(): array
    {
        $files = glob($this->project->path('Storage/Views/*.php')) ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

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
