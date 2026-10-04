<?php

declare(strict_types=1);

namespace App\Dev;

/**
 * A direct child process for a foreground development session. Argument-array
 * proc_open avoids a shell wrapper, so Windows can terminate the actual PHP
 * child with proc_terminate. Temporary output files avoid Windows pipe hangs;
 * they are removed when the child is reaped. Ordinary children inherit the OS
 * environment. Explicit process-only overrides are merged without copying
 * .env-only values published into CLI $_ENV.
 */
final class DevProcess
{
    /** @var resource|null */
    private $process = null;

    /** @var array<string, resource> */
    private array $readers = [];

    /** @var array<string, string> */
    private array $paths = [];

    private bool $started = false;
    private ?int $exitCode = null;

    /**
     * @param list<string> $command
     * @param array<string,string> $environment Process-only overrides; never written to .env.
     */
    public function __construct(private readonly array $command, private readonly string $workingDirectory,
        private readonly array $environment = [])
    {
        if ($command === [] || $command[0] === '' || !is_dir($workingDirectory)) {
            throw new DevException('SqueHub Dev process specification is invalid.');
        }
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new DevException('SqueHub Dev process specification is invalid.');
            }
        }
        foreach ($environment as $key => $value) {
            if (!is_string($key) || preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $key) !== 1
                || !is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new DevException('SqueHub Dev process environment is invalid.');
            }
        }
    }

    /** @return list<string> */
    public function command(): array { return $this->command; }
    public function workingDirectory(): string { return $this->workingDirectory; }
    public function exitCode(): ?int { return $this->exitCode; }

    public function start(): void
    {
        if ($this->started) throw new DevException('SqueHub Dev process is already started.');
        $this->started = true;
        $out = @tempnam(sys_get_temp_dir(), 'sqdevout-');
        $err = @tempnam(sys_get_temp_dir(), 'sqdeverr-');
        if ($out === false || $err === false) {
            if (is_string($out)) @unlink($out);
            if (is_string($err)) @unlink($err);
            throw new DevException('SqueHub Dev could not prepare child output files.');
        }
        $this->paths = ['out' => $out, 'err' => $err];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']];
        // Ordinary children inherit the OS environment, then read their own
        // current .env. The selected frontend URL is a process-only override;
        // never copy CLI-published .env secrets into another child process.
        $environment = null;
        if ($this->environment !== []) {
            $environment = (array) getenv();
            foreach ($this->environment as $key => $value) $environment[$key] = $value;
        }
        $process = @proc_open($this->command, $descriptors, $pipes,
            $this->workingDirectory, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            $this->removeOutputFiles();
            throw new DevException('SqueHub Dev could not start a child process.');
        }
        $this->process = $process;
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        foreach ($this->paths as $type => $path) {
            $reader = @fopen($path, 'rb');
            if ($reader === false) {
                // A child may already be running. Reap it here rather than
                // relying on a caller to recover from partial start.
                if (!$this->terminate(static function (string $type, string $data): void {}, 1.0)) {
                    throw new DevException('SqueHub Dev could not stop a child after output setup failed.');
                }
                throw new DevException('SqueHub Dev could not read child output.');
            }
            $this->readers[$type] = $reader;
        }
    }

    /**
     * Drain new output and return whether the direct child remains alive.
     *
     * @param callable(string,string):void $receive Receives out/err chunks.
     */
    public function poll(callable $receive): bool
    {
        if (!$this->started) return false;
        if ($this->process === null) return false;
        $this->drain($receive);
        $status = proc_get_status($this->process);
        if ($status['running']) return true;
        $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
        $this->drain($receive);
        $closed = proc_close($this->process);
        $this->process = null;
        if ($this->exitCode === null && $closed >= 0) $this->exitCode = $closed;
        $this->removeOutputFiles();
        return false;
    }

    /**
     * Ask a child to stop, then force termination after the grace period.
     * Windows proc_terminate directly ends the PHP process; it cannot promise
     * a running Queue job will finish before its reservation is retried.
     *
     * @param callable(string,string):void $receive Receives final output.
     */
    public function terminate(callable $receive, float $graceSeconds = 5.0): bool
    {
        if (!$this->started || $this->process === null) return true;
        if (!$this->poll($receive)) return true;
        @proc_terminate($this->process);
        if ($this->waitForExit($receive, $graceSeconds)) return true;
        @proc_terminate($this->process, 9);
        return $this->waitForExit($receive, 1.0);
    }

    /** @param callable(string,string):void $receive */
    private function waitForExit(callable $receive, float $graceSeconds): bool
    {
        $deadline = microtime(true) + max(0.0, $graceSeconds);
        do {
            if (!$this->poll($receive)) return true;
            usleep(50_000);
        } while (microtime(true) < $deadline);
        return false;
    }

    /** @param callable(string,string):void $receive */
    private function drain(callable $receive): void
    {
        foreach ($this->readers as $type => $reader) {
            // Seeking to the current offset clears EOF after another process
            // appends to the same file between polls.
            fseek($reader, 0, SEEK_CUR);
            while (($data = fread($reader, 8192)) !== false && $data !== '') {
                $receive($type, $data);
            }
        }
    }

    private function removeOutputFiles(): void
    {
        foreach ($this->readers as $reader) fclose($reader);
        $this->readers = [];
        foreach ($this->paths as $path) @unlink($path);
        $this->paths = [];
    }
}
