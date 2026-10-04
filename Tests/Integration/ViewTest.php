<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\View\ViewNotFoundException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class ViewTest extends TestCase
{
    private string $applicationViews;
    private string $publishedViews;
    private string $packageViews;
    private string $legacyRootViews;
    private array $previousState;

    protected function setUp(): void
    {
        $this->applicationViews = BASE_DIR . '/Project/Views/';
        $this->publishedViews = BASE_DIR . '/Project/PackagesViews/';
        $this->packageViews = BASE_DIR . '/Project/Packages/Demo/Views/';
        $this->legacyRootViews = BASE_DIR . '/Views/';

        foreach ([$this->applicationViews, $this->publishedViews, $this->packageViews, $this->legacyRootViews] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }

        $this->previousState = [];
        foreach (['viewPaths', 'sections', 'sectionStack', 'parentView', 'packageManager', 'applicationRoot', 'contributions'] as $property) {
            $this->previousState[$property] = (new ReflectionProperty(View::class, $property))->getValue();
        }

        file_put_contents(BASE_DIR . '/Project/Packages/Demo/Demo.php',
            '<?php namespace Packages\\Demo; final class Demo extends \\App\\Plugins\\ServiceProvider {}');
        $packages = new PackageManager(new Application(BASE_DIR));
        if (!$packages->isEnabled('Demo')) {
            $packages->apply($packages->planEnable('Demo'));
        }
        View::setPackageManager($packages, BASE_DIR);

        $this->setViewState('viewPaths', []);
        $this->setViewState('sections', []);
        $this->setViewState('sectionStack', []);
        $this->setViewState('parentView', null);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousState as $property => $value) {
            $this->setViewState($property, $value);
        }
    }

    public function testApplicationViewsResolveAndTakePrecedence(): void
    {
        $this->write($this->applicationViews, 'Source.squehub.php', 'application');
        $this->write($this->publishedViews, 'Source.squehub.php', 'published');
        $this->write($this->packageViews, 'Source.squehub.php', 'package');

        self::assertSame('application', $this->render('source'));
        self::assertSame(
            [$this->applicationViews, $this->publishedViews],
            array_slice(View::getViewPaths(), 0, 2)
        );
        self::assertContains(realpath($this->packageViews) . '/', View::getViewPaths());
    }

    public function testPublishedAndPackageViewsResolveInOrder(): void
    {
        $this->write($this->publishedViews, 'Published.squehub.php', 'published');
        $this->write($this->packageViews, 'Package.squehub.php', 'package');
        $this->write($this->publishedViews, 'Shared.squehub.php', 'published');
        $this->write($this->packageViews, 'Shared.squehub.php', 'package');

        self::assertSame('published', $this->render('published'));
        self::assertSame('package', $this->render('package'));
        self::assertSame('published', $this->render('shared'));
    }

    public function testLegacyPackageDirectoryDoesNotActivateBundledViews(): void
    {
        $project = new TemporaryProject();
        try {
            $legacyPackageViews = $project->path('project/packages/Legacy/views/');
            $this->write($legacyPackageViews, 'legacy.squehub.php', 'legacy package');
            $app = new Application($project->path());
            View::setPackageManager(new PackageManager($app), $project->path());

            self::assertNotContains(realpath($legacyPackageViews) . '/', View::getViewPaths());
            $this->assertMissingView('Legacy');
        } finally {
            $project->remove();
        }
    }

    public function testDisabledCanonicalPackageViewIsAbsent(): void
    {
        $dormant = BASE_DIR . '/Project/Packages/Dormant/Views/';
        $this->write($dormant, 'Dormant.squehub.php', 'should not render');
        file_put_contents(BASE_DIR . '/Project/Packages/Dormant/Dormant.php',
            '<?php namespace Packages\\Dormant; final class Dormant extends \\App\\Plugins\\ServiceProvider {}');

        self::assertNotContains(realpath($dormant) . '/', View::getViewPaths());
        $this->assertMissingView('Dormant');
    }

    public function testApplicationAndPublishedViewRootsAcceptFirstLetterVariants(): void
    {
        $project = new TemporaryProject();
        try {
            $this->write($project->path('project/views/'), 'LegacyApplication.squehub.php', 'legacy application');
            $this->write($project->path('project/packagesViews/'), 'LegacyPublished.squehub.php', 'legacy published');
            $app = new Application($project->path());
            View::setPackageManager(new PackageManager($app), $project->path());

            self::assertContains('project', scandir($project->path()));
            self::assertNotContains('Project', scandir($project->path()));
            self::assertSame('legacy application', $this->render('legacyApplication'));
            self::assertSame('legacy published', $this->render('legacyPublished'));
        } finally {
            $project->remove();
        }
    }

    public function testLogicalNamesAcceptFirstLetterCaseVariantsForEachSegment(): void
    {
        $this->write($this->applicationViews, 'Account/Profile.squehub.php', 'profile');
        $this->write($this->applicationViews, 'Account/Profile.php', 'raw profile');

        self::assertSame('profile', $this->render('account.profile'));
        self::assertSame('profile', $this->render('Account.profile'));
        self::assertSame('profile', $this->render('account.Profile'));
        self::assertSame(
            realpath($this->applicationViews . 'Account/Profile.php'),
            realpath(View::findViewFile('account/profile'))
        );
    }

    public function testRootViewsAreNotUsedByEitherResolver(): void
    {
        $this->write($this->legacyRootViews, 'legacy-only.squehub.php', 'legacy');
        $this->write($this->legacyRootViews, 'legacy-raw.php', 'legacy');

        $this->assertMissingView('legacy-only');
        self::assertFalse(View::findViewFile('legacy-raw'));
        self::assertNotContains($this->legacyRootViews, View::getViewPaths());
    }

    public function testIncludesAndCompiledCacheStillWork(): void
    {
        $this->write($this->applicationViews, 'Hello.squehub.php', 'Hello {{ $name }} @include(\'partial\')');
        $this->write($this->publishedViews, 'Partial.squehub.php', '[{{ $name }}]');

        self::assertSame('Hello Ada [Ada]', $this->render('hello', ['name' => 'Ada']));
        $cacheFiles = glob(BASE_DIR . '/Storage/Views/*.php');
        self::assertIsArray($cacheFiles);
        self::assertNotEmpty($cacheFiles);

        self::assertSame('Hello Bea [Bea]', $this->render('hello', ['name' => 'Bea']));
        self::assertSame($cacheFiles, glob(BASE_DIR . '/Storage/Views/*.php'));
    }

    public function testSectionAndLayoutBehavior(): void
    {
        $this->write($this->applicationViews, 'Child.squehub.php', "@section('title')Welcome@endsection@extends('layout')");
        $this->write($this->publishedViews, 'Layout.squehub.php', "<h1>@yield('title')</h1>");

        self::assertStringContainsString('<h1>Welcome</h1>', $this->render('child'));
    }

    public function testEscapingRawOutputIncludesAndReservedErrors(): void
    {
        $this->write($this->applicationViews, 'Secure.squehub.php',
            '<p>{{ $name }}</p><i>{!! $trusted !!}</i>@include(\'securePartial\')');
        $this->write($this->packageViews, 'SecurePartial.squehub.php',
            '<span>{{ $name }}</span>{{ $errors->any() ? "bad" : "clean" }}');
        $rendered = $this->render('secure', [
            'name' => '<script>alert(1)</script>',
            'trusted' => '<b>yes</b>',
            'errors' => '<script>cannot replace bag</script>',
        ]);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered);
        self::assertStringNotContainsString('<script>', $rendered);
        self::assertStringContainsString('<b>yes</b>', $rendered);
        self::assertStringContainsString('<span>&lt;script&gt;', $rendered);
        self::assertStringContainsString('clean', $rendered);
    }

    public function testLayoutSectionsEscapeAndOldCacheKeyIsNotReused(): void
    {
        $this->write($this->applicationViews, 'SecureChild.squehub.php',
            "@section('title'){{ \$name }}@endsection@extends('secureLayout')");
        $this->write($this->applicationViews, 'SecureLayout.squehub.php',
            '<h1>@yield(\'title\')</h1><p>{{ $name }}</p>');
        $layout = $this->render('secureChild', ['name' => '<svg onload=alert(1)>']);
        self::assertStringContainsString('&lt;svg onload=alert(1)&gt;', $layout);
        self::assertStringNotContainsString('<svg', $layout);

        $source = '<p>{{ $name }}</p>';
        $this->write($this->applicationViews, 'CacheProbe.squehub.php', $source);
        $parsed = (new \ReflectionMethod(View::class, 'processBladeSyntax'))->invoke(null, $source);
        $cacheDir = BASE_DIR . '/Storage/Views/';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
        file_put_contents($cacheDir . md5('cacheProbe' . $parsed) . '.php', 'STALE RAW OUTPUT');
        $fresh = $this->render('cacheProbe', ['name' => '<b>new</b>']);
        self::assertSame('<p>&lt;b&gt;new&lt;/b&gt;</p>', $fresh);
    }

    public function testMissingViewReportsLogicalNameWithoutAPhysicalLocation(): void
    {
        $this->assertMissingView('absent');
    }

    private function assertMissingView(string $name): void
    {
        try {
            View::render($name);
            self::fail('A missing root View must fail through the exception boundary.');
        } catch (ViewNotFoundException $error) {
            self::assertSame($name, $error->view());
            self::assertStringContainsString('was not found', $error->getMessage());
            self::assertStringNotContainsString(BASE_DIR, $error->getMessage());
        }
    }

    private function setViewState(string $property, mixed $value): void
    {
        (new ReflectionProperty(View::class, $property))->setValue(null, $value);
    }

    private function write(string $directory, string $name, string $contents): void
    {
        $file = $directory . $name;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    private function render(string $name, array $data = []): string
    {
        ob_start();
        try {
            View::render($name, $data);
            return trim((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
