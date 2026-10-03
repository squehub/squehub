<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevelopmentServer;
use App\Foundation\Application;
use App\Foundation\EnvironmentSetup;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Mounted local assets pass the guarded public file router, not the PHP app. */
final class BasePathDevelopmentServerTest extends TestCase
{
    public function testPreparedServerDisplaysConfiguredMountInItsUrl(): void
    {
        $project = $this->routerFixture();
        $project->write('Config/Http.php', '<?php return ["base_path" => "/app"];');
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) {
            $project->remove();
            self::markTestSkipped('A local loopback socket is required to prepare the development server.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        try {
            $app = new Application($project->path());
            $app->bootstrap();
            $server = DevelopmentServer::prepare($app, '127.0.0.1', (string) $port);
            self::assertSame('http://127.0.0.1:' . $port . '/app', $server->url());
        } finally {
            $project->remove();
        }
    }

    public function testMountedOrdinaryAssetIsStreamedAndHeadHasNoBody(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = (string) file_get_contents($root . '/public/assets/default/favicon/site.webmanifest');
        $get = $this->route('GET', '/app/assets/default/favicon/site.webmanifest');
        self::assertSame(0, $get->getExitCode(), $get->getErrorOutput());
        self::assertSame($expected . '__STATUS__200', $get->getOutput());

        $head = $this->route('HEAD', '/app/assets/default/favicon/site.webmanifest');
        self::assertSame(0, $head->getExitCode(), $head->getErrorOutput());
        self::assertSame('__STATUS__200', $head->getOutput());
    }

    public function testOutsideMountAndExecutableAssetDoNotBypassApplication(): void
    {
        foreach (['/assets/default/favicon/site.webmanifest', '/app/assets/example.php',
            '/app/assets/%2e%2e/site.webmanifest'] as $path) {
            $response = $this->route('GET', $path);
            self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
            self::assertStringContainsString('__STATUS__' . ($this->setupRequired() ? '503' : '404'),
                $response->getOutput(), $path);
            self::assertStringNotContainsString('require_once __DIR__', $response->getOutput(), $path);
        }
    }

    public function testProjectHttpConfigurationOverridesAConflictingEnvironmentMount(): void
    {
        $root = dirname(__DIR__, 2);
        $project = $this->routerFixture();
        try {
            $project->write('Config/Http.php', '<?php return ["base_path" => "/fixture"];');
            $project->write('public/assets/probe.txt', 'CONFIG_OVERRIDE');
            $response = $this->fixtureRoute($project, '/fixture/assets/probe.txt', '/app');
            self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
            self::assertSame('CONFIG_OVERRIDE__STATUS__200', $response->getOutput());
            self::assertSame((string) file_get_contents($root . '/Bootstrap/DevelopmentServer.php'),
                (string) file_get_contents($project->path('Bootstrap/DevelopmentServer.php')));
        } finally {
            $project->remove();
        }
    }

    public function testReservedPackageAssetUrlCannotBeShadowedByAPublicFile(): void
    {
        $project = $this->routerFixture();
        try {
            $project->write('Config/Http.php', '<?php return ["base_path" => "/fixture"];');
            $project->write('public/assets/Packages/Disabled/js/app.mjs', 'STATIC_COLLISION');
            $response = $this->fixtureRoute($project,
                '/fixture/assets/Packages/Disabled/js/app.mjs', '/app');
            self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
            self::assertSame('APP_FALLBACK__STATUS__404', $response->getOutput());
            self::assertStringNotContainsString('STATIC_COLLISION', $response->getOutput());

            $rewrite = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
            self::assertStringContainsString(
                'RewriteRule ^assets/Packages(?:/|$) index.php [QSA,L]', $rewrite);
            self::assertLessThan(strpos($rewrite, 'RewriteCond %{REQUEST_FILENAME} !-f'),
                strpos($rewrite, 'RewriteRule ^assets/Packages(?:/|$) index.php [QSA,L]'));
        } finally {
            $project->remove();
        }
    }

    public function testMountedStaticAssetCannotFollowLinkOutsidePublicAssets(): void
    {
        $project = $this->routerFixture();
        try {
            $project->write('Config/Http.php', '<?php return ["base_path" => "/fixture"];');
            $project->write('secret.txt', 'SECRET_OUTSIDE_ASSETS');
            $project->write('public/assets/ordinary.txt', 'ordinary');
            $link = $project->path('public/assets/outside.txt');
            if (!function_exists('symlink') || !@symlink($project->path('secret.txt'), $link)) {
                self::markTestSkipped('Creating file links requires Windows Developer Mode or equivalent permission.');
            }
            $response = $this->fixtureRoute($project, '/fixture/assets/outside.txt', '/app');
            self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
            self::assertSame('APP_FALLBACK__STATUS__404', $response->getOutput());
            self::assertStringNotContainsString('SECRET_OUTSIDE_ASSETS', $response->getOutput());
        } finally {
            $project->remove();
        }
    }

    private function routerFixture(): TemporaryProject
    {
        $root = dirname(__DIR__, 2);
        $project = new TemporaryProject();
        $project->write('Bootstrap/DevelopmentServer.php',
            (string) file_get_contents($root . '/Bootstrap/DevelopmentServer.php'));
        $project->write('vendor/autoload.php', '<?php require_once '
            . var_export($root . '/vendor/autoload.php', true) . ';');
        $project->write('public/index.php', '<?php http_response_code(404); echo "APP_FALLBACK";');
        return $project;
    }

    private function fixtureRoute(TemporaryProject $project, string $path, string $environmentBase): Process
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path];
        $code = 'http_response_code(200); $_SERVER=' . var_export($server, true) . '; '
            . '$result=require '
            . var_export($project->path('Bootstrap/DevelopmentServer.php'), true) . '; '
            . 'echo "__STATUS__" . http_response_code();';
        $process = new Process([PHP_BINARY, '-r', $code], $project->path(),
            ['APP_BASE_PATH' => $environmentBase]);
        $process->run();
        return $process;
    }

    private function setupRequired(): bool
    {
        return EnvironmentSetup::status(dirname(__DIR__, 2)) !== null;
    }

    /** Run the development router in a child without binding a listening socket. */
    private function route(string $method, string $path): Process
    {
        $root = dirname(__DIR__, 2);
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path];
        // CLI has no implicit 200 status, whereas PHP's web server does.
        $code = 'http_response_code(200); $_SERVER=' . var_export($server, true) . '; '
            . '$result=require ' . var_export($root . '/Bootstrap/DevelopmentServer.php', true) . '; '
            . 'echo "__STATUS__" . http_response_code();';
        $process = new Process([
            PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-r', $code,
        ], $root, [
            'APP_BASE_PATH' => '/app', 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1',
        ]);
        $process->run();
        return $process;
    }
}
