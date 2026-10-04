<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevException;
use App\Dev\DevelopmentServer;
use App\Foundation\Application;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The shared server specification preserves paths and explicit-port policy. */
final class DevelopmentServerTest extends TestCase
{
    public function testPreparedServerUsesCurrentPhpAndDisposableRootWithSpaces(): void
    {
        $project = new TemporaryProject();
        $root = $project->path('application with spaces');
        self::assertTrue(mkdir($root));
        $project->write('application with spaces/public/index.php', '<?php echo "DEV_INDEX";');
        $project->write('application with spaces/Bootstrap/DevelopmentServer.php',
            '<?php if ($_SERVER["REQUEST_URI"] === "/probe") { echo "DEV_ROUTER_OK"; return true; } return false;');
        $project->write('application with spaces/squehub', '<?php');

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) {
            $project->remove();
            self::markTestSkipped('Local loopback sockets are required for the Dev server qualification.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        try {
            $app = new Application($root);
            $server = DevelopmentServer::prepare($app, '127.0.0.1', (string) $port);
            $process = $server->process();
            self::assertSame('http://127.0.0.1:' . $port, $server->url());
            self::assertNull($server->portNotice());
            self::assertStringContainsString(PHP_BINARY, $process->getCommandLine());
            self::assertStringContainsString('DevelopmentServer.php', $process->getCommandLine());
            self::assertStringContainsString('application with spaces', $process->getCommandLine());
            self::assertSame($app->basePath(), $process->getWorkingDirectory());
            self::assertFalse($process->isStarted());
            $dev = $server->devProcess();
            self::assertSame($app->basePath(), $dev->workingDirectory());
            self::assertSame([
                PHP_BINARY, '-d', $dev->command()[2], '-S', '127.0.0.1:' . $port,
                '-t', $app->publicPath(), $app->basePath('Bootstrap/DevelopmentServer.php'),
            ], $dev->command());
            self::assertStringStartsWith('session.save_path=', $dev->command()[2]);
        } finally {
            $project->remove();
        }
    }

    public function testExplicitOccupiedPortFailsWithoutStartingAChild(): void
    {
        $project = new TemporaryProject();
        $project->write('public/index.php', '<?php echo "ok";');
        $project->write('Bootstrap/DevelopmentServer.php', '<?php return false;');
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) {
            $project->remove();
            self::markTestSkipped('Local loopback sockets are required for the occupied-port test.');
        }
        try {
            $address = stream_socket_get_name($socket, false);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            $this->expectException(DevException::class);
            DevelopmentServer::prepare(new Application($project->path()), '127.0.0.1', (string) $port);
        } finally {
            fclose($socket);
            $project->remove();
        }
    }

}
