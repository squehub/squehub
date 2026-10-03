<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Plugins\View;
use App\Support\RuntimeContext;
use App\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The View-to-Response bridge reuses Package activation and compiled sources. */
final class ViewResponseLifecycleTest extends TestCase
{
    private TemporaryProject $project;
    private string $package;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = new TemporaryProject();
        $this->package = 'Commerce' . bin2hex(random_bytes(4));
        $name = $this->package;
        $this->project->write("Project/Packages/{$name}/{$name}.php",
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {}');
        $manager = new PackageManager(new Application($this->project->path()));
        self::assertTrue($manager->apply($manager->planEnable($name))->complete());
        $this->selectFreshApplication();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        parent::tearDown();
    }

    public function testNamespacedResponseUsesCurrentPackageSourceAndSafeOverride(): void
    {
        $logical = $this->package . '::Orders.Index';
        $this->packageView('Orders.Index', '<p>PACKAGE</p>');
        $source = View::response($logical, status: 202, headers: ['X-Source' => 'package']);
        self::assertSame('<p>PACKAGE</p>', $source->content());
        self::assertSame(202, $source->status());
        self::assertSame('package', $source->header('X-Source'));
        self::assertSame(View::renderResult($logical)->html(), $source->content());

        $this->project->write('Project/PackagesViews/' . $this->package
            . '/Orders/Index.squehub.php', '<p>OVERRIDE</p>');
        $override = View::response($logical, status: 201, headers: ['X-Source' => 'override']);
        self::assertSame('<p>OVERRIDE</p>', $override->content());
        self::assertSame(201, $override->status());
        self::assertSame('override', $override->header('X-Source'));
        self::assertSame('<p>PACKAGE</p>', $source->content());
    }

    public function testDisabledPackageCannotBeResurrectedByOverrideOrCompiledArtifact(): void
    {
        $logical = $this->package . '::Orders.Index';
        $this->packageView('Orders.Index', '<p>PACKAGE</p>');
        $this->project->write('Project/PackagesViews/' . $this->package
            . '/Orders/Index.squehub.php', '<p>OVERRIDE</p>');
        self::assertSame('<p>OVERRIDE</p>', View::response($logical)->content());
        self::assertNotEmpty($this->artifacts());

        $manager = $this->app->container()->make(PackageManager::class);
        self::assertTrue($manager->apply($manager->planDisable($this->package))->complete());
        $this->selectFreshApplication();
        try {
            View::response($logical);
            self::fail('An inactive Package yielded a View Response.');
        } catch (ViewNotFoundException $error) {
            self::assertSame($logical, $error->view());
            self::assertStringNotContainsString($this->project->path(), $error->getMessage());
        }
    }

    public function testColdWarmClearCorruptAndChangedSourceKeepResponseContract(): void
    {
        $source = 'Project/Views/Pages/Changing.squehub.php';
        $this->project->write($source, 'Alpha');
        $cold = View::response('Pages.Changing', status: 202,
            headers: ['X-Mode' => 'cold']);
        self::assertSame('Alpha', $cold->content());
        self::assertSame(202, $cold->status());
        self::assertCount(1, $this->artifacts());

        $warm = View::warm();
        self::assertSame(0, $warm['failed']);
        $cached = View::response('Pages.Changing', status: 202,
            headers: ['X-Mode' => 'warm']);
        self::assertSame($cold->content(), $cached->content());
        self::assertSame($cold->status(), $cached->status());
        self::assertSame('warm', $cached->header('X-Mode'));
        self::assertSame('cold', $cold->header('X-Mode'));

        self::assertGreaterThanOrEqual(1, View::clearCompiled());
        self::assertCount(0, $this->artifacts());
        self::assertSame('Alpha', View::response('Pages.Changing')->content());
        $artifacts = $this->artifacts();
        self::assertCount(1, $artifacts);
        $artifact = array_shift($artifacts);
        self::assertIsString($artifact);
        self::assertSame(strlen('<?php return 42;'),
            file_put_contents($artifact, '<?php return 42;'));
        clearstatcache(true, $artifact);
        self::assertSame('Alpha', View::response('Pages.Changing')->content());
        self::assertStringNotContainsString('<?php return 42;',
            (string) file_get_contents($artifact));

        $mtime = filemtime($this->project->path($source));
        self::assertIsInt($mtime);
        $this->project->write($source, 'Bravo');
        self::assertTrue(touch($this->project->path($source), $mtime));
        clearstatcache(true, $this->project->path($source));
        $changed = View::response('Pages.Changing', status: 422,
            headers: ['X-Mode' => 'changed']);
        self::assertSame('Bravo', $changed->content());
        self::assertSame(422, $changed->status());
        self::assertSame('changed', $changed->header('X-Mode'));
        self::assertSame('Alpha', $cold->content());
    }

