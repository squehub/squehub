<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Frontend\PackageAssetSource;
use App\Http\FileResponse;
use App\Packages\PackageManager;
use App\Plugins\TestCase;

/** Private Package source is reachable only through an enabled asset owner. */
final class PackageAssetHttpTest extends TestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = 'Commerce' . bin2hex(random_bytes(4));
        $name = $this->package;
        $this->testApplication()->write("Project/Packages/{$name}/{$name}.php",
            '<?php namespace Packages\\' . $name . '; final class ' . $name
            . ' extends \\App\\Plugins\\ServiceProvider {}');
        $this->testApplication()->write("Project/Packages/{$name}/Assets/js/app.mjs",
            'export const enabled = true;');
    }

    public function testEnabledPackageAssetUsesContainedSourceAndNormalFileResponse(): void
    {
        $this->enablePackage();
        $source = new PackageAssetSource($this->app());
        $file = $source->resolve($this->package, 'js/app.mjs');
        self::assertSame($this->testApplication()->path(
            "Project/Packages/{$this->package}/Assets/js/app.mjs"), $file);

        $result = $this->get('/assets/Packages/' . $this->package . '/js/app.mjs')->assertOk();
        self::assertInstanceOf(FileResponse::class, $result->response());
        $result->assertHeader('Content-Type', 'text/javascript; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        ob_start();
        $result->response()->send();
        self::assertSame('export const enabled = true;', ob_get_clean());
    }

    public function testDisabledAndUnsafePackagePathsCannotBeServed(): void
    {
        $base = '/assets/Packages/' . $this->package;
        $this->get($base . '/js/app.mjs')->assertStatus(404);
        $this->get($base . '/js/%2e%2e/app.mjs')->assertStatus(404);
        $this->get($base . '/js/app.php')->assertStatus(404);
        $this->get($base . '/JS/app.mjs')->assertStatus(404);
        $this->get('/assets/Packages/' . strtolower($this->package) . '/js/app.mjs')
            ->assertStatus(404);
        self::assertNull((new PackageAssetSource($this->app()))
            ->resolve($this->package, 'js/app.mjs'));
    }

    public function testPackageAssetRespectsApplicationBasePath(): void
    {
        // The mounted request is normalized by Kernel before this middleware.
        $this->enablePackage();
        $this->testApplication()->configure(['http' => ['base_path' => '/shop']]);
        $this->get('/shop/assets/Packages/' . $this->package . '/js/app.mjs')->assertOk();
        $this->get('/assets/Packages/' . $this->package . '/js/app.mjs')->assertStatus(404);
    }

    public function testLinkedPackageAssetCannotReadOutsideItsPrivateAssetsRoot(): void
    {
        $this->enablePackage();
        $this->app();
        $outside = $this->testApplication()->path('Project/private.mjs');
        $this->testApplication()->write('Project/private.mjs', 'outside-secret');
        $linked = $this->testApplication()->path(
            "Project/Packages/{$this->package}/Assets/js/linked.mjs");
        if (!@symlink($outside, $linked)) {
            self::markTestSkipped('File symlinks are unavailable in this environment.');
        }
        $this->get('/assets/Packages/' . $this->package . '/js/linked.mjs')
            ->assertStatus(404)->assertNotContains('outside-secret');
    }

    private function enablePackage(): void
    {
        $manager = new PackageManager(new Application($this->testApplication()->root()));
        self::assertTrue($manager->apply($manager->planEnable($this->package))->complete());
    }
}
