<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Support\CaseFallbackAutoloader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Support/CaseFallbackAutoloader.php';

final class CaseFallbackAutoloaderTest extends TestCase
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

    public function testLoadsInitialLetterVariantsAcrossCanonicalRoots(): void
    {
        $loader = new CaseFallbackAutoloader($this->project->path());
        $segment = 'Fixture' . bin2hex(random_bytes(4));

        foreach (['App', 'Project', 'Database'] as $root) {
            $namespace = lcfirst($root) . '\\' . lcfirst($segment);
            $class = $namespace . '\\LowerWidget';
            $this->project->write(
                $root . '/' . $segment . '/LowerWidget.php',
                '<?php namespace ' . $namespace . '; class lowerWidget {}'
            );

            $loader->load($class);
            self::assertTrue(class_exists($class, false), $class);
        }
    }

    public function testLoadsCapitalizedClassFromLowercasePhysicalPaths(): void
    {
        $segment = 'caseFolder' . bin2hex(random_bytes(4));
        $namespace = 'app\\' . $segment;
        $class = 'App\\' . ucfirst($segment) . '\\LowerWidget';
        $this->project->write(
            'app/' . $segment . '/lowerWidget.php',
            '<?php namespace ' . $namespace . '; class LowerWidget {}'
        );

        (new CaseFallbackAutoloader($this->project->path()))->load($class);
        self::assertTrue(class_exists($class, false));
    }

    public function testPackagesAliasLoadsFromProjectPackagePathVariants(): void
    {
        $package = 'demo' . bin2hex(random_bytes(4));
        $class = 'Packages\\' . ucfirst($package) . '\\Widget';
        $this->project->write(
            'Project/packages/' . $package . '/widget.php',
            '<?php namespace packages\\' . $package . '; class Widget {}'
        );

        (new CaseFallbackAutoloader($this->project->path()))->load($class);
        self::assertTrue(class_exists($class, false));
    }

    public function testRegistrationAppendsAfterExistingAutoloaders(): void
    {
        $loader = new CaseFallbackAutoloader($this->project->path());
        $loader->register();

        try {
            $callbacks = spl_autoload_functions();
            self::assertSame([$loader, 'load'], $callbacks[array_key_last($callbacks)]);
        } finally {
            spl_autoload_unregister([$loader, 'load']);
        }
    }

    public function testComposerAutoloadResolvesLowercaseNamespaceAndClassName(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . 'echo class_exists("app\\\\core\\\\view") ? "loaded" : "missing";';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('loaded', $process->getOutput());
    }

    public function testRejectsPathSyntaxAndOtherRootsWithoutIncludingFiles(): void
    {
        $loader = new CaseFallbackAutoloader($this->project->path());
        $this->project->write('App/Guard/Probe.php', '<?php $GLOBALS["case_fallback_probe"] = true;');
        unset($GLOBALS['case_fallback_probe']);

        foreach (['app\\..\\Guard\\Probe', 'app\\Guard/Probe', 'app\\Guard\\\\Probe',
            'aPP\\Guard\\Probe', 'Other\\Guard\\Probe'] as $class) {
            $loader->load($class);
        }

        self::assertArrayNotHasKey('case_fallback_probe', $GLOBALS);
    }

    public function testRejectsMissingBasePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CaseFallbackAutoloader($this->project->path('missing'));
    }
}