    public function testUnsafePackageOverrideCannotFallThroughToCompiledPackageSource(): void
    {
        $logical = $this->package . '::Orders.Index';
        $this->packageView('Orders.Index', 'PACKAGE_FALLBACK');
        self::assertSame('PACKAGE_FALLBACK', View::response($logical)->content());
        $override = $this->project->path('Project/PackagesViews/' . $this->package
            . '/Orders/Index.squehub.php');
        self::assertTrue(mkdir($override, 0777, true));
        try {
            View::response($logical);
            self::fail('An unsafe override fell through to a compiled Package View.');
        } catch (ViewNotFoundException $error) {
            self::assertSame($logical, $error->view());
            self::assertStringNotContainsString('PACKAGE_FALLBACK', $error->getMessage());
        }
    }

    public function testSourceReplacedByOutsideFileLinkCannotExecuteOldCompiledView(): void
    {
        $outside = new TemporaryProject();
        $source = 'Project/Packages/' . $this->package . '/Views/Orders/Index.squehub.php';
        try {
            $this->project->write($source, 'SAFE_SOURCE');
            self::assertSame('SAFE_SOURCE',
                View::response($this->package . '::Orders.Index')->content());
            $outside->write('Outside.squehub.php', 'OUTSIDE_VIEW_RESPONSE_SECRET');
            self::assertTrue(unlink($this->project->path($source)));
            if (!@symlink($outside->path('Outside.squehub.php'), $this->project->path($source))) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            ob_start();
            try {
                try {
                    View::response($this->package . '::Orders.Index');
                    self::fail('A stale compiled artifact executed after an unsafe source swap.');
                } catch (ViewNotFoundException $error) {
                    self::assertSame($this->package . '::Orders.Index', $error->view());
                    self::assertStringNotContainsString('OUTSIDE_VIEW_RESPONSE_SECRET',
                        $error->getMessage());
                }
                self::assertSame('', (string) ob_get_contents());
            } finally {
                ob_end_clean();
            }
        } finally {
            if (is_link($this->project->path($source))) {
                unlink($this->project->path($source));
            }
            $outside->remove();
        }
    }

    public function testApplicationsKeepTheirOwnRootsAndResponseState(): void
    {
        $second = new TemporaryProject();
        try {
            $this->project->write('Project/Views/Pages/Identity.squehub.php', 'Application A');
            $second->write('Project/Views/Pages/Identity.squehub.php', 'Application B');
            RuntimeContext::select($this->app);
            $firstResponse = View::response('Pages.Identity', status: 201,
                headers: ['X-App' => 'A']);
            $appB = new Application($second->path());
            $appB->bootstrap();
            RuntimeContext::select($appB);
            $secondResponse = View::response('Pages.Identity', status: 422,
                headers: ['X-App' => 'B']);
            RuntimeContext::select($this->app);
            $again = View::response('Pages.Identity');

            self::assertSame('Application A', $firstResponse->content());
            self::assertSame('Application B', $secondResponse->content());
            self::assertSame('Application A', $again->content());
            self::assertSame(201, $firstResponse->status());
            self::assertSame(422, $secondResponse->status());
            self::assertSame('A', $firstResponse->header('X-App'));
            self::assertSame('B', $secondResponse->header('X-App'));
            self::assertNull($again->header('X-App'));
        } finally {
            RuntimeContext::select($this->app);
            $second->remove();
        }
    }

    private function packageView(string $logical, string $source): void
    {
        $this->project->write('Project/Packages/' . $this->package . '/Views/'
            . str_replace('.', '/', $logical) . '.squehub.php', $source);
    }

    /** @return list<string> */
    private function artifacts(): array
    {
        $files = glob($this->project->path('Storage/Views/*.php')) ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function selectFreshApplication(): void
    {
        $this->app = new Application($this->project->path());
        $this->app->bootstrap();
        RuntimeContext::select($this->app);
    }
}
