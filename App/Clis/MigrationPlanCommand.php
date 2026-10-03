<?php

declare(strict_types=1);

namespace App\Clis;

use App\Changes\ChangeRenderer;
use App\Database\Migrations\MigrationPlanner;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Publish a source-only migration review. This command never loads a migration,
 * opens a database connection, or claims that a discovered file is pending.
 */
final class MigrationPlanCommand
{
    public static function register(ConsoleApplication $console, string $root): void
    {
        $command = new Command('migrate:plan');
        $command->setDescription('Review migration source without running PHP or contacting the database.')
            ->addOption('json', null, InputOption::VALUE_NONE,
                'Print the machine-readable Change Plan.')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($root): int {
                try {
                    $plan = (new MigrationPlanner())->plan($root);
                    if ($input->getOption('json')) {
                        $output->writeln(json_encode($plan->toArray(),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            OutputInterface::OUTPUT_RAW);
                    } else {
                        $output->writeln(ChangeRenderer::render($plan,
                            'SqueHub Migration Source Plan'));
                    }
                    return $plan->hasConflicts() ? Command::FAILURE : Command::SUCCESS;
                } catch (Throwable) {
                    // Filesystem and parser errors may contain private paths or
                    // source text; the CLI reports only a categorical failure.
                    $output->writeln('<error>Migration source could not be inspected safely.</error>');
                    return Command::FAILURE;
                }
            });
        $console->add($command);
    }
}
