<?php

declare(strict_types=1);

namespace App\Clis;

use App\Changes\ChangeRenderer;
use App\Upgrades\UpgradePreflight;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** A local, inert source comparison; an upgrade is never applied by this command. */
final class UpgradeCommands
{
    public static function register(ConsoleApplication $console, string $currentRoot): void
    {
        $check = new Command('upgrade:check');
        $check->setDescription('Compare the current project with a local target source tree without applying changes.')
            ->addArgument('target', InputArgument::REQUIRED, 'Local target source directory')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a bounded machine-readable report')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($currentRoot): int {
                try {
                    $report = (new UpgradePreflight())->inspect($currentRoot,
                        (string) $input->getArgument('target'));
                    if ($input->getOption('json')) {
                        $output->writeln(json_encode($report->toArray(),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
                    } else {
                        $output->writeln('SqueHub Upgrade Preflight');
                        $output->writeln('Status: ' . $report->status());
                        $output->writeln(ChangeRenderer::render($report->plan(), 'Proposed source changes'));
                        foreach ($report->findings() as $finding) {
                            $output->writeln(strtoupper($finding['status']) . ' '
                                . OutputFormatter::escape($finding['code']) . ' '
                                . self::safe($finding['subject']));
                        }
                        $output->writeln('Inspection only; no source files or database records were changed.');
                    }
                    return in_array($report->status(), ['blocked', 'unknown'], true)
                        ? Command::FAILURE : Command::SUCCESS;
                } catch (Throwable) {
                    // Discovery errors can include machine paths or source
                    // metadata; only a categorical failure crosses the CLI.
                    $output->writeln('<error>Upgrade preflight could not inspect the local source safely.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($check);
    }

    private static function safe(string $value): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '');
    }
}
