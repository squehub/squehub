<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Frontend\AssetManifest;
use App\Frontend\FrontendException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real build files and a Vite manifest remain one deployable unit. */
final class AssetManifestTest extends TestCase
{
    public function testResourcesResolveCssAndStaticImportsUnderBuildRoot(): void
    {
        $project = new TemporaryProject();
        try {
            $this->build($project);
            $manifest = new AssetManifest($project->path(), 'public/assets/build');
            self::assertSame([
                'file' => '/assets/build/assets/main-a1.js',
                'css' => ['/assets/build/assets/shared-b2.css',
                    '/assets/build/assets/main-c3.css'],
                'imports' => ['/assets/build/assets/shared-d4.js'],
            ], $manifest->resources('src/main.jsx'));
            self::assertSame('assets/main-a1.js',
                $manifest->find('src/main.jsx')['file']);
            self::assertNull($manifest->find('src/unknown.js'));
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D',
                $manifest->fingerprint());
        } finally {
            $project->remove();
        }
    }

    public function testChangedManifestInvalidatesTheSameReaderAndMissingOutputFails(): void
    {
        $project = new TemporaryProject();
        try {
            $this->build($project);
            $manifest = new AssetManifest($project->path(), 'public/assets/build');
            $before = $manifest->fingerprint();
            $project->write('public/assets/build/assets/main-e5.js', 'replacement');
            $this->writeManifest($project, 'assets/main-e5.js');
            self::assertSame('/assets/build/assets/main-e5.js',
                $manifest->resources('src/main.jsx')['file']);
            self::assertNotSame($before, $manifest->fingerprint());
            unlink($project->path('public/assets/build/assets/main-e5.js'));
            $this->expectException(FrontendException::class);
            $manifest->resources('src/main.jsx');
        } finally {
            $project->remove();
        }
    }

    public function testInvalidManifestCannotBecomeAFreshOrExternalAsset(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/build/.vite/manifest.json', '{broken');
            $manifest = new AssetManifest($project->path(), 'public/assets/build');
            try {
                $manifest->fingerprint();
                self::fail('Corrupt manifest must fail.');
            } catch (FrontendException $error) {
                self::assertStringNotContainsString($project->path(), $error->getMessage());
            }
            $project->write('public/assets/build/.vite/manifest.json',
                '{"src/main.js":{"file":"../outside.js","isEntry":true}}');
            $this->expectException(FrontendException::class);
            (new AssetManifest($project->path(), 'public/assets/build'))
                ->resources('src/main.js');
        } finally {
            $project->remove();
        }
    }

    public function testManifestRejectsMissingImportAndCaseCollision(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/build/assets/main.js', 'module');
            $project->write('public/assets/build/.vite/manifest.json',
                '{"src/main.js":{"file":"assets/main.js","isEntry":true,'
                . '"imports":["missing.js"]}}');
            try {
                (new AssetManifest($project->path(), 'public/assets/build'))
                    ->resources('src/main.js');
                self::fail('Missing import must fail.');
            } catch (FrontendException) {
            }
            $project->write('public/assets/build/.vite/manifest.json',
                '{"src/main.js":{"file":"assets/main.js","isEntry":true},'
                . '"SRC/MAIN.JS":{"file":"assets/main.js","isEntry":true}}');
            $this->expectException(FrontendException::class);
            (new AssetManifest($project->path(), 'public/assets/build'))
                ->resources('src/main.js');
        } finally {
            $project->remove();
        }
    }

    public function testManifestOutputMustMatchThePhysicalFilenameCase(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/build/assets/main.js', 'module');
            $project->write('public/assets/build/.vite/manifest.json',
                '{"src/main.js":{"file":"assets/Main.js","isEntry":true}}');
            $this->expectException(FrontendException::class);
            (new AssetManifest($project->path(), 'public/assets/build'))
                ->resources('src/main.js');
        } finally {
            $project->remove();
        }
    }

    public function testManifestCannotFollowALinkedOutput(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('outside.js', 'outside module');
            $project->write('public/assets/build/.vite/manifest.json',
                '{"src/main.js":{"file":"assets/main.js","isEntry":true}}');
            $path = $project->path('public/assets/build/assets/main.js');
            mkdir(dirname($path), 0777, true);
            if (!@symlink($project->path('outside.js'), $path)) {
                self::markTestSkipped('This host cannot create file symlinks.');
            }
            $this->expectException(FrontendException::class);
            (new AssetManifest($project->path(), 'public/assets/build'))
                ->resources('src/main.js');
        } finally {
            $project->remove();
        }
    }

    public function testManifestCannotSelectAnExecutablePhpOutput(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('public/assets/build/assets/entry.php', '<?php echo 1;');
            $project->write('public/assets/build/.vite/manifest.json',
                '{"src/main.js":{"file":"assets/entry.php","isEntry":true}}');
            $this->expectException(FrontendException::class);
            (new AssetManifest($project->path(), 'public/assets/build'))
                ->resources('src/main.js');
        } finally {
            $project->remove();
        }
    }

    public function testUnchangedManifestRechecksAssetsAndDynamicImports(): void
    {
        foreach (['assets/logo.svg', 'assets/lazy.js'] as $removed) {
            $project = new TemporaryProject();
            try {
                $project->write('public/assets/build/assets/main.js', 'module');
                $project->write('public/assets/build/assets/logo.svg', '<svg/>');
                $project->write('public/assets/build/assets/lazy.js', 'module');
                $project->write('public/assets/build/.vite/manifest.json', json_encode([
                    'src/main.js' => [
                        'file' => 'assets/main.js', 'isEntry' => true,
                        'assets' => ['assets/logo.svg'],
                        'dynamicImports' => ['src/lazy.js'],
                    ],
                    'src/lazy.js' => ['file' => 'assets/lazy.js',
                        'isDynamicEntry' => true],
                ], JSON_THROW_ON_ERROR));
                $manifest = new AssetManifest($project->path(), 'public/assets/build');
                self::assertSame('/assets/build/assets/main.js',
                    $manifest->resources('src/main.js')['file']);
                unlink($project->path('public/assets/build/' . $removed));
                try {
                    $manifest->resources('src/main.js');
                    self::fail('Cached manifest accepted a missing output.');
                } catch (FrontendException $error) {
                    self::assertStringNotContainsString($project->path(),
                        $error->getMessage());
                }
            } finally {
                $project->remove();
            }
        }
    }

    private function build(TemporaryProject $project): void
    {
        foreach (['main-a1.js', 'shared-d4.js', 'shared-b2.css', 'main-c3.css'] as $file) {
            $project->write('public/assets/build/assets/' . $file, $file);
        }
        $this->writeManifest($project, 'assets/main-a1.js');
    }

    private function writeManifest(TemporaryProject $project, string $entry): void
    {
        $project->write('public/assets/build/.vite/manifest.json', json_encode([
            '_shared.js' => ['file' => 'assets/shared-d4.js',
                'css' => ['assets/shared-b2.css']],
            'src/main.jsx' => ['file' => $entry, 'isEntry' => true,
                'imports' => ['_shared.js'], 'css' => ['assets/main-c3.css']],
        ], JSON_THROW_ON_ERROR));
    }
}
