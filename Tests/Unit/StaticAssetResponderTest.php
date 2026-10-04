<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\UrlBasePath;
use App\Http\FileResponse;
use App\Http\Request;
use App\Http\StaticAssetResponder;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Ordinary assets have one bounded URL despite two compatible source trees. */
final class StaticAssetResponderTest extends TestCase
{
    private TemporaryProject $project;
    private StaticAssetResponder $assets;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->assets = new StaticAssetResponder($this->project->path());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRootAssetsTakePrecedenceAndExposeSafeMetadata(): void
    {
        $this->project->write('Assets/docs/css/site.css', 'ROOT_CSS');
        $this->project->write('public/assets/docs/css/site.css', 'PUBLIC_CSS');

        $response = $this->assets->response(
            new Request('GET', '/assets/docs/css/site.css'), new UrlBasePath());
        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame(200, $response->status());
        self::assertSame('text/css; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('8', $response->header('Content-Length'));
        self::assertSame('inline; filename="site.css"', $response->header('Content-Disposition'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('public, max-age=300', $response->header('Cache-Control'));
        self::assertSame("default-src 'none'; sandbox", $response->header('Content-Security-Policy'));
        self::assertSame('ROOT_CSS', $this->body($response));
    }

    public function testPublicAssetsRemainAvailableWhenRootFileIsAbsent(): void
    {
        $this->project->write('public/assets/build/app.js', 'PUBLIC_BUILD');

        $response = $this->assets->response(
            new Request('GET', '/assets/build/app.js'), new UrlBasePath());
        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame('text/javascript; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('PUBLIC_BUILD', $this->body($response));
    }

    public function testHeadKeepsMetadataWithoutSendingAFileBody(): void
    {
        $this->project->write('Assets/docs/js/docs.js', 'DOCS_JS');

        $response = $this->assets->response(
            new Request('HEAD', '/assets/docs/js/docs.js'), new UrlBasePath());
        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame(200, $response->status());
        self::assertSame('7', $response->header('Content-Length'));
        self::assertSame('', $this->body($response, true));
        self::assertNull($this->assets->response(
            new Request('POST', '/assets/docs/js/docs.js'), new UrlBasePath()));
    }

    public function testConfiguredMountIsRequiredBeforeRootAssetCanResolve(): void
    {
        $this->project->write('Assets/docs/images/icon.png', 'PNG');
        $mount = new UrlBasePath('/app');

        $response = $this->assets->response(
            new Request('GET', '/app/assets/docs/images/icon.png'), $mount);
        self::assertInstanceOf(FileResponse::class, $response);
        self::assertSame('image/png', $response->header('Content-Type'));
        self::assertSame('PNG', $this->body($response));
        self::assertNull($this->assets->response(
            new Request('GET', '/assets/docs/images/icon.png'), $mount));
        self::assertNull($this->assets->response(
            new Request('GET', '/application/assets/docs/images/icon.png'), $mount));
    }

    public function testPackageAndUnsafePathsCannotResolveFromEitherTree(): void
    {
        $this->project->write('Assets/Packages/Disabled/js/app.js', 'ROOT_PACKAGE');
        $this->project->write('public/assets/Packages/Disabled/js/app.js', 'PUBLIC_PACKAGE');
        $this->project->write('Assets/docs/js/app.php', 'EXECUTABLE');
        $this->project->write('Assets/docs/.hidden.css', 'HIDDEN');
        $this->project->write('Assets/docs/js/App.js', 'CASE_SENSITIVE');

        foreach ([
            '/assets/Packages/Disabled/js/app.js',
            '/assets/packages/Disabled/js/app.js',
            '/assets/PACKAGES/Disabled/js/app.js',
            '/assets/docs/js/app.php',
            '/assets/docs/.hidden.css',
            '/assets/docs/../docs/js/App.js',
            '/assets/docs/%2e%2e/docs/js/App.js',
            '/assets/docs\\js\\App.js',
            '/assets/docs/js/app.js',
        ] as $path) {
            self::assertNull($this->assets->response(new Request('GET', $path),
                new UrlBasePath()), $path);
        }
    }

    public function testLinkedFileCannotExposeAFileOutsideAssets(): void
    {
        $this->project->write('secret.css', 'PRIVATE_CSS');
        $this->project->write('Assets/docs/ordinary.css', 'ORDINARY');
        $link = $this->project->path('Assets/docs/leak.css');
        if (!function_exists('symlink') || !@symlink($this->project->path('secret.css'), $link)) {
            self::markTestSkipped('Creating file links requires Windows Developer Mode or equivalent permission.');
        }

        self::assertNull($this->assets->response(
            new Request('GET', '/assets/docs/leak.css'), new UrlBasePath()));
    }

    private function body(FileResponse $response, bool $head = false): string
    {
        ob_start();
        try {
            $response->send($head);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
