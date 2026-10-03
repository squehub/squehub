<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Support\RuntimeContext;
use App\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Containment is rechecked for namespaced sources and application overrides. */
final class PackageViewFilesystemTest extends TestCase
{
    private TemporaryProject $project;
    private string $package;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $this->package = 'Commerce' . bin2hex(random_bytes(4));
        $this->project->write("Project/Packages/{$this->package}/{$this->package}.php",
            '<?php namespace Packages\\' . $this->package . '; final class ' . $this->package
            . ' extends \\App\\Plugins\\ServiceProvider {}');
        $manager = new PackageManager(new Application($this->project->path()));
        self::assertTrue($manager->apply($manager->planEnable($this->package))->complete());
        $this->selectFreshApplication();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testFreshApplicationAcceptsSafeInRootPackageSourceLink(): void
    {
        $real = $this->packageFile('Orders/Real.squehub.php');
        $alias = $this->packageFile('Orders/Alias.squehub.php');
        $this->project->write($real, 'safe source');
        if (!@symlink($this->project->path($real), $this->project->path($alias))) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            $this->selectFreshApplication();
            self::assertSame('safe source', $this->render('Orders.Alias'));
        } finally {
            unlink($this->project->path($alias));
        }
    }

    public function testOutsideRootPackageSourceLinkIsUnavailable(): void
    {
        $outside = new TemporaryProject();
        $alias = $this->packageFile('Orders/Outside.squehub.php');
        try {
            $outside->write('Outside.squehub.php', 'OUTSIDE_PACKAGE_SECRET');
            $this->project->write($this->packageFile('Orders/Placeholder.squehub.php'), 'safe');
            if (!@symlink($outside->path('Outside.squehub.php'), $this->project->path($alias))) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->assertUnsafe('Orders.Outside', 'OUTSIDE_PACKAGE_SECRET');
        } finally {
            if (is_link($this->project->path($alias))) {
                unlink($this->project->path($alias));
            }
            $outside->remove();
        }
    }

    public function testBrokenPackageSourceLinkIsUnavailable(): void
    {
        $alias = $this->packageFile('Orders/Broken.squehub.php');
        $this->project->write($this->packageFile('Orders/Placeholder.squehub.php'), 'safe');
        if (!@symlink($this->project->path($this->packageFile('Orders/Absent.squehub.php')),
            $this->project->path($alias))) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            $this->assertUnsafe('Orders.Broken');
        } finally {
            unlink($this->project->path($alias));
        }
    }

    public function testSafeApplicationOverrideLinkWinsOverPackageSource(): void
    {
        $this->project->write($this->packageFile('Orders/Index.squehub.php'), 'package');
        $real = $this->overrideFile('Orders/Real.squehub.php');
        $alias = $this->overrideFile('Orders/Index.squehub.php');
        $this->project->write($real, 'safe override');
        if (!@symlink($this->project->path($real), $this->project->path($alias))) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            self::assertSame('safe override', $this->render('Orders.Index'));
        } finally {
            unlink($this->project->path($alias));
        }
    }

    public function testPresentNonFileOverrideBlocksPackageFallback(): void
    {
        $this->project->write($this->packageFile('Orders/Index.squehub.php'), 'package fallback');
        $override = $this->project->path($this->overrideFile('Orders/Index.squehub.php'));
        self::assertTrue(mkdir($override, 0777, true));
        $this->assertUnsafe('Orders.Index');
    }

    public function testOutsideAndBrokenOverridesFailRatherThanFallingBack(): void
    {
        $outside = new TemporaryProject();
        $alias = $this->overrideFile('Orders/Index.squehub.php');
        try {
            $this->project->write($this->packageFile('Orders/Index.squehub.php'), 'package fallback');
            $this->project->write($this->overrideFile('Orders/Placeholder.squehub.php'), 'safe');
            $outside->write('Outside.squehub.php', 'OUTSIDE_OVERRIDE_SECRET');
            if (!@symlink($outside->path('Outside.squehub.php'), $this->project->path($alias))) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->assertUnsafe('Orders.Index', 'OUTSIDE_OVERRIDE_SECRET');
            unlink($this->project->path($alias));
            if (!@symlink($this->project->path($this->overrideFile('Orders/Absent.squehub.php')),
                $this->project->path($alias))) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->assertUnsafe('Orders.Index');
        } finally {
            if (is_link($this->project->path($alias))) {
                unlink($this->project->path($alias));
            }
            $outside->remove();
        }
    }

    public function testLinkedOverrideNamespaceDirectoryCannotPointToSibling(): void
    {
        $this->project->write($this->packageFile('Orders/Index.squehub.php'), 'package fallback');
        $sibling = 'Project/PackagesViews/Accounting/Orders/Index.squehub.php';
        $this->project->write($sibling, 'SIBLING_OVERRIDE_SECRET');
        $namespaceDirectory = $this->project->path('Project/PackagesViews/' . $this->package);
        if (!@symlink($this->project->path('Project/PackagesViews/Accounting'),
            $namespaceDirectory)) {
            self::markTestSkipped('Directory symlinks are unavailable to this test process.');
        }
        try {
            $this->assertUnsafe('Orders.Index', 'SIBLING_OVERRIDE_SECRET');
        } finally {
            if (is_link($namespaceDirectory)) {
                if (DIRECTORY_SEPARATOR === '\\') {
                    rmdir($namespaceDirectory);
                } else {
                    unlink($namespaceDirectory);
                }
            }
        }
    }

    public function testCompiledPackageSourceCannotFollowOutsideRootSwap(): void
    {
        $outside = new TemporaryProject();
        $source = $this->packageFile('Orders/Swapped.squehub.php');
        try {
            $this->project->write($source, 'safe source');
            $outside->write('Outside.squehub.php', 'OUTSIDE_PACKAGE_SECRET');
            self::assertSame('safe source', $this->render('Orders.Swapped'));
            self::assertNotEmpty(glob($this->project->path('Storage/Views/*.php')) ?: []);
            unlink($this->project->path($source));
            if (!@symlink($outside->path('Outside.squehub.php'), $this->project->path($source))) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            clearstatcache(true, $this->project->path($source));
            $this->assertUnsafe('Orders.Swapped', 'OUTSIDE_PACKAGE_SECRET');
        } finally {
            if (is_link($this->project->path($source))) {
                unlink($this->project->path($source));
            }
            $outside->remove();
        }
    }

    public function testCompiledOverrideCannotFollowOutsideRootSwap(): void
    {
        $outside = new TemporaryProject();
        $override = $this->overrideFile('Orders/Swapped.squehub.php');
        try {
            $this->project->write($this->packageFile('Orders/Swapped.squehub.php'), 'package fallback');
            $this->project->write($override, 'safe override');
            $outside->write('Outside.squehub.php', 'OUTSIDE_OVERRIDE_SECRET');
            self::assertSame('safe override', $this->render('Orders.Swapped'));
            unlink($this->project->path($override));
            if (!@symlink($outside->path('Outside.squehub.php'), $this->project->path($override))) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            clearstatcache(true, $this->project->path($override));
            $this->assertUnsafe('Orders.Swapped', 'OUTSIDE_OVERRIDE_SECRET');
        } finally {
            if (is_link($this->project->path($override))) {
                unlink($this->project->path($override));
            }
            $outside->remove();
        }
    }

    public function testPhysicalAliasCycleAcrossPackageViewsIsRejected(): void
    {
        $source = $this->packageFile('Partials/A.squehub.php');
        $alias = $this->packageFile('Partials/B.squehub.php');
        $this->project->write($source,
            "@include('{$this->package}::Partials.B')");
        if (!@symlink($this->project->path($source), $this->project->path($alias))) {
            self::markTestSkipped('File symlinks are unavailable to this test process.');
        }
        try {
            $error = $this->renderFailure('Partials.A');
            self::assertStringContainsString('circular', strtolower($error->getMessage()));
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        } finally {
            unlink($this->project->path($alias));
        }
    }

    public function testLinuxLocalNameCasingFollowsPhysicalFilesystem(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Case-sensitive filesystem execution requires Linux qualification.');
        }
        $this->project->write($this->packageFile('Orders/CaseOnly.squehub.php'), 'exact');
        self::assertSame('exact', $this->render('Orders.CaseOnly'));
        $this->assertMissing('Orders.CASEONLY');
    }

    private function packageFile(string $relative): string
    {
        return 'Project/Packages/' . $this->package . '/Views/' . $relative;
    }

    private function overrideFile(string $relative): string
    {
        return 'Project/PackagesViews/' . $this->package . '/' . $relative;
    }

    private function selectFreshApplication(): void
    {
        $this->app = new Application($this->project->path());
        $this->app->bootstrap();
        RuntimeContext::select($this->app);
    }

    private function render(string $local): string
    {
        return trim(View::renderResult($this->package . '::' . $local)->html());
    }

    private function renderFailure(string $local): Throwable
    {
        try {
            View::renderResult($this->package . '::' . $local);
            self::fail('Unsafe or circular Package View rendered.');
        } catch (Throwable $error) {
            return $error;
        }
    }

    private function assertUnsafe(string $local, string $secret = ''): void
    {
        $error = $this->renderFailure($local);
        self::assertInstanceOf(ViewNotFoundException::class, $error);
        self::assertTrue($error->unsafe());
        self::assertSame($this->package . '::' . $local, $error->view());
        self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        if ($secret !== '') {
            self::assertStringNotContainsString($secret, $error->getMessage());
        }
    }

    private function assertMissing(string $local): void
    {
        $error = $this->renderFailure($local);
        self::assertInstanceOf(ViewNotFoundException::class, $error);
        self::assertSame($this->package . '::' . $local, $error->view());
    }
}
