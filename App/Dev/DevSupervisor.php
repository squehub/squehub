<?php

declare(strict_types=1);

namespace App\Dev;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Owns a small foreground group of local development processes. Every selected
 * service is expected to keep running; losing one ends the whole session.
 * Direct child processes avoid Windows shell wrappers and use bounded
 * termination. An application job that starts its own detached descendants
 * remains outside this local supervisor's ownership.
 */
final class DevSupervisor
{
    private bool $stopRequested = false;
    private bool $running = false;

    /** @var array<string, DevProcess> */
    private array $started = [];

    /** @var array<string, array<string, string>> Incomplete stdout/stderr lines. */
    private array $pending = [];

    public function stop(): void { $this->stopRequested = true; }

    /**
     * @param array<string, DevProcess> $processes Label => long-running process.
     * @param ?callable():void $tick Optional test seam invoked once per poll.
     */
    public function run(array $processes, OutputInterface $output, ?callable $tick = null): int
    {
        if ($this->running || $processes === []) {
            throw new DevException('SqueHub Dev requires a new, non-empty process session.');
        }
        foreach ($processes as $label => $process) {
            if (!is_string($label) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $label) !== 1
                || !$process instanceof DevProcess) {
                throw new DevException('SqueHub Dev process specification is invalid.');
            }
        }

        $this->running = true;
        $this->stopRequested = false;
        $this->started = [];
        $this->pending = [];
        $status = 0;
        $restoreSignals = static function (): void {};
        try {
            $restoreSignals = $this->installStopHandlers();
            foreach ($processes as $label => $process) {
                if ($this->stopRequested) break;
                // A development session can run for hours. DevProcess stores
                // no output history; each poll forwards only new bytes.
                // Retain the handle before start: an exception after a child
                // was created must still allow finally to stop that child.
                $this->started[$label] = $process;
                $process->start();
            }

            while (!$this->stopRequested) {
                foreach ($this->started as $label => $process) {
                    // Polling drains both streams from temporary files; using
                    // Windows pipes here can deadlock on a verbose child.
                    if (!$process->poll(function (string $type, string $data) use ($label, $output): void {
                        $this->forward($label, $type, $data, $output);
                    })) {
                        $code = $process->exitCode();
                        $output->writeln(sprintf('<error>[%s] exited unexpectedly%s.</error>',
                            $label, $code === null ? '' : ' with code ' . $code));
                        $status = 1;
                        break 2;
                    }
                }
                if ($tick !== null) $tick();
                if (!$this->stopRequested) usleep(100_000);
            }
        } catch (Throwable $exception) {
            // Process exceptions may include a command line or environment.
            // Keep the user-facing failure categorical and preserve the cause.
            $output->writeln('<error>SqueHub Dev could not run a managed process.</error>');
            $status = 1;
        } finally {
            // Reverse startup order stops the worker before the web server.
            // An in-flight Queue attempt may be interrupted and later retried
            // by normal reservation rules; no exactly-once claim is made.
            foreach (array_reverse($this->started, true) as $label => $process) {
                try {
                    $stopped = $process->terminate(function (string $type, string $data)
                        use ($label, $output): void {
                        $this->forward($label, $type, $data, $output);
                    }, 5.0);
                    if (!$stopped) {
                        $output->writeln('<error>[' . $label . '] could not be stopped.</error>');
                        $status = 1;
                    }
                } catch (Throwable) {
                    $output->writeln('<error>[' . $label . '] could not be stopped.</error>');
                    $status = 1;
                }
            }
            foreach ($this->pending as $label => $streams) {
                foreach ($streams as $text) {
                    if ($text !== '') $this->writeLine($label, $text, $output);
                }
            }
            $restoreSignals();
            $this->started = [];
            $this->pending = [];
            $this->running = false;
        }
        return $status;
    }

    private function forward(string $label, string $type, string $data, OutputInterface $output): void
    {
        $text = ($this->pending[$label][$type] ?? '') . $data;
        while (($newline = strpos($text, "\n")) !== false) {
            $this->writeLine($label, substr($text, 0, $newline + 1), $output);
            $text = substr($text, $newline + 1);
        }
        // An unterminated log line must not grow forever in a long session.
        if (strlen($text) > 8192) {
            $this->writeLine($label, $text, $output);
            $text = '';
        }
        $this->pending[$label][$type] = $text;
    }

    private function writeLine(string $label, string $line, OutputInterface $output): void
    {
        $output->write('[' . $label . '] ' . OutputFormatter::escape($line));
        if (!str_ends_with($line, "\n")) $output->writeln('');
    }

    /** @return callable():void Restores only signal handlers installed here. */
    private function installStopHandlers(): callable
    {
        if (DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_set_ctrl_handler')) {
            $handler = function (int $event): bool {
                if ($event !== PHP_WINDOWS_EVENT_CTRL_C && $event !== PHP_WINDOWS_EVENT_CTRL_BREAK) {
                    return false;
                }
                $this->stop();
                return true;
            };
            if (!sapi_windows_set_ctrl_handler($handler)) {
                throw new DevException('SqueHub Dev could not register the Windows stop handler.');
            }
            return static function () use ($handler): void {
                sapi_windows_set_ctrl_handler($handler, false);
            };
        }

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')
            && defined('SIGINT') && defined('SIGTERM')) {
            $previousAsync = pcntl_async_signals(true);
            $previousInt = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler(SIGINT) : SIG_DFL;
            $previousTerm = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler(SIGTERM) : SIG_DFL;
            pcntl_signal(SIGINT, fn () => $this->stop());
            pcntl_signal(SIGTERM, fn () => $this->stop());
            return static function () use ($previousInt, $previousTerm, $previousAsync): void {
                pcntl_signal(SIGINT, $previousInt);
                pcntl_signal(SIGTERM, $previousTerm);
                pcntl_async_signals($previousAsync);
            };
        }

        return static function (): void {};
    }
}
