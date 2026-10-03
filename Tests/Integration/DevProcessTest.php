<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Dev\DevException;
use App\Dev\DevProcess;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Direct argv spawning preserves special characters and both output streams. */
final class DevProcessTest extends TestCase
{
    public function testDirectPhpChildPreservesSpacedPathsAndArgumentBytes(): void
    {
        $project = new TemporaryProject();
        $directory = $project->path('folder with spaces');
        self::assertTrue(mkdir($directory));
        $stdout = 'literal & echo SHOULD_NOT_EXECUTE';
        $stderr = 'literal %TEMP% ^ !';
        $process = new DevProcess([PHP_BINARY, dirname(__DIR__) . '/Fixtures/DevChild.php',
            'emit', $stdout, $stderr, '7'], $directory);
        $captured = ['out' => '', 'err' => ''];
        $receiver = static function (string $type, string $data) use (&$captured): void {
            $captured[$type] .= $data;
        };
        try {
            self::assertSame(PHP_BINARY, $process->command()[0]);
            self::assertSame($directory, $process->workingDirectory());
            $process->start();
            $deadline = microtime(true) + 5.0;
            while ($process->poll($receiver) && microtime(true) < $deadline) usleep(20_000);
            self::assertFalse($process->poll($receiver), 'The bounded PHP fixture should have exited.');
            self::assertSame(7, $process->exitCode());
            self::assertSame($stdout . "\n", $captured['out']);
            self::assertSame($stderr . "\n", $captured['err']);
            self::assertFileDoesNotExist($directory . '/SHOULD_NOT_EXECUTE');
        } finally {
            $process->terminate($receiver, 0.0);
            $project->remove();
        }
    }

    public function testInvalidProcessSpecificationIsRejectedBeforeSpawning(): void
    {
        $project = new TemporaryProject();
        try {
            $this->expectException(DevException::class);
            new DevProcess([PHP_BINARY, "bad\0argument"], $project->path());
        } finally {
            $project->remove();
        }
    }

    public function testProcessOnlyFrontendUrlReachesChildWithoutWritingEnvironmentFile(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Child.php', '<?php echo getenv("SQUEHUB_FRONTEND_DEV_URL");');
            $process = new DevProcess([PHP_BINARY, $project->path('Child.php')],
                $project->path(), ['SQUEHUB_FRONTEND_DEV_URL' => 'http://127.0.0.1:5187']);
            $output = '';
            $receive = static function (string $type, string $data) use (&$output): void {
                if ($type === 'out') $output .= $data;
            };
            try {
                $process->start();
                $deadline = microtime(true) + 5;
                while ($process->poll($receive) && microtime(true) < $deadline) usleep(20_000);
                self::assertFalse($process->poll($receive));
                self::assertSame('http://127.0.0.1:5187', $output);
                self::assertSame(0, $process->exitCode());
                self::assertFileDoesNotExist($project->path('.env'));
            } finally { $process->terminate($receive, 0.0); }
        } finally { $project->remove(); }
    }

    public function testProcessOverrideWinsOverConfiguredDotenvUrlInChild(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('.env', "SQUEHUB_FRONTEND_DEV_URL=http://127.0.0.1:5173\n");
            $root = dirname(__DIR__, 2);
            $project->write('Child.php', '<?php require '
                . var_export($root . '/vendor/autoload.php', true) . '; '
                . 'echo (new \\App\\Foundation\\Environment(__DIR__))->get("SQUEHUB_FRONTEND_DEV_URL");');
            $process = new DevProcess([PHP_BINARY, $project->path('Child.php')],
                $project->path(), ['SQUEHUB_FRONTEND_DEV_URL' => 'http://127.0.0.1:5187']);
            $output = '';
            $receive = static function (string $type, string $data) use (&$output): void {
                if ($type === 'out') $output .= $data;
            };
            try {
                $process->start();
                $deadline = microtime(true) + 5;
                while ($process->poll($receive) && microtime(true) < $deadline) usleep(20_000);
                self::assertFalse($process->poll($receive));
                self::assertSame('http://127.0.0.1:5187', $output);
                self::assertSame(0, $process->exitCode());
                self::assertSame("SQUEHUB_FRONTEND_DEV_URL=http://127.0.0.1:5173\n",
                    file_get_contents($project->path('.env')));
            } finally { $process->terminate($receive, 0.0); }
        } finally { $project->remove(); }
    }
}
