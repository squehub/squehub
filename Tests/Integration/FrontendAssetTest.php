<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Core\View;
use App\Foundation\Application;
use App\Frontend\FrontendException;
use App\Frontend\FrontendManager;
use App\Packages\PackageManager;
use App\Support\RuntimeContext;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Native modules and Vite entries share the existing View asset lifecycle. */
final class FrontendAssetTest extends TestCase
{
    public function testNativeImportMapAndModuleFollowBasePathAndRenderOwner(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/js/app.mjs', 'export const ready = true;');
            $this->views($project);
            $app = $this->application($project, 'development', '/app', [
                'adapter' => 'none', 'entries' => ['app' => '/assets/js/app.mjs'],
                'imports' => ['@app/' => '/assets/js/'],
            ]);
            RuntimeContext::select($app);
            $html = View::renderResult('Pages.App')->html();
            self::assertStringContainsString('<script type="importmap">', $html);
            self::assertStringContainsString('"@app/":"/app/assets/js/"', $html);
            self::assertStringContainsString(
                '<script type="module" src="/app/assets/js/app.mjs"></script>', $html);
            self::assertSame(1, substr_count($html, 'type="importmap"'));
            self::assertSame(1, substr_count($html, 'src="/app/assets/js/app.mjs"'));
        } finally {
            $project->remove();
        }
    }

    public function testNativeEntryRequiresAnExistingPhysicalFile(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, 'development', '', [
                'adapter' => 'none', 'entries' => ['app' => '/assets/js/missing.mjs'],
            ]);
            $this->expectException(FrontendException::class);
            $app->container()->make(FrontendManager::class)->entryTags('app');
        } finally {
            $project->remove();
        }
    }

    public function testNativeEntryRejectsAMisCasedPhysicalFilename(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/js/main.mjs', 'export {};');
            $app = $this->application($project, 'development', '', [
                'adapter' => 'none', 'entries' => ['app' => '/assets/js/Main.mjs'],
            ]);
            $this->expectException(FrontendException::class);
            $app->container()->make(FrontendManager::class)->entryTags('app');
        } finally {
            $project->remove();
        }
    }

    public function testImportMapEscapesScriptDelimitersAndRejectsAliasCollisions(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/js/app.mjs', 'export {};');
            $app = $this->application($project, 'development', '', [
                'adapter' => 'none', 'entries' => ['app' => '/assets/js/app.mjs'],
                'imports' => ['safe' => 'https://cdn.example.test/m.js?x=</script>'],
            ]);
            $manager = $app->container()->make(FrontendManager::class);
            $map = $manager->entryTags('app')['head'][0];
            self::assertStringContainsString('\\u003C/script\\u003E', $map);
            self::assertSame(1, substr_count($map, '</script>'));

            $app->config()->set('frontend.imports', [
                'Shop' => '/assets/js/app.mjs',
                'shop' => '/assets/js/app.mjs',
            ]);
            $this->expectException(FrontendException::class);
            $manager->entryTags('app');
        } finally {
            $project->remove();
        }
    }

    public function testDevelopmentViteUsesOnlyConfiguredLoopbackOrigin(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, 'development', '/app', [
                'adapter' => 'vite', 'entries' => ['app' => 'src/main.jsx'],
                'development' => ['enabled' => true,
                    'url' => 'http://127.0.0.1:5182'],
            ]);
            $tags = $app->container()->make(FrontendManager::class)->entryTags('app');
            self::assertSame([], $tags['head']);
            self::assertSame([], $tags['styles']);
            self::assertSame([
                '<script type="module" src="http://127.0.0.1:5182/@vite/client"></script>',
                '<script type="module" src="http://127.0.0.1:5182/src/main.jsx"></script>',
            ], $tags['scripts']);

            $app->config()->set('frontend.development.url',
                'http://untrusted.example.test:5182');
            $this->expectException(FrontendException::class);
            $app->container()->make(FrontendManager::class)->entryTags('app');
        } finally {
            $project->remove();
        }
    }

    public function testLocalEnvironmentCanUseExplicitDevelopmentVite(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, 'local', '', [
                'adapter' => 'vite', 'entries' => ['app' => 'src/main.js'],
                'development' => ['enabled' => true,
                    'url' => 'http://localhost:5174'],
            ]);
            $tags = $app->container()->make(FrontendManager::class)->entryTags('app');
            self::assertStringContainsString('http://localhost:5174/@vite/client',
                $tags['scripts'][0]);
        } finally {
            $project->remove();
        }
    }

    public function testProductionViteUsesManifestCssImportsAndNoHmr(): void
    {
        $project = new TemporaryProject();
        try {
            $this->views($project);
            $project->write('public/assets/build/assets/main-a1.js', 'module');
            $project->write('public/assets/build/assets/main-a1.css', 'body{}');
            $project->write('public/assets/build/assets/shared-b2.js', 'module');
            $project->write('public/assets/build/.vite/manifest.json', json_encode([
                '_shared.js' => ['file' => 'assets/shared-b2.js'],
                'src/main.jsx' => ['file' => 'assets/main-a1.js',
                    'isEntry' => true, 'css' => ['assets/main-a1.css'],
                    'imports' => ['_shared.js']],
            ], JSON_THROW_ON_ERROR));
            $app = $this->application($project, 'production', '/mounted', [
                'adapter' => 'vite', 'entries' => ['app' => 'src/main.jsx'],
                'development' => ['enabled' => true,
                    'url' => 'http://127.0.0.1:5173'],
            ]);
            RuntimeContext::select($app);
            self::assertSame('/mounted/assets/build/assets/main-a1.js',
                \asset('src/main.jsx'));
            self::assertSame('/mounted/assets/logo.png', \asset('/assets/logo.png'));
            $html = View::renderResult('Pages.App')->html();
            self::assertStringContainsString(
                '<link rel="modulepreload" href="/mounted/assets/build/assets/shared-b2.js">',
                $html);
            self::assertStringContainsString(
                '<link rel="stylesheet" href="/mounted/assets/build/assets/main-a1.css">',
                $html);
            self::assertStringContainsString(
                '<script type="module" src="/mounted/assets/build/assets/main-a1.js"></script>',
                $html);
            self::assertStringNotContainsString('@vite/client', $html);
            self::assertStringNotContainsString('localhost', $html);
        } finally {
            $project->remove();
        }
    }

    public function testSelectedProductionViteCannotFallBackToDevelopmentServer(): void
    {
        $project = new TemporaryProject();
        try {
            $app = $this->application($project, 'production', '', [
                'adapter' => 'vite', 'entries' => ['app' => 'src/main.jsx'],
                'development' => ['enabled' => true,
                    'url' => 'http://127.0.0.1:5173'],
            ]);
            $this->expectException(FrontendException::class);
            $app->container()->make(FrontendManager::class)->entryTags('app');
        } finally {
            $project->remove();
        }
    }

    public function testLogicalPackageModuleRequiresAnActiveContainedSource(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/Shop/Shop.php',
                '<?php namespace Packages\\Shop; final class Shop extends '
                . '\\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/Shop/Assets/js/main.mjs',
                'export const shop = true;');
            $app = $this->application($project, 'development', '/app', [
                'adapter' => 'none', 'entries' => ['shop' => 'Shop::js/main.mjs'],
                'imports' => ['@shop/main' => 'Shop::js/main.mjs'],
            ]);
            RuntimeContext::select($app);
            try {
                \asset('Shop::js/main.mjs');
                self::fail('Disabled Package must not resolve a logical asset.');
            } catch (FrontendException) {
            }
            $packages = $app->container()->make(PackageManager::class);
            self::assertTrue($packages->apply($packages->planEnable('Shop'))->complete());
            // Package activation is frozen for an Application boot. A later
            // request/process selects a fresh Application from the new state.
            $app = $this->application($project, 'development', '/app', [
                'adapter' => 'none', 'entries' => ['shop' => 'Shop::js/main.mjs'],
                'imports' => ['@shop/main' => 'Shop::js/main.mjs'],
            ]);
            RuntimeContext::select($app);
            $url = \asset('Shop::js/main.mjs');
            self::assertMatchesRegularExpression(
                '~\A/app/assets/Packages/Shop/js/main\.mjs\?v=[a-f0-9]{16}\z~D',
                $url);
            $tags = $app->container()->make(FrontendManager::class)->entryTags('shop');
            self::assertStringContainsString($url, $tags['scripts'][0]);
            self::assertStringContainsString('"@shop/main":"' . $url . '"',
                $tags['head'][0]);
            $app->config()->set('frontend.imports', ['@shop/' => 'Shop::js/']);
            try {
                $app->container()->make(FrontendManager::class)->entryTags('shop');
                self::fail('Package directory prefix must not be projected.');
            } catch (FrontendException $error) {
                self::assertSame(
                    'Package import-map prefixes are unsupported; use a file alias.',
                    $error->getMessage()
                );
            }
        } finally {
            $project->remove();
        }
    }

    public function testPublishedKitAssetRetainsItsOrdinaryPublicUrl(): void
    {
        $project = new TemporaryProject();
        try {
            // Kit activation publishes files to this existing public target;
            // the asset mapper must not reinterpret them as Package sources.
            $project->write('public/assets/Kits/Checkout/checkout.css',
                'body { color: blue; }');
            $app = $this->application($project, 'production', '/store', [
                'adapter' => 'none',
            ]);
            RuntimeContext::select($app);
            self::assertSame('/store/assets/Kits/Checkout/checkout.css',
                \asset('/assets/Kits/Checkout/checkout.css'));
            self::assertFileExists($project->path(
                'public/assets/Kits/Checkout/checkout.css'));
        } finally {
            $project->remove();
        }
    }

    private function views(TemporaryProject $project): void
    {
        $project->write('Project/Views/Layouts/App.squehub.php',
            '<html><head>@stack(\'head\')@stack(\'styles\')</head>'
            . '<body>@yield(\'content\')@stack(\'scripts\')</body></html>');
        $project->write('Project/Views/Pages/App.squehub.php',
            "@extends('Layouts.App')@frontend('app')@frontend('app')"
            . "@section('content')Ready@endsection");
    }

    /** @param array<string,mixed> $frontend */
    private function application(TemporaryProject $project, string $environment,
        string $mount, array $frontend): Application
    {
        $frontend = array_replace_recursive([
            'adapter' => 'none', 'entries' => [],
            'build' => ['directory' => 'public/assets/build',
                'manifest' => '.vite/manifest.json'],
            'development' => ['enabled' => false,
                'url' => 'http://127.0.0.1:5173'],
            'imports' => [],
            'spa' => ['enabled' => false, 'prefix' => '/',
                'view' => null, 'except' => []],
        ], $frontend);
        $project->write('Config/App.php', '<?php return [\'env\' => '
            . var_export($environment, true) . '];');
        $project->write('Config/Http.php', '<?php return [\'base_path\' => '
            . var_export($mount, true) . '];');
        $project->write('Config/Frontend.php', '<?php return '
            . var_export($frontend, true) . ';');
        $app = new Application($project->path());
        $app->bootstrap();
        return $app;
    }
}
