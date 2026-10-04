<?php

declare(strict_types=1);

namespace App\Clis;

use App\Foundation\Application;
use App\Recovery\RecoveryPlanner;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Registers an inspection-only recovery inventory; it never restores data. */
final class RecoveryCommands
{
    public static function register(ConsoleApplication $console, Application $application): void
    {
        $command = new Command('recovery:plan');
        $command->setDescription('Inspect separate source, database, uploads, secrets, and Queue recovery boundaries.')
            ->addOption('source-bundle', null, InputOption::VALUE_REQUIRED,
                'Explicit portable source bundle to verify')
            ->addOption('database-snapshot', null, InputOption::VALUE_REQUIRED,
                'Operator-supplied database artifact to fingerprint')
            ->addOption('snapshot-driver', null, InputOption::VALUE_REQUIRED,
                'Declared driver of the supplied database artifact (sqlite or mysql)')
            ->addOption('snapshot-sha256', null, InputOption::VALUE_REQUIRED,
                'Expected SHA-256 checksum from an independent operator record')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print bounded machine-readable evidence')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($application): int {
                try {
                    $plan = (new RecoveryPlanner($application))->plan(
                        self::option($input, 'source-bundle'),
                        self::option($input, 'database-snapshot'),
                        self::option($input, 'snapshot-driver'),
                        self::option($input, 'snapshot-sha256'),
                    );
                    $output->writeln($input->getOption('json')
                        ? json_encode($plan->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                        : $plan->render());
                    return Command::SUCCESS;
                } catch (Throwable) {
                    // Filesystem and driver failures may contain physical paths
                    // or connection details; the CLI never prints their causes.
                    $output->writeln('<error>Recovery artifacts could not be inspected safely.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($command);
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
