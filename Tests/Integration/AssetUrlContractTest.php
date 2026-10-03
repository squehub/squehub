<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Http\Exception\NotFoundHttpException;
use App\Http\ExceptionHandler;
use App\Http\Request;
use App\Packages\PackageManager;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** The public asset URL contract through the real Application and View APIs. */
final class AssetUrlContractTest extends TestCase
{
    public function testAssetHelperUsesTheSelectedApplicationWithoutARequest(): void
    {
        $alphaProject = new TemporaryProject();
        $betaProject = new TemporaryProject();
        try {
            $alpha = $this->application($alphaProject, '/alpha');
            $beta = $this->application($betaProject, '/beta');

            RuntimeContext::select($alpha);
            self::assertSame('/alpha/assets/app.css', \asset('/assets/app.css'));
            self::assertSame('/alpha/assets/app.css?v=42#theme',
                \asset('/assets/app.css?v=42#theme'));

            RuntimeContext::select($beta);
            self::assertSame('/beta/assets/app.css', \asset('/assets/app.css'));
            self::assertSame('/beta/assets/app.css', \asset('/beta/assets/app.css'));

            RuntimeContext::select($alpha);
            self::assertSame('/alpha/assets/app.css', \asset('/assets/app.css'));
        } finally {
            $alphaProject->remove();
            $betaProject->remove();
        }
    }

