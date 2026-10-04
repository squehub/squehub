<?php

declare(strict_types=1);

namespace App\Dev;

use App\Foundation\Application;
use App\Foundation\Environment;
use App\Foundation\UrlBasePath;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Prepares the same local PHP server for `start` and SqueHub Dev. The only
 * filesystem change is its private runtime session directory; source and
 * application configuration are never rewritten.
 */
final class DevelopmentServer
{
    /** @param non-empty-array<int, string> $command */
    private function __construct(
        private readonly Process $process,
        private readonly string $url,
        private readonly ?string $portNotice,
        private readonly array $command,
        private readonly string $workingDirectory
    ) {
    }

    public static function prepare(Application $app, string $host = 'localhost',
        ?string $requestedPort = null): self
    {
        return self::prepareFor($app, $host, $requestedPort, 8000, 8099,
            'Bootstrap/DevelopmentServer.php', '');
    }

    /** Studio has its own router and never accepts a public bind address. */
    public static function prepareStudio(Application $app, ?string $requestedPort = null): self
    {
        if ($app->environment() !== 'development'
            || $app->config()->get('studio.enabled', false) !== true) {
            throw new DevException('Studio requires development environment and explicit enablement.');
        }
        return self::prepareFor($app, '127.0.0.1', $requestedPort, 8100, 8199,
            'Bootstrap/StudioServer.php', '/studio');
    }

    private static function prepareFor(Application $app, string $host, ?string $requestedPort,
        int $defaultPort, int $lastDefaultPort, string $router, string $urlSuffix): self
    {
        $port = $requestedPort ?? (string) $defaultPort;
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new DevException('Invalid host.');
        }
        if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
            throw new DevException('Invalid port. It should be a number between 1024 and 65535.');
        }

        $documentRoot = $app->publicPath();
        $routerScript = $app->basePath($router);
        if (!is_dir($documentRoot) || !is_file($routerScript)) {
            throw new DevException('The public development entry point is missing.');
        }

        // Probe the address PHP will bind. A TCP connect detects an active
        // listener even where Windows permits another bind to the same port;
        // the bind probe then catches reserved/blocked ports without a
        // listener. PHP's actual server bind remains the final authority.
        $first = (int) $port;
        $last = $requestedPort === null ? $lastDefaultPort : $first;
        // Only make outbound connection probes to a known loopback address.
        // An arbitrary hostname accepted for binding must not become an
        // accidental remote port scan during development startup.
        $connectHosts = match ($host) {
            'localhost' => ['localhost', '127.0.0.1'],
            '127.0.0.1', '0.0.0.0' => ['127.0.0.1'],
            default => [],
        };
        $selected = null;
        for ($candidate = $first; $candidate <= $last; ++$candidate) {
            foreach ($connectHosts as $connectHost) {
                $listener = @stream_socket_client("tcp://{$connectHost}:{$candidate}", $errorCode,
                    $errorMessage, 0.05, STREAM_CLIENT_CONNECT);
                if ($listener !== false) {
                    fclose($listener);
                    continue 2;
                }
            }
            $probe = @stream_socket_server("tcp://{$host}:{$candidate}");
            if ($probe !== false) {
                fclose($probe);
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            throw new DevException($requestedPort === null
                ? "No available development port from {$defaultPort} through {$lastDefaultPort} on {$host}."
                : "Cannot listen on {$host}:{$port}; the port is in use or blocked by the operating system.");
        }
        $portNotice = $selected === $first ? null : "Port {$first} is unavailable; using {$selected}.";

        // A protected system session.save_path can make every web request
        // fail even when the server binds successfully. Keep sessions outside
        // public/ and isolate them by application root.
        $sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'squehub-dev-sessions-'
            . substr(hash('sha256', $app->basePath()), 0, 16);
        if ((!is_dir($sessionPath) && !@mkdir($sessionPath, 0700, true))
            || !is_writable($sessionPath)) {
            throw new DevException('Cannot prepare a writable development session directory.');
        }

        $command = [
            PHP_BINARY, '-d', 'session.save_path=' . $sessionPath,
            '-S', $host . ':' . $selected, '-t', $documentRoot, $routerScript,
        ];
        $process = new Process($command, $app->basePath(), self::childEnvironment($app));
        $process->setTimeout(null);

        $mount = $app->container()->make(UrlBasePath::class)->value();
        return new self($process, "http://{$host}:{$selected}{$mount}{$urlSuffix}", $portNotice,
            $command, $app->basePath());
    }

    /**
     * Remove only copies of .env values published by CLI boot. A long-lived
     * child must read its own current .env; real shell variables retain their
     * normal precedence. The values themselves never enter command arguments.
     *
     * @return array<string, false>
     */
    public static function childEnvironment(Application $app): array
    {
        $environment = [];
        foreach ($app->container()->make(Environment::class)->publishedDotenvKeys() as $key) {
            $environment[$key] = false;
        }
        return $environment;
    }

    public function process(): Process { return $this->process; }
    /** Native direct child used by Dev for reliable Windows shutdown. */
    /** @param array<string,string> $environment Process-only development overrides. */
    public function devProcess(array $environment = []): DevProcess
    {
        return new DevProcess($this->command, $this->workingDirectory, $environment);
    }
    public function url(): string { return $this->url; }
    public function portNotice(): ?string { return $this->portNotice; }

    /** Preserve the standalone `start` command's foreground output and exit status. */
    public function run(OutputInterface $output): int
    {
        if ($this->portNotice !== null) $output->writeln($this->portNotice);
        $output->writeln('Starting PHP built-in server on ' . $this->url . '...');
        try {
            $this->process->run(static function (string $type, string $data) use ($output): void {
                $output->write($data);
            });
        } catch (Throwable $exception) {
            throw new DevException('PHP development server could not start.', 0, $exception);
        }
        return $this->process->isSuccessful() ? 0 : 1;
    }
}
