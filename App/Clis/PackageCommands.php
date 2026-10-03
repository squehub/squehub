<?php

declare(strict_types=1);

namespace App\Clis;

use App\Changes\ChangePlan;
use App\Changes\ChangeApplyException;
use App\Changes\ChangeRenderer;
use App\Core\View;
use App\Foundation\Application;
use App\Packages\PackageException;
use App\Packages\PackageManager;
use App\Scheduler\ScheduleLoader;
use App\Scheduler\Scheduler;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Registers the Package lifecycle CLI without exposing source locations. */
final class PackageCommands
{
    public static function register(ConsoleApplication $console, PackageManager $packages, Application $app): void
    {
        $list = new Command('package:list');
        $list->setDescription('List discovered Packages and their activation status.')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($packages): int {
                try {
                    $descriptors = $packages->list();
                    usort($descriptors, static function ($left, $right): int {
                        return strcasecmp($left->name(), $right->name()) ?: strcmp($left->name(), $right->name());
                    });

                    if ($descriptors === []) {
                        $output->writeln('<comment>No Packages are installed.</comment>');
                        return Command::SUCCESS;
                    }

                    $table = new Table($output);
                    $table->setHeaders(['Package', 'Status', 'Version', 'Owner', 'Issues']);
                    foreach ($descriptors as $descriptor) {
                        $issues = $descriptor->errors();
                        $table->addRow([
                            self::safe($descriptor->name()),
                            self::safe($descriptor->status()),
                            self::safe($descriptor->version() ?? '-'),
                            self::safe($descriptor->owner() ?? '-'),
                            self::safe($issues === [] ? '-' : implode('; ', $issues)),
                        ]);
                    }
                    $table->render();
                    return Command::SUCCESS;
                } catch (PackageException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Packages could not be listed.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($list);

        $inspect = new Command('package:inspect');
        $inspect->setDescription('Inspect Package state and last verified contributions without executing Package code.')
            ->addArgument('name', InputArgument::REQUIRED, 'Exact Package name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED,
                'Show recorded contributions of one type (route, middleware, view, view_namespace, config, service, scheduler)')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($packages): int {
                try {
                    $filter = $input->getOption('type');
                    $labels = [
                        'route' => 'Routes', 'middleware' => 'Middleware', 'view' => 'Views',
                        'view_namespace' => 'View namespaces',
                        'config' => 'Config', 'service' => 'Services', 'scheduler' => 'Scheduler',
                    ];
                    if ($filter !== null && (!is_string($filter) || !isset($labels[$filter]))) {
                        throw new PackageException('Contribution type is unsupported.');
                    }
                    $inspection = $packages->inspect((string) $input->getArgument('name'));
                    $descriptor = $inspection['descriptor'];
                    $record = $descriptor->record();
                    $output->writeln('<info>' . self::safe($descriptor->name()) . '</info>');
                    $output->writeln('Status: ' . self::safe($descriptor->status()));
                    $output->writeln('Version: ' . self::safe($descriptor->version() ?? '-'));
                    $output->writeln('Owner: ' . self::safe($descriptor->owner() ?? 'unspecified'));
                    $output->writeln('Source: ' . self::safe((string) ($record['source_kind'] ?? 'manual')));
                    $activation = ($record['enabled'] ?? false) === true
                        ? ($descriptor->status() === 'enabled' ? 'explicitly enabled in Activation Registry'
                            : 'requested in Activation Registry; Package cannot activate')
                        : 'not enabled';
                    $output->writeln('Activation: ' . $activation);
                    $output->writeln('Dependencies: ' . self::safe($descriptor->dependencies() === []
                        ? 'none' : implode(', ', $descriptor->dependencies())));
                    $output->writeln('Required by: ' . self::safe($inspection['required_by'] === []
                        ? 'none' : implode(', ', $inspection['required_by'])));
                    $output->writeln('Required by Kits: ' . self::safe($inspection['required_by_kits'] === []
                        ? 'none' : implode(', ', $inspection['required_by_kits'])));
                    if ($descriptor->errors() !== []) {
                        $output->writeln('Reason: ' . self::safe(implode('; ', $descriptor->errors())));
                    }
                    $provenance = $inspection['provenance'];
                    $reason = $provenance === 'stale'
                        ? ($descriptor->status() === 'broken' ? ' (Package cannot activate)'
                            : ' (Package source changed)') : '';
                    $output->writeln('Provenance: ' . self::safe($provenance . $reason));
                    if ($provenance === 'unavailable') {
                        $output->writeln('Contributions: unavailable until package:verify runs.');
                        return Command::SUCCESS;
                    }
                    $active = $descriptor->status() === 'enabled' && $provenance === 'current';
                    $output->writeln('Contributions: ' . ($active ? 'last verified active registrations'
                        : 'last verified records (not confirmed active)'));
                    $counts = array_fill_keys(array_keys($labels), 0);
                    foreach ($inspection['contributions'] as $item) {
                        ++$counts[$item['type']];
                    }
                    foreach ($labels as $type => $label) {
                        if ($filter === null || $filter === $type) {
                            $output->writeln('  ' . $label . ': ' . $counts[$type]);
                        }
                    }
                    if ($filter !== null) {
                        foreach ($inspection['contributions'] as $item) {
                            if ($item['type'] !== $filter) { continue; }
                            $line = '  ' . $item['identifier'];
                            if (is_string($item['source'])) { $line .= ' — ' . $item['source']; }
                            $output->writeln(self::safe($line));
                        }
                    }
                    return Command::SUCCESS;
                } catch (PackageException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Package could not be inspected.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($inspect);

        $verify = new Command('package:verify');
        $verify->setDescription('Deliberately execute enabled Package definitions and refresh one contribution snapshot.')
            ->addArgument('name', InputArgument::REQUIRED, 'Exact enabled Package name')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($packages, $app): int {
                try {
                    $name = (string) $input->getArgument('name');
                    if ($packages->inspect($name)['descriptor']->status() !== 'enabled') {
                        throw new PackageException('Only an enabled Package can be verified.');
                    }
                    // A verification boot has already run the enabled providers.
                    // Route and Scheduler definition files also execute here,
                    // but no route handler, scheduled task, or view is rendered.
                    ob_start();
                    try {
                        self::loadRoutesForVerification($app);
                        (new ScheduleLoader())->load($app);
                        if ($app->container()->has(Scheduler::class)) {
                            $app->container()->make(Scheduler::class)->definitions();
                        }
                        View::indexAvailable();
                    } finally {
                        ob_end_clean();
                    }
                    $packages->captureContributionSnapshot($name);
                    $output->writeln('<info>Package ' . self::safe($name)
                        . ' contribution snapshot verified.</info>');
                    return Command::SUCCESS;
                } catch (PackageException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Package verification failed.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($verify);

        foreach (['install', 'enable', 'disable', 'upgrade', 'remove'] as $operation) {
            $command = new Command('package:' . $operation);
            $command->setDescription(self::description($operation));
            if ($operation === 'install') {
                $command->addArgument('source', InputArgument::REQUIRED,
                    'Local Package directory or HTTPS Git source');
            } else {
                $command->addArgument('name', InputArgument::REQUIRED, 'Exact Package name');
                if ($operation === 'upgrade') {
                    $command->addArgument('source', InputArgument::REQUIRED,
                        'Local Package directory or HTTPS Git source');
                }
            }
            $command->addOption('preview', null, InputOption::VALUE_NONE,
                'Show the proposed changes without applying them');
            $command->addOption('yes', 'y', InputOption::VALUE_NONE,
                'Apply the reviewed plan without an interactive confirmation');
            $command->setCode(static function (InputInterface $input, OutputInterface $output) use ($operation, $packages): int {
                try {
                    $plan = self::plan($operation, $input, $packages);
                    $output->writeln(ChangeRenderer::render($plan));
                    if ($plan->hasConflicts()) {
                        $output->writeln('<error>Resolve Package conflicts before applying this change.</error>');
                        return Command::FAILURE;
                    }
                    if ($input->getOption('preview')) {
                        $output->writeln('<comment>Preview only; no application Package files or state changed.</comment>');
                        return Command::SUCCESS;
                    }
                    if (!$input->getOption('yes') && !self::confirmedAtTerminal($input, $output)) {
                        $output->writeln('<error>Use --yes to apply this reviewed Package plan non-interactively.</error>');
                        return Command::FAILURE;
                    }
                    $result = $packages->apply($plan);
                    if (!$result->complete()) {
                        if ($result->recoveryPath !== null) {
                            $output->writeln('<error>Package changes applied, but backup cleanup is incomplete at '
                                . self::safe($result->recoveryPath) . '; manual recovery is needed.</error>');
                        } else {
                            $output->writeln('<error>Package change could not be fully verified at '
                                . self::safe($result->failed?->subject ?? 'the final state') . '.</error>');
                        }
                        return Command::FAILURE;
                    }
                    $output->writeln('<info>Package ' . self::safe($operation) . ' completed.</info>');
                    return Command::SUCCESS;
                } catch (ChangeApplyException $exception) {
                    $result = $exception->result;
                    $output->writeln('<error>Package change was incomplete: '
                        . count($result->applied) . ' applied, ' . count($result->unapplied)
                        . ' unapplied. Review the filesystem and Activation Registry.</error>');
                    if ($result->failed !== null) {
                        $output->writeln('<error>Unverified action: '
                            . self::safe(strtoupper($result->failed->kind) . ' ' . $result->failed->subject)
                            . '.</error>');
                    }
                    if ($result->recoveryPath !== null) {
                        $output->writeln('<error>Recovery files remain at '
                            . self::safe($result->recoveryPath) . '.</error>');
                    }
                    return Command::FAILURE;
                } catch (PackageException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    // Lower-level process and filesystem errors may contain source
                    // credentials or absolute paths. Keep those out of CLI output.
                    $output->writeln('<error>Package operation failed.</error>');
                    return Command::FAILURE;
                }
            });
            $console->add($command);
        }
    }

    private static function description(string $operation): string
    {
        return match ($operation) {
            'install' => 'Install a Package into Project/Packages, disabled by default.',
            'enable' => 'Validate and enable an installed Package.',
            'disable' => 'Disable a Package without deleting its files.',
            'upgrade' => 'Upgrade an installed Package from an explicit source.',
            'remove' => 'Remove an owned Package after safety checks.',
            default => throw new PackageException('Package operation is unsupported.'),
        };
    }

    private static function plan(string $operation, InputInterface $input, PackageManager $packages): ChangePlan
    {
        return match ($operation) {
            'install' => $packages->planInstall((string) $input->getArgument('source')),
            'enable' => $packages->planEnable((string) $input->getArgument('name')),
            'disable' => $packages->planDisable((string) $input->getArgument('name')),
            'upgrade' => $packages->planUpgrade((string) $input->getArgument('name'),
                (string) $input->getArgument('source')),
            'remove' => $packages->planRemove((string) $input->getArgument('name')),
            default => throw new PackageException('Package operation is unsupported.'),
        };
    }

    /** A Symfony interactive input alone is insufficient when stdin is a pipe. */
    private static function confirmedAtTerminal(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->isInteractive() || !defined('STDIN') || !function_exists('stream_isatty')
            || !@stream_isatty(STDIN)) { return false; }
        $output->write('<question>Apply this Package change? [y/N] </question>');
        $answer = fgets(STDIN);
        return is_string($answer) && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    /** Keep variables created by the legacy route loader out of the CLI closure. */
    private static function loadRoutesForVerification(Application $app): void
    {
        $squehubApp = $app;
        require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';
    }

    /** Neutralize console markup and control characters from Package metadata. */
    private static function safe(string $value): string
    {
        $withoutControls = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
        return OutputFormatter::escape($withoutControls);
    }
}
