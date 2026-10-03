<?php

declare(strict_types=1);

namespace App\Frontend\Build;

use App\Bundles\BundleException;
use App\Bundles\BundlePath;
use App\Dev\DevProcess;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Frontend\AssetManifest;
use App\Frontend\FrontendException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use InvalidArgumentException;
use Throwable;

/**
 * Explicit Node tooling for an application that selected a build adapter.
 * HTTP boot only reads configuration and built files; it never resolves Node,
 * probes a development server, or runs a package script.
 */
final class FrontendBuild
{
    /** @param ?FrontendBuildAdapter $adapter Test/extension seam; no global registry. */
    public function __construct(private Application $app, private ?FrontendBuildAdapter $adapter = null)
    {
    }

    /**
     * Keep configured, installed, reachable, and built states distinct. The
     * optional probe opens only a short loopback TCP connection.
     *
     * @return array{adapter:string,configured:bool,node_installed:bool,vite_installed:bool,dev_reachable:?bool,build_available:bool}
     */
    public function status(bool $probe = false): array
    {
        $settings = $this->settings();
        $name = $settings['adapter'] ?? 'none';
        if (!is_string($name) || !in_array($name, ['none', 'vite'], true)) {
            throw new FrontendBuildException('Frontend adapter configuration is invalid.');
        }
        if ($name === 'none') {
            return ['adapter' => 'none', 'configured' => false, 'node_installed' => false,
                'vite_installed' => false, 'dev_reachable' => null, 'build_available' => false];
        }
        $source = $this->sourceDirectory($settings);
        $node = (new ExecutableFinder())->find('node');
        $script = $this->localViteScript($source);
        $build = $settings['build'] ?? [];
        $manifest = is_array($build) && is_string($build['manifest'] ?? null)
            ? $build['manifest'] : '.vite/manifest.json';
        $directory = is_array($build) && is_string($build['directory'] ?? null)
            ? $build['directory'] : 'public/assets/build';
        $available = false;
        try {
            $entries = $settings['entries'] ?? [];
            if (is_array($entries) && $entries !== []) {
                $reader = new AssetManifest($this->app->basePath(), $directory, $manifest);
                foreach ($entries as $entry) {
                    if (!is_string($entry)) { throw new FrontendBuildException('Frontend entry configuration is invalid.'); }
                    $reader->resources($entry);
                }
                $available = true;
            }
        } catch (FrontendException | FrontendBuildException) {
            $available = false;
        }
        return ['adapter' => $name, 'configured' => true, 'node_installed' => is_string($node),
            'vite_installed' => $script !== null, 'dev_reachable' => $probe ? $this->probe() : null,
            'build_available' => $available];
    }

    /** Build only the selected local Vite project, then verify every entry. */
    public function build(): string
    {
        [$adapter, $node, $script, $source, $settings] = $this->installed();
        $process = new Process($adapter->buildCommand($node, $script), $source);
        $process->disableOutput();
        $process->setTimeout(300);
        try {
            $process->run();
        } catch (Throwable $exception) {
            throw new FrontendBuildException('Frontend build could not complete.', 0, $exception);
        }
        if (!$process->isSuccessful()) {
            throw new FrontendBuildException('Frontend build failed. Inspect the local Vite project.');
        }
        try {
            $build = $settings['build'] ?? [];
            $reader = new AssetManifest($this->app->basePath(),
                (string) ($build['directory'] ?? 'public/assets/build'),
                (string) ($build['manifest'] ?? '.vite/manifest.json'));
            foreach ($settings['entries'] ?? [] as $entry) {
                if (!is_string($entry)) { throw new FrontendBuildException('Frontend entry configuration is invalid.'); }
                $reader->resources($entry);
            }
            return $reader->fingerprint();
        } catch (FrontendException $exception) {
            throw new FrontendBuildException('Frontend build output is invalid or incomplete.', 0, $exception);
        }
    }

    /**
     * An explicit port is strict. The default probes a bounded loopback range;
     * Vite's own bind remains the final authority after the probe closes.
     */
    public function developmentPort(?string $requested = null, ?int $exclude = null): int
    {
        $this->requireSelected();
        if ($requested !== null && (!ctype_digit($requested) || (int) $requested < 1024
            || (int) $requested > 65535)) {
            throw new FrontendBuildException('Frontend development port must be 1024 through 65535.');
        }
        $first = $requested === null ? $this->configuredPort() : (int) $requested;
        $last = $requested === null ? min($first + 26, 65535) : $first;
        for ($port = $first; $port <= $last; ++$port) {
            if ($exclude === $port) continue;
            $listener = @stream_socket_client('tcp://127.0.0.1:' . $port, $number,
                $message, 0.05, STREAM_CLIENT_CONNECT);
            if ($listener !== false) {
                fclose($listener);
                continue;
            }
            $probe = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if ($probe !== false) {
                fclose($probe);
                return $port;
            }
        }
        throw new FrontendBuildException($requested === null
            ? 'No available frontend development port in the configured range.'
            : 'The requested frontend development port is unavailable.');
    }

