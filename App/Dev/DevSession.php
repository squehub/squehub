<?php

declare(strict_types=1);

namespace App\Dev;

use App\Foundation\Application;
use App\Frontend\Build\FrontendBuild;
use App\Frontend\Build\FrontendBuildException;
use App\Health\HealthManager;
use App\Health\HealthReport;
use App\Queue\PersistentQueueDriver;
use App\Queue\QueueManager;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Coordinates one local development session using existing Application,
 * Health, PHP server, and Queue services. It owns no worker, scheduler loop,
 * Package state or persistent process registry. An explicitly selected
 * frontend can contribute one supervised local Vite process.
 */
final class DevSession
{
    /**
     * The optional supervisor and tick keep process-lifecycle tests bounded;
     * ordinary CLI calls use the default foreground supervisor without them.
     *
     * @param ?callable():void $tick
     */
    public static function run(Application $app, OutputInterface $output, string $host = 'localhost',
        ?string $port = null, bool $queue = false, ?DevSupervisor $supervisor = null,
        ?callable $tick = null, bool $frontend = false, ?string $frontendPort = null): int
    {
        $output->writeln('SqueHub Dev');
        try {
            $health = $app->container()->make(HealthManager::class);
            // Doctor is a one-time diagnostic, not a watcher. Its optional
            // warnings are visible but do not block a simple PHP server.
            $doctor = $health->doctor();
            self::showDoctor($doctor, $output);
            $ready = $health->ready();
            if ($ready->hasFailures()) {
                $output->writeln('Readiness: fail.');
                $output->writeln('<error>Application preflight failed; run php squehub doctor or php squehub setup.</error>');
                foreach ($ready->results() as $result) {
                    if ($result->status() === 'fail') {
                        $output->writeln('<error>' . $result->name() . ': '
                            . $result->summary() . '</error>');
                    }
                }
                return 1;
            }
            $output->writeln('Readiness: pass.');
        } catch (Throwable $exception) {
            throw new DevException('SqueHub Dev preflight could not complete. Run php squehub doctor.', 0, $exception);
        }

        $worker = null;
        if ($queue) {
            try {
                $manager = $app->container()->make(QueueManager::class);
                if (!$manager->driver() instanceof PersistentQueueDriver) {
                    throw new DevException('The selected sync Queue processes jobs immediately; no worker is needed.');
                }
                $worker = self::queueProcess($app);
            } catch (DevException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new DevException('Queue worker is unavailable. Check Queue configuration and persistence.',
                    0, $exception);
            }
        }

        if (!$frontend && $frontendPort !== null) {
            throw new DevException('--frontend-port requires --frontend.');
        }
        $frontendBuild = $frontend ? new FrontendBuild($app) : null;
        $selectedFrontendPort = null;
        $server = DevelopmentServer::prepare($app, $host, $port);
        if ($frontendBuild !== null) {
            try {
                $serverPort = parse_url($server->url(), PHP_URL_PORT);
                $selectedFrontendPort = $frontendBuild->developmentPort($frontendPort,
                    is_int($serverPort) ? $serverPort : null);
            } catch (FrontendBuildException $exception) {
                throw new DevException($exception->getMessage(), 0, $exception);
            }
        }
        $frontendProcess = null;
        $frontendUrl = null;
        if ($frontendBuild !== null && $selectedFrontendPort !== null) {
            try {
                $frontendUrl = $frontendBuild->developmentUrl($selectedFrontendPort);
                $frontendProcess = $frontendBuild->developmentProcess($selectedFrontendPort, $server->url());
            } catch (FrontendBuildException $exception) {
                throw new DevException($exception->getMessage(), 0, $exception);
            }
        }
        if ($server->portNotice() !== null) $output->writeln($server->portNotice());
        $output->writeln('Server: ' . $server->url() . ' (starting)');
        $output->writeln($worker === null ? 'Queue: not started' : 'Queue: worker starting');
        $output->writeln($frontendUrl === null ? 'Frontend: not started'
            : 'Frontend: ' . $frontendUrl . ' (starting)');
        $output->writeln('Scheduler: not started');
        $output->writeln('Press Ctrl+C to stop.');

        $processes = ['server' => $server->devProcess($frontendUrl === null ? []
            : ['SQUEHUB_FRONTEND_DEV_URL' => $frontendUrl])];
        if ($worker !== null) $processes['queue'] = $worker;
        if ($frontendProcess !== null) $processes['frontend'] = $frontendProcess;
        return ($supervisor ?? new DevSupervisor())->run($processes, $output, $tick);
    }

    /**
     * Build the existing long-running Queue command without booting a driver.
     * `run()` checks that the selected connection is persistent before this
     * process is ever started.
     */
    public static function queueProcess(Application $app): DevProcess
    {
        $entry = $app->basePath('squehub');
        if (!is_file($entry)) {
            throw new DevException('The application squehub entry file is missing.');
        }
        return new DevProcess([PHP_BINARY, $entry, 'queue:work'], $app->basePath());
    }

    private static function showDoctor(HealthReport $report, OutputInterface $output): void
    {
        $counts = $report->counts();
        $output->writeln(sprintf('Doctor: %d pass, %d warning, %d fail, %d skipped (broad diagnostics).',
            $counts['pass'], $counts['warning'], $counts['fail'], $counts['skipped']));
        foreach ($report->results() as $result) {
            if ($result->status() === 'warning' || $result->status() === 'fail') {
                $output->writeln(sprintf('  %s: %s', $result->name(), $result->summary()));
            }
        }
    }
}
