<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevException;
use App\Dev\DevelopmentServer;
use App\Studio\StudioHttp;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Studio's separate HTTP boundary never delegates to application routes or static fallback. */
final class StudioHttpTest extends TestCase
{
    private ?TestApplication $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanup();
        $this->project = null;
    }

    public function testDisabledAndNonDevelopmentApplicationsDoNotExposeStudio(): void
    {
        $disabled = $this->fixture(['app' => ['env' => 'development', 'debug' => true],
            'studio' => ['enabled' => false]]);
        $disabledMarker = $this->project->path('disabled-route-registered.txt');
        $this->project->write('Project/Routes/Web.php', '<?php file_put_contents('
            . var_export($disabledMarker, true) . ', "registered");');
        self::assertSame(404, (new StudioHttp($disabled))->handle('GET', '/studio',
            '127.0.0.1', '127.0.0.1:8100', 8100)->status());
        self::assertFileDoesNotExist($disabledMarker);
        $this->project?->cleanup();
        $this->project = null;

        $production = $this->fixture(['app' => ['env' => 'production', 'debug' => true],
            'studio' => ['enabled' => true]]);
        self::assertNotNull($this->project);
        $productionMarker = $this->project->path('production-route-registered.txt');
        $this->project->write('Project/Routes/Web.php', '<?php file_put_contents('
            . var_export($productionMarker, true) . ', "registered");');
        self::assertSame(404, (new StudioHttp($production))->handle('GET', '/studio',
            '127.0.0.1', '127.0.0.1:8100', 8100)->status());
        self::assertFileDoesNotExist($productionMarker);
    }

    public function testPeerHostMethodAndFixedPathAreEnforced(): void
    {
        $app = $this->fixture();
        $http = new StudioHttp($app);
        self::assertSame(403, $http->handle('GET', '/studio', '192.0.2.1',
            '127.0.0.1:8100', 8100)->status());
        self::assertSame(403, $http->handle('GET', '/studio', '127.0.0.1',
            'localhost:8100', 8100)->status());
        self::assertSame(403, $http->handle('GET', '/studio', '127.0.0.1',
            '127.0.0.1:8101', 8100)->status());
        $post = $http->handle('POST', '/studio', '127.0.0.1', '127.0.0.1:8100', 8100);
        self::assertSame(405, $post->status());
        self::assertSame('GET, HEAD', $post->header('Allow'));
        self::assertSame(404, $http->handle('GET', '/index.php', '127.0.0.1',
            '127.0.0.1:8100', 8100)->status());
        self::assertSame(404, $http->handle('GET', '/assets/secret.txt', '127.0.0.1',
            '127.0.0.1:8100', 8100)->status());
    }

    public function testMountedOverviewEscapesApplicationTextAndUsesMountedAsset(): void
    {
        $app = $this->fixture(['app' => ['name' => '<script>secret</script>'],
            'http' => ['base_path' => '/nested/path']]);
        $http = new StudioHttp($app);
        self::assertSame(404, $http->handle('GET', '/studio', '127.0.0.1',
            '127.0.0.1:8100', 8100)->status());
        $page = $http->handle('GET', '/nested/path/studio', '127.0.0.1',
            '127.0.0.1:8100', 8100);
        self::assertSame(200, $page->status(), $page->content());
        self::assertStringContainsString('&lt;script&gt;secret&lt;/script&gt;', $page->content());
        self::assertStringNotContainsString('<script>secret</script>', $page->content());
        self::assertStringContainsString('/nested/path/assets/studio.css', $page->content());
        self::assertStringContainsString('/nested/path/assets/default/favicon/squehub-icon.png',
            $page->content());
        self::assertStringContainsString('/nested/path/studio/routes', $page->content());
        self::assertStringContainsString("default-src 'none'", (string) $page->header('Content-Security-Policy'));
        self::assertSame('no-store, private', $page->header('Cache-Control'));
        $asset = $http->handle('HEAD', '/nested/path/assets/studio.css', '127.0.0.1',
            '127.0.0.1:8100', 8100);
        self::assertSame(200, $asset->status());
        self::assertSame('text/css; charset=UTF-8', $asset->header('Content-Type'));
        $icon = $http->handle('GET', '/nested/path/assets/default/favicon/squehub-icon.png',
            '127.0.0.1', '127.0.0.1:8100', 8100);
        self::assertSame(200, $icon->status());
        self::assertSame('image/png', $icon->header('Content-Type'));
        self::assertSame(file_get_contents(dirname(__DIR__, 2)
            . '/public/assets/default/favicon/squehub-icon.png'), $icon->content());
        self::assertSame(404, $http->handle('GET',
            '/nested/path/assets/default/favicon/other.png', '127.0.0.1',
            '127.0.0.1:8100', 8100)->status());
    }

    public function testRoutesPageShowsRegisteredClosureWithoutInvokingIt(): void
    {
        $app = $this->fixture();
        $marker = $this->project->path('handler-executed.txt');
        $this->project->write('Project/Routes/Web.php', '<?php echo "ROUTE_REGISTRATION_OUTPUT_SENTINEL"; '
            . '\\App\\Plugins\\Route::path("/")->get(static function (): void {'
            . ' file_put_contents(' . var_export($marker, true) . ', "executed");'
            . ' })->named("welcome.page");');

        $page = (new StudioHttp($app))->handle('GET', '/studio/routes',
            '127.0.0.1', '127.0.0.1:8100', 8100);
        self::assertSame(200, $page->status(), $page->content());
        self::assertStringContainsString('welcome.page', $page->content());
        self::assertStringContainsString('Closure', $page->content());
        self::assertStringContainsString('GET', $page->content());
        self::assertStringNotContainsString('No records available', $page->content());
        self::assertStringNotContainsString('ROUTE_REGISTRATION_OUTPUT_SENTINEL', $page->content());
        self::assertFileDoesNotExist($marker);
    }

    public function testStudioServerPreparationUsesSeparateLoopbackRouterAndMountedUrl(): void
    {
        $app = $this->fixture(['http' => ['base_path' => '/nested/path']]);
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) {
            self::markTestSkipped('A loopback socket is needed for Studio port qualification.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $server = DevelopmentServer::prepareStudio($app, (string) $port);
        self::assertSame('http://127.0.0.1:' . $port . '/nested/path/studio', $server->url());
        self::assertStringContainsString('Bootstrap/StudioServer.php', $server->process()->getCommandLine());
        self::assertFalse($server->process()->isStarted());
        $this->expectException(DevException::class);
        DevelopmentServer::prepareStudio($app, '0');
    }

    /** @param array<string,array<string,mixed>> $changes */
    private function fixture(array $changes = []): \App\Foundation\Application
    {
        $project = $this->project = TestApplication::temporary(array_replace_recursive([
            'app' => ['env' => 'development', 'debug' => false],
            'studio' => ['enabled' => true],
        ], $changes));
        $root = dirname(__DIR__, 2);
        $project->write('Project/Views/Studio/Dashboard.squehub.php',
            (string) file_get_contents($root . '/Project/Views/Studio/Dashboard.squehub.php'));
        $project->write('public/assets/studio.css',
            (string) file_get_contents($root . '/public/assets/studio.css'));
        $project->write('public/assets/default/favicon/squehub-icon.png',
            (string) file_get_contents($root . '/public/assets/default/favicon/squehub-icon.png'));
        $project->write('public/index.php', '<?php echo "APP_SENTINEL";');
        $project->write('Bootstrap/StudioServer.php', '<?php echo "STUDIO_ROUTER";');
        return $project->application();
    }
}