    public function testShippedWelcomeViewUsesMountedAssetsAtRootAndInSubdirectory(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2)
            . '/Project/Views/Home/Welcome.squehub.php');
        self::assertIsString($source);
        $rootProject = new TemporaryProject();
        $mountedProject = new TemporaryProject();
        try {
            // Both Applications render the same shipped source; only the mount differs.
            foreach ([$rootProject, $mountedProject] as $project) {
                $project->write('Project/Views/Home/Welcome.squehub.php', $source);
            }

            RuntimeContext::select($this->application($rootProject, ''));
            $root = View::renderResult('Home.Welcome')->html();
            self::assertStringContainsString(
                'href="/assets/default/favicon/squehub-icon.png"', $root);
            self::assertStringContainsString(
                'src="/assets/default/img/squehub-icon.png"', $root);

            RuntimeContext::select($this->application($mountedProject, '/squehub-v2'));
            $mounted = View::renderResult('Home.Welcome')->html();
            self::assertStringContainsString(
                'href="/squehub-v2/assets/default/favicon/squehub-icon.png"', $mounted);
            self::assertStringContainsString(
                'src="/squehub-v2/assets/default/img/squehub-icon.png"', $mounted);
            self::assertStringNotContainsString('href="/assets/default/', $mounted);
            self::assertStringNotContainsString('src="/assets/default/', $mounted);
        } finally {
            $rootProject->remove();
            $mountedProject->remove();
        }
    }

    public function testEveryParticipatingViewAssetOwnerUsesTheMountedResolver(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Views/Layouts/App.squehub.php',
                '<head>@stack(\'styles\')</head><body>@yield(\'content\')'
                . '@stack(\'scripts\')</body>');
            $project->write('Project/Views/Pages/Home.squehub.php',
                "@extends('Layouts.App')@style('/assets/page.css')"
                . "@script('/assets/page.js')@section('content')"
                . "@include('Partials.Badge')@component('Badge')@endcomponent"
                . "@include('Addon')@endsection");
            $project->write('Project/Views/Partials/Badge.squehub.php',
                "@style('/assets/partial.css')@script('/assets/partial.js')");
            $project->write('Project/Views/Components/Badge.squehub.php',
                "@style('/assets/component.css')@script('/assets/component.js')"
                . '<span>Badge</span>');
            $project->write('Project/Packages/AssetUrlContractAddon/AssetUrlContractAddon.php',
                '<?php namespace Packages\\AssetUrlContractAddon; final class AssetUrlContractAddon extends '
                . '\\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/AssetUrlContractAddon/Views/Addon.squehub.php',
                "@style('/assets/Kits/AssetUrlContractAddon/addon.css')"
                . "@script('/assets/Kits/AssetUrlContractAddon/addon.js')");

            $installer = $this->application($project, '/squehub-v2');
            $packages = $installer->container()->make(PackageManager::class);
            self::assertTrue($packages->apply($packages->planEnable('AssetUrlContractAddon'))->complete());

            $app = $this->application($project, '/squehub-v2');
            RuntimeContext::select($app);
            View::assets()->for('Pages.Home')
                ->style('/squehub-v2/assets/registered.css?v=1')
                ->script('/assets/registered.js#module');
            $html = View::renderResult('Pages.Home')->html();

            foreach ([
                'page.css', 'partial.css', 'component.css',
                'registered.css?v=1', 'Kits/AssetUrlContractAddon/addon.css',
            ] as $style) {
                self::assertStringContainsString(
                    '<link rel="stylesheet" href="/squehub-v2/assets/' . $style . '">', $html);
            }
            foreach ([
                'page.js', 'partial.js', 'component.js',
                'registered.js#module', 'Kits/AssetUrlContractAddon/addon.js',
            ] as $script) {
                self::assertStringContainsString(
                    '<script src="/squehub-v2/assets/' . $script . '"></script>', $html);
            }
            self::assertStringNotContainsString('/squehub-v2/squehub-v2/assets/', $html);
        } finally {
            $project->remove();
        }
    }

    public function testRawPhpErrorTemplatesUseTheirOwningApplicationMount(): void
    {
        $rootProject = new TemporaryProject();
        $mountedProject = new TemporaryProject();
        try {
            foreach ([$rootProject, $mountedProject] as $project) {
                foreach ([404, 500] as $status) {
                    $source = file_get_contents(dirname(__DIR__, 2)
                        . '/Project/Views/Default/Error/' . $status . '.php');
                    self::assertIsString($source);
                    $project->write('Project/Views/Default/Error/' . $status . '.php', $source);
                }
            }
            $root = $this->application($rootProject, '');
            $mounted = $this->application($mountedProject, '/squehub-v2');
            // A raw PHP error include belongs to the handler's Application,
            // even if a different Application is selected for View helpers.
            RuntimeContext::select($root);

            foreach ([
                [$root, '/assets/default/favicon/squehub-icon.png'],
                [$mounted, '/squehub-v2/assets/default/favicon/squehub-icon.png'],
            ] as [$app, $favicon]) {
                $handler = new ExceptionHandler($app);
                $missing = $handler->render(new NotFoundHttpException(),
                    new Request('GET', '/missing'));
                self::assertSame(404, $missing->status());
                self::assertStringContainsString('href="' . $favicon . '"',
                    $missing->content());
                $failure = $handler->render(new RuntimeException('private detail'),
                    new Request('GET', '/failure'));
                self::assertSame(500, $failure->status());
                self::assertStringContainsString('href="' . $favicon . '"',
                    $failure->content());
            }
        } finally {
            $rootProject->remove();
            $mountedProject->remove();
        }
    }

    public function testShippedManifestIconsStayUnderTheAssetMount(): void
    {
        $root = dirname(__DIR__, 2);
        $manifestUrl = (new UrlBasePath('/squehub-v2'))
            ->assetUrl('/assets/default/favicon/site.webmanifest');
        foreach (['public/assets/default/favicon', 'Assets/Default/Favicon'] as $directory) {
            $json = file_get_contents($root . '/' . $directory . '/site.webmanifest');
            self::assertIsString($json);
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($manifest['icons'] ?? null);
            foreach ($manifest['icons'] as $icon) {
                self::assertIsString($icon['src'] ?? null);
                self::assertStringStartsNotWith('/', $icon['src']);
                self::assertFileExists($root . '/' . $directory . '/' . $icon['src']);
                self::assertSame('/squehub-v2/assets/default/favicon/' . $icon['src'],
                    substr($manifestUrl, 0, strrpos($manifestUrl, '/') + 1) . $icon['src']);
            }
        }
    }

    private function application(TemporaryProject $project, string $mount): Application
    {
        $project->write('Config/App.php', '<?php return [\'env\' => \'testing\'];');
        $project->write('Config/Http.php',
            '<?php return [\'base_path\' => ' . var_export($mount, true) . '];');
        $app = new Application($project->path());
        $app->bootstrap();
        return $app;
    }
}
