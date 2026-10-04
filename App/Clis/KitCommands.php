<?php

declare(strict_types=1);

namespace App\Clis;

use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangeRenderer;
use App\Kits\KitException;
use App\Kits\KitManager;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Registers the explicit Kit lifecycle without loading Kit entry PHP during
 * discovery, inspection, command help, or review. The manager owns all writes.
 */
final class KitCommands
{
    public static function register(ConsoleApplication $console, KitManager $kits): void
    {
        $list = new Command('kit:list');
        $list->setDescription('List discovered Kits and their lifecycle state.')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($kits): int {
                try {
                    $descriptors = $kits->list();
                    usort($descriptors, static fn ($a, $b): int =>
                        strcasecmp($a->name(), $b->name()) ?: strcmp($a->name(), $b->name()));
                    if ($descriptors === []) {
                        $output->writeln('<comment>No Kits are installed or discovered.</comment>');
                        return Command::SUCCESS;
                    }
                    $table = new Table($output);
                    $table->setHeaders(['Kit', 'Status', 'Version', 'Source', 'Packages', 'Issues']);
                    foreach ($descriptors as $descriptor) {
                        $table->addRow([
                            self::safe($descriptor->name()),
                            self::safe($descriptor->status()),
                            self::safe($descriptor->version() ?? '-'),
                            self::safe($descriptor->sourceKind()),
                            count($descriptor->requires()),
                            self::safe($descriptor->errors() === [] ? '-'
                                : implode('; ', $descriptor->errors())),
                        ]);
                    }
                    $table->render();
                    return Command::SUCCESS;
                } catch (KitException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Kits could not be listed.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($list);

        $inspect = new Command('kit:inspect');
        $inspect->setDescription('Inspect Kit manifest, state, and ownership without executing Kit PHP.')
            ->addArgument('name', InputArgument::REQUIRED, 'Exact Kit name')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($kits): int {
                try {
                    $inspection = $kits->inspect((string) $input->getArgument('name'));
                    $descriptor = $inspection['descriptor'];
                    $output->writeln('<info>' . self::safe($descriptor->name()) . '</info>');
                    $output->writeln('Status: ' . self::safe($descriptor->status()));
                    $output->writeln('Version: ' . self::safe($descriptor->version() ?? '-'));
                    $output->writeln('Source: ' . self::safe($descriptor->sourceKind()));
                    $output->writeln('Required Packages: ' . self::safe($descriptor->requires() === []
                        ? 'none' : implode(', ', $descriptor->requires())));
                    $hooks = self::hookNames($descriptor->hooks());
                    $output->writeln('Lifecycle hooks: ' . self::safe($hooks === []
                        ? 'none' : implode(', ', $hooks)));
                    $owned = $inspection['owned_files'];
                    $output->writeln('Owned application files: ' . count($owned));
                    $counts = ['generated' => 0, 'migration' => 0, 'seeder' => 0,
                        'test' => 0, 'config' => 0, 'asset' => 0, 'modified' => 0];
                    foreach ($owned as $item) {
                        $kind = $item['kind'] ?? null;
                        if (is_string($kind) && array_key_exists($kind, $counts)) {
                            ++$counts[$kind];
                        }
                        if (($item['modified'] ?? false) === true) { ++$counts['modified']; }
                    }
                    $output->writeln('  Generated: ' . $counts['generated']
                        . ', Config: ' . $counts['config'] . ', Assets: ' . $counts['asset']);
                    $output->writeln('  Migrations: ' . $counts['migration']
                        . ', Seeders: ' . $counts['seeder'] . ', Tests: ' . $counts['test']);
                    $output->writeln('  Modified after publication: ' . $counts['modified']);
                    if ($descriptor->errors() !== []) {
                        $output->writeln('Issues: ' . self::safe(implode('; ', $descriptor->errors())));
                    }
                    $output->writeln('Kit entry code runs only during explicit lifecycle apply.');
                    return Command::SUCCESS;
                } catch (KitException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    $output->writeln('<error>Kit could not be inspected.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($inspect);

        foreach (['install', 'enable', 'disable', 'upgrade', 'remove'] as $operation) {
            $command = new Command('kit:' . $operation);
            $command->setDescription(self::description($operation));
            if ($operation === 'install') {
                $command->addArgument('source', InputArgument::REQUIRED,
                    'Local Kit directory');
            } else {
                $command->addArgument('name', InputArgument::REQUIRED, 'Exact Kit name');
                if ($operation === 'upgrade') {
                    $command->addArgument('source', InputArgument::REQUIRED,
                        'Local Kit directory');
                }
            }
            $command->addOption('preview', null, InputOption::VALUE_NONE,
                'Review changes without applying files, state, dependencies, or hooks');
            $command->addOption('yes', 'y', InputOption::VALUE_NONE,
                'Apply the reviewed plan without interactive confirmation');
            $command->setCode(static function (InputInterface $input, OutputInterface $output)
                use ($operation, $kits): int {
                try {
                    $plan = self::plan($operation, $input, $kits);
                    $output->writeln(ChangeRenderer::render($plan, 'SqueHub Kit Change Plan'));
                    if ($plan->hasConflicts()) {
                        $output->writeln('<error>Resolve Kit conflicts before applying this change.</error>');
                        return Command::FAILURE;
                    }
                    if ($input->getOption('preview')) {
                        $output->writeln('<comment>Preview only; no application files or state changed. '
                            . 'Lifecycle hooks were not executed.</comment>');
                        return Command::SUCCESS;
                    }
                    if (!$input->getOption('yes') && !self::confirmedAtTerminal($input, $output)) {
                        $output->writeln('<error>Use --yes to apply this reviewed Kit plan non-interactively.</error>');
                        return Command::FAILURE;
                    }
                    $result = $kits->apply($plan);
                    if (!$result->complete()) {
                        if ($result->recoveryPath !== null) {
                            $output->writeln('<error>Kit changes were incomplete; recovery files remain at '
                                . self::safe($result->recoveryPath) . '.</error>');
                        } else {
                            $output->writeln('<error>Kit change could not be fully verified at '
                                . self::safe($result->failed?->subject ?? 'the final state') . '.</error>');
                        }
                        return Command::FAILURE;
                    }
                    $output->writeln('<info>Kit ' . self::safe($operation) . ' completed.</info>');
                    return Command::SUCCESS;
                } catch (ChangeApplyException $exception) {
                    $result = $exception->result;
                    $output->writeln('<error>Kit change was incomplete: '
                        . count($result->applied) . ' applied, ' . count($result->unapplied)
                        . ' unapplied. Review the filesystem and Kit state.</error>');
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
                } catch (KitException $exception) {
                    $output->writeln('<error>' . self::safe($exception->getMessage()) . '</error>');
                    return Command::FAILURE;
                } catch (Throwable) {
                    // Filesystem and source errors may contain credentials or
                    // absolute paths; generic output preserves that boundary.
                    $output->writeln('<error>Kit operation failed.</error>');
                    return Command::FAILURE;
                }
            });
            $console->add($command);
        }
    }

    private static function description(string $operation): string
    {
        return match ($operation) {
            'install' => 'Install a Kit definition, disabled by default.',
            'enable' => 'Review and enable a Kit composition.',
            'disable' => 'Disable a Kit without deleting generated application files.',
            'upgrade' => 'Upgrade an installed Kit from an explicit source.',
            'remove' => 'Remove safely owned Kit source and application files.',
        };
    }

    private static function plan(string $operation, InputInterface $input, KitManager $kits): ChangePlan
    {
        return match ($operation) {
            'install' => $kits->planInstall((string) $input->getArgument('source')),
            'enable' => $kits->planEnable((string) $input->getArgument('name')),
            'disable' => $kits->planDisable((string) $input->getArgument('name')),
            'upgrade' => $kits->planUpgrade((string) $input->getArgument('name'),
                (string) $input->getArgument('source')),
            'remove' => $kits->planRemove((string) $input->getArgument('name')),
        };
    }

    /** A Symfony interactive input can still have noninteractive stdin. */
    private static function confirmedAtTerminal(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->isInteractive() || !defined('STDIN') || !function_exists('stream_isatty')
            || !@stream_isatty(STDIN)) { return false; }
        $output->write('<question>Apply this Kit change? [y/N] </question>');
        $answer = fgets(STDIN);
        return is_string($answer) && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
    }

    /** @param array<mixed> $hooks
     *  @return list<string>
     */
    private static function hookNames(array $hooks): array
    {
        $names = [];
        foreach ($hooks as $key => $value) {
            if (is_string($key)) { $names[] = $key; }
            elseif (is_string($value)) { $names[] = $value; }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** Console metadata must never become formatting or terminal controls. */
    private static function safe(string $value): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '');
    }
}
