<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevSupervisor;
use App\Dev\DevProcess;
use App\Dev\DevelopmentServer;
use App\Foundation\Application;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Console\Output\BufferedOutput;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real PHP children qualify Dev output, failure propagation, and shutdown on Windows. */
final class DevSupervisorTest extends TestCase
{
    public function testChildFailureStopsItsPeerAndForwardsBothStreams(): void
    {
        $project = new TemporaryProject();
        $root = $project->path('path with spaces');
        self::assertTrue(mkdir($root));
        $server = $this->child($root, 'server', 'wait');
        $queue = $this->child($root, 'queue', 'trigger-exit', ['queue ready', 'queue failed', '7']);
        $supervisor = new DevSupervisor();
        $output = new BufferedOutput();
        $deadline = microtime(true) + 5.0;
        $startedAt = microtime(true);
        try {
            $exit = $supervisor->run(['server' => $server, 'queue' => $queue], $output,
                static function () use ($root, $deadline): void {
                    if (!is_file($root . '/server.ready') || !is_file($root . '/queue.ready')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Dev fixture children did not become ready.');
                        }
                        return;
                    }
                    if (!is_file($root . '/queue.trigger')) {
                        file_put_contents($root . '/queue.trigger', 'release');
                    }
                });
            self::assertNotSame(0, $exit);
            self::assertLessThan(8.0, microtime(true) - $startedAt,
                'A failed child must stop its peer before the fixture self-timeout.');
            self::assertFalse($server->poll(static function (string $type, string $data): void {}),
                'A failed selected child must stop its peer.');
            self::assertFalse($queue->poll(static function (string $type, string $data): void {}));
            $captured = $output->fetch();
            self::assertStringContainsString('queue ready', $captured);
            self::assertStringContainsString('queue failed', $captured);
        } finally {
            $server->terminate(static function (string $type, string $data): void {}, 0.0);
            $queue->terminate(static function (string $type, string $data): void {}, 0.0);
            $project->remove();
        }
    }

    public function testProgrammaticStopReapsAllChildrenWithoutAnOrphanHeartbeat(): void
    {
        $project = new TemporaryProject();
        $root = $project->path('path with spaces');
        self::assertTrue(mkdir($root));
        $server = $this->child($root, 'server', 'wait');
        $queue = $this->child($root, 'queue', 'wait');
        $supervisor = new DevSupervisor();
        $output = new BufferedOutput();
        $deadline = microtime(true) + 5.0;
        $startedAt = microtime(true);
        try {
            $exit = $supervisor->run(['server' => $server, 'queue' => $queue], $output,
                static function () use ($root, $supervisor, $deadline): void {
                    if (is_file($root . '/server.ready') && is_file($root . '/queue.ready')
                        && is_file($root . '/server.heartbeat') && is_file($root . '/queue.heartbeat')) {
                        $supervisor->stop();
                        return;
                    }
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Dev fixture children did not become ready.');
                    }
                });
            self::assertSame(0, $exit, $output->fetch());
            self::assertLessThan(8.0, microtime(true) - $startedAt,
                'Explicit shutdown must stop children before their self-timeout.');
            self::assertFalse($server->poll(static function (string $type, string $data): void {}));
            self::assertFalse($queue->poll(static function (string $type, string $data): void {}));
            $before = [filesize($root . '/server.heartbeat'), filesize($root . '/queue.heartbeat')];
            usleep(200_000);
            clearstatcache(true);
            self::assertSame($before,
                [filesize($root . '/server.heartbeat'), filesize($root . '/queue.heartbeat')],
                'Heartbeat changed after Dev reaped its children.');
        } finally {
            $server->terminate(static function (string $type, string $data): void {}, 0.0);
            $queue->terminate(static function (string $type, string $data): void {}, 0.0);
            $project->remove();
        }
    }

    public function testRealPhpServerCanStartAndStopWithoutRequestingAnApplicationRoute(): void
    {
        $project = new TemporaryProject();
        $root = $project->path('web app with spaces');
        self::assertTrue(mkdir($root));
        $project->write('web app with spaces/public/index.php', '<?php echo "unused";');
        $project->write('web app with spaces/Bootstrap/DevelopmentServer.php', '<?php return false;');
        $reservation = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($reservation === false) {
            $project->remove();
            self::markTestSkipped('Loopback socket is required for PHP server process qualification.');
        }
        $address = stream_socket_get_name($reservation, false);
        fclose($reservation);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $process = DevelopmentServer::prepare(new Application($root),
            '127.0.0.1', (string) $port)->devProcess();
        $supervisor = new DevSupervisor();
        $output = new BufferedOutput();
        $accepted = false;
        $deadline = microtime(true) + 5.0;
        try {
            $exit = $supervisor->run(['server' => $process], $output,
                static function () use ($port, $supervisor, &$accepted, $deadline): void {
                    $socket = @stream_socket_client('tcp://127.0.0.1:' . $port,
                        $number, $message, 0.1);
                    if ($socket !== false) {
                        fclose($socket);
                        $accepted = true;
                        $supervisor->stop();
                        return;
                    }
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('PHP server did not bind the selected port.');
                    }
                });
            self::assertSame(0, $exit, $output->fetch());
            self::assertTrue($accepted, 'A TCP listener must exist before Dev reports startup.');
            self::assertFalse($process->poll(static function (string $type, string $data): void {}));
        } finally {
            $process->terminate(static function (string $type, string $data): void {}, 0.0);
            $project->remove();
        }
    }

    /** The argument vector passes paths with spaces without a shell wrapper. */
    private function child(string $root, string $name, string $mode, array $extra = []): DevProcess
    {
        return new DevProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/DevChild.php', $mode,
            $root . '/' . $name . '.ready', $root . '/' . $name . '.trigger',
            $root . '/' . $name . '.heartbeat', ...$extra], $root);
    }
}