    public function developmentUrl(int $port): string
    {
        if ($port < 1024 || $port > 65535) {
            throw new FrontendBuildException('Frontend development port is invalid.');
        }
        return 'http://127.0.0.1:' . $port;
    }

    /** Vite receives only the selected backend origin and base path. */
    public function developmentProcess(int $port, string $backendUrl): DevProcess
    {
        if (!in_array($this->app->environment(), ['development', 'local'], true)) {
            throw new FrontendBuildException('Frontend development requires a local environment.');
        }
        [$adapter, $node, $script, $source] = $this->installed();
        try { $parsed = parse_url($backendUrl); }
        catch (\ValueError) { $parsed = false; }
        if (!is_array($parsed) || ($parsed['scheme'] ?? null) !== 'http'
            || !in_array($parsed['host'] ?? null, ['localhost', '127.0.0.1'], true)
            || !is_int($parsed['port'] ?? null) || $parsed['port'] < 1024
            || isset($parsed['user']) || isset($parsed['pass'])
            || isset($parsed['query']) || isset($parsed['fragment'])) {
            throw new FrontendBuildException('Backend development URL is invalid.');
        }
        $origin = 'http://' . $parsed['host'] . ':' . $parsed['port'];
        try { $base = (new UrlBasePath((string) ($parsed['path'] ?? '')))->value(); }
        catch (InvalidArgumentException $exception) {
            throw new FrontendBuildException('Backend development mount is invalid.', 0, $exception);
        }
        return new DevProcess($adapter->developmentCommand($node, $script, $port), $source,
            ['SQUEHUB_BACKEND_ORIGIN' => $origin, 'SQUEHUB_BASE_PATH' => $base]);
    }

    /** @return array{FrontendBuildAdapter,string,string,string,array<string,mixed>} */
    private function installed(): array
    {
        $settings = $this->settings();
        $adapter = $this->requireSelected();
        $source = $this->sourceDirectory($settings);
        if (!is_dir($source) || is_link($source)) {
            throw new FrontendBuildException('Selected frontend source directory is unavailable.');
        }
        $node = (new ExecutableFinder())->find('node');
        $script = $this->localViteScript($source);
        if (!is_string($node) || $script === null) {
            throw new FrontendBuildException('Selected frontend needs local Node and Vite. Run npm install in Project/Frontend.');
        }
        return [$adapter, $node, $script, $source, $settings];
    }

    private function requireSelected(): FrontendBuildAdapter
    {
        $name = $this->settings()['adapter'] ?? 'none';
        if ($name !== 'vite') {
            throw new FrontendBuildException('No frontend build adapter is selected.');
        }
        $adapter = $this->adapter ?? new ViteBuildAdapter();
        if ($adapter->name() !== $name) {
            throw new FrontendBuildException('Selected frontend build adapter is unavailable.');
        }
        return $adapter;
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $settings = $this->app->config()->get('frontend', []);
        if (!is_array($settings)) {
            throw new FrontendBuildException('Frontend configuration is invalid.');
        }
        return $settings;
    }

    /** Reject a configurable source path that could leave the application. */
    private function sourceDirectory(array $settings): string
    {
        if (($settings['source'] ?? null) !== 'Project/Frontend') {
            throw new FrontendBuildException('Frontend source must be Project/Frontend.');
        }
        try {
            return BundlePath::target($this->app->basePath(), 'Project/Frontend');
        } catch (BundleException $exception) {
            throw new FrontendBuildException('Frontend source is unsafe or conflicts by casing.', 0, $exception);
        }
    }

    private function localViteScript(string $source): ?string
    {
        $path = $source . '/node_modules/vite/bin/vite.js';
        if (!is_file($path) || is_link($path)) { return null; }
        $resolved = realpath($path);
        $root = realpath($source);
        if ($resolved === false || $root === false
            || !str_starts_with(str_replace('\\', '/', $resolved),
                rtrim(str_replace('\\', '/', $root), '/') . '/')) {
            return null;
        }
        return $resolved;
    }

    private function configuredPort(): int
    {
        $development = $this->settings()['development'] ?? [];
        $url = is_array($development) ? ($development['url'] ?? null) : null;
        try { $parts = is_string($url) ? parse_url($url) : false; }
        catch (\ValueError) { $parts = false; }
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || !in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true)
            || !is_int($parts['port'] ?? null) || $parts['port'] < 1024
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new FrontendBuildException('Frontend development URL must use loopback HTTP.');
        }
        return $parts['port'];
    }

    private function probe(): bool
    {
        if (!in_array($this->app->environment(), ['development', 'local'], true)) {
            return false;
        }
        $port = $this->configuredPort();
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $number,
            $message, 0.15, STREAM_CLIENT_CONNECT);
        if ($socket === false) { return false; }
        fclose($socket);
        return true;
    }
}
