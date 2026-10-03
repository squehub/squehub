<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\EnvironmentSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** The local router sends private paths to the application, never to a file server. */
final class DevelopmentServerSecurityTest extends TestCase
{
    public function testStaticAssetIsTheOnlyDirectFileResponse(): void
    {
        $asset = $this->route('/assets/default/img/logo-icon.png');
        self::assertSame(0, $asset->getExitCode(), $asset->getErrorOutput());
        $output = $asset->getOutput();
        self::assertStringEndsWith('__STATUS__200', $output);
        self::assertSame(
            hash_file('sha256', dirname(__DIR__, 2) . '/public/assets/default/img/logo-icon.png'),
            hash('sha256', substr($output, 0, -strlen('__STATUS__200')))
        );

        // A PHP file placed under assets must go through the application,
        // even though the development server can execute PHP files itself.
        $php = $this->route('/assets/example.php');
        self::assertSame(0, $php->getExitCode(), $php->getErrorOutput());
        self::assertStringContainsString('__STATUS__' . ($this->setupRequired() ? '503' : '404'), $php->getOutput());
    }

    public function testRepositoryPathsNeverExposeSourceOrTrace(): void
    {
        foreach ([
            '/.env',
            '/composer.json',
            '/App/Clis/Clis.php',
            '/Bootstrap/DevelopmentServer.php',
            '/.htaccess',
            '/%2e%2e/squehub',
        ] as $path) {
            $response = $this->route($path);
            self::assertSame(0, $response->getExitCode(), $path . ': ' . $response->getErrorOutput());
            self::assertStringContainsString('__STATUS__' . ($this->setupRequired() ? '503' : '404'),
                $response->getOutput(), $path);
            self::assertStringContainsString($this->setupRequired()
                ? 'SqueHub setup required' : 'Oops! Page Not Found', $response->getOutput(), $path);
            self::assertStringNotContainsString("require_once __DIR__", $response->getOutput(), $path);
            self::assertStringNotContainsString('RouteMatcher.php', $response->getOutput(), $path);
            if ($this->setupRequired()) {
                self::assertStringNotContainsString('Squehub Debug Bar', $response->getOutput(), $path);
            } else {
                self::assertStringContainsString('Squehub Debug Bar', $response->getOutput(), $path);
            }
        }
    }

    public function testJson404KeepsStructuredBodyWithoutDebugBar(): void
    {
        $response = $this->route('/Bootstrap/DevelopmentServer.php', true);
        self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
        self::assertStringContainsString($this->setupRequired()
            ? json_encode(['message' => EnvironmentSetup::notice(dirname(__DIR__, 2))], JSON_THROW_ON_ERROR)
            : '{"error":"Not Found"}', $response->getOutput());
        self::assertStringContainsString('__STATUS__' . ($this->setupRequired() ? '503' : '404'), $response->getOutput());
        self::assertStringNotContainsString('Squehub Debug Bar', $response->getOutput());
    }

    public function testSquehubCommandPathNeverServesSource(): void
    {
        // Applications may deliberately register /squehub as a route; its
        // response must still come from the application, not the CLI script.
        $response = $this->route('/squehub');
        self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
        self::assertStringNotContainsString("require_once __DIR__", $response->getOutput());
    }

    public function testApplicationDebugSettingControlsLegacyBarDespitePredefinedConstant(): void
    {
        foreach (['/', '/.env'] as $path) {
            $response = $this->route($path, false, 'false', true);
            self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
            self::assertStringNotContainsString('Squehub Debug Bar', $response->getOutput(), $path);
            self::assertStringNotContainsString('DEBUG MODE: ON', $response->getOutput(), $path);
        }

        $response = $this->route('/.env', false, 'true', false);
        self::assertSame(0, $response->getExitCode(), $response->getErrorOutput());
        if ($this->setupRequired()) {
            self::assertStringNotContainsString('Squehub Debug Bar', $response->getOutput());
        } else {
            self::assertStringContainsString('Squehub Debug Bar', $response->getOutput());
        }
    }

    private function setupRequired(): bool
    {
        return EnvironmentSetup::status(dirname(__DIR__, 2)) !== null;
    }

    /** Execute one router request without leaving a development server running. */
    private function route(
        string $path,
        bool $json = false,
        string $debug = 'true',
        ?bool $legacyDebug = null
    ): Process
    {
        $root = dirname(__DIR__, 2);
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path];
        if ($json) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }
        $code = ($legacyDebug === null ? '' : 'define("DEBUG_MODE", ' . ($legacyDebug ? 'true' : 'false') . '); ')
            . '$_SERVER=' . var_export($server, true) . '; '
            . '$result=require ' . var_export($root . '/Bootstrap/DevelopmentServer.php', true) . '; '
            . 'echo $result === false ? "STATIC" : "__STATUS__" . http_response_code();';
        $process = new Process([
            PHP_BINARY, '-d', 'session.save_path=' . sys_get_temp_dir(), '-r', $code,
        ], $root, [
            'APP_DEBUG' => $debug,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
        ]);
        $process->run();
        return $process;
    }
}
