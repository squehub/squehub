<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevProcess;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Real PHP built-in server requests qualify Studio's Host and router boundary. */
final class StudioLoopbackHttpTest extends TestCase
{
    public function testLocalHttpBoundaryAndLiveConfiguration(): void
    {
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($probe === false) {
            self::markTestSkipped('A loopback socket is required for Studio HTTP qualification.');
        }
        $address = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $project = TestApplication::temporary([
            'app' => ['env' => 'development', 'debug' => true],
            'studio' => ['enabled' => true],
            'http' => ['base_path' => '/nested/path'],
            'redis' => ['default' => 'main', 'client' => 'auto',
                'connections' => ['main' => ['host' => null, 'port' => 6379]]],
        ]);
        $root = dirname(__DIR__, 2);
        $process = null;
        try {
            // Keep the real Bootstrap/App.php provider list while retaining the
            // fixture's SQLite/array settings and inert external transports.
            foreach (glob($root . '/Config/*.php') ?: [] as $source) {
                $destination = $project->path('Config/' . basename($source));
                if (!is_file($destination)) {
                    file_put_contents($destination, (string) file_get_contents($source));
                }
            }
            $project->write('vendor/autoload.php', '<?php require_once '
                . var_export($root . '/vendor/autoload.php', true) . ';');
            $project->write('App/Core/Helper.php', '<?php require_once '
                . var_export($root . '/App/Core/Helper.php', true) . ';');
            $project->write('Bootstrap/App.php',
                (string) file_get_contents($root . '/Bootstrap/App.php'));
            $project->write('Bootstrap/StudioServer.php',
                (string) file_get_contents($root . '/Bootstrap/StudioServer.php'));
            $project->write('Project/Views/Studio/Dashboard.squehub.php',
                (string) file_get_contents($root . '/Project/Views/Studio/Dashboard.squehub.php'));
            $project->write('public/assets/studio.css',
                (string) file_get_contents($root . '/public/assets/studio.css'));
            $project->write('public/assets/default/favicon/squehub-icon.png',
                (string) file_get_contents($root . '/public/assets/default/favicon/squehub-icon.png'));
            $project->write('public/index.php', '<?php echo "APP_INDEX_SENTINEL";');
            $project->write('public/private.php', '<?php echo "PRIVATE_SOURCE_SENTINEL";');
            $project->write('Project/Routes/web.php', '<?php '
                . '\\App\\Plugins\\Route::path("/")->get(static function (): void {'
                . ' file_put_contents(' . var_export($project->path('route-executed.txt'), true)
                . ', "executed"); })->named("welcome.page");');

            $process = new DevProcess([PHP_BINARY, '-S', '127.0.0.1:' . $port,
                '-t', $project->path('public'), $project->path('Bootstrap/StudioServer.php')],
                $project->root());
            $process->start();
            $this->waitForServer($process, $port);

            $page = $this->request($port, 'GET', '/nested/path/studio');
            self::assertStringContainsString('200 OK', $page);
            self::assertStringContainsString('SqueHub Studio', $page);
            self::assertStringContainsString('/nested/path/assets/studio.css', $page);
            self::assertStringContainsString('/nested/path/assets/default/favicon/squehub-icon.png',
                $page);
            self::assertStringContainsString("default-src 'none'", $page);
            self::assertStringContainsString('no-store, private', $page);
            self::assertFileDoesNotExist($project->path('route-executed.txt'));

            $routes = $this->request($port, 'GET', '/nested/path/studio/routes');
            self::assertStringContainsString('200 OK', $routes);
            self::assertStringContainsString('welcome.page', $routes);
            self::assertStringContainsString('Closure', $routes);
            self::assertStringNotContainsString('No records available', $routes);
            self::assertFileDoesNotExist($project->path('route-executed.txt'));

            $css = $this->request($port, 'GET', '/nested/path/assets/studio.css');
            self::assertStringContainsString('200 OK', $css);
            self::assertStringContainsString('text/css', $css);
            self::assertStringContainsString('.shell', $css);
            $head = $this->request($port, 'HEAD', '/nested/path/assets/studio.css');
            self::assertStringContainsString('200 OK', $head);
            self::assertStringNotContainsString('.shell', $head);
            $icon = $this->request($port, 'GET',
                '/nested/path/assets/default/favicon/squehub-icon.png');
            self::assertStringContainsString('200 OK', $icon);
            self::assertStringContainsString('image/png', $icon);
            self::assertStringContainsString("\x89PNG\r\n\x1a\n", $icon);
            self::assertStringContainsString('404 Not Found', $this->request($port,
                'GET', '/nested/path/assets/default/favicon/other.png'));

            self::assertStringContainsString('403 Forbidden', $this->request($port,
                'GET', '/nested/path/studio', 'localhost:' . $port));
            self::assertStringContainsString('405 Method Not Allowed', $this->request($port,
                'POST', '/nested/path/studio'));
            foreach (['/nested/path/private.php', '/nested/path/index.php',
                '/nested/path/assets/other.css', '/studio'] as $path) {
                $denied = $this->request($port, 'GET', $path);
                self::assertStringContainsString('404 Not Found', $denied, $path);
                self::assertStringNotContainsString('PRIVATE_SOURCE_SENTINEL', $denied);
                self::assertStringNotContainsString('APP_INDEX_SENTINEL', $denied);
            }
            self::assertFileDoesNotExist($project->path('route-executed.txt'));

            $project->configure(['app' => ['env' => 'production', 'debug' => true]]);
            self::assertStringContainsString('404 Not Found',
                $this->request($port, 'GET', '/nested/path/studio'));
            $project->configure(['app' => ['env' => 'development', 'debug' => true],
                'studio' => ['enabled' => false]]);
            self::assertStringContainsString('404 Not Found',
                $this->request($port, 'GET', '/nested/path/studio'));
        } finally {
            if ($process !== null) $process->terminate(static function (string $type, string $data): void {}, 1.0);
            $project->cleanup();
        }
    }

    private function waitForServer(DevProcess $process, int $port): void
    {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            if (!$process->poll(static function (string $type, string $data): void {})) {
                self::fail('Studio loopback server exited before its first request.');
            }
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port,
                $error, $message, 0.05);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        self::fail('Studio loopback server did not start within three seconds.');
    }

    private function request(int $port, string $method, string $path, ?string $host = null): string
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port,
            $error, $message, 2);
        self::assertIsResource($socket, 'Studio loopback connection failed.');
        stream_set_timeout($socket, 1);
        fwrite($socket, $method . ' ' . $path . " HTTP/1.0\r\nHost: "
            . ($host ?? '127.0.0.1:' . $port) . "\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($response);
        return $response;
    }
}
