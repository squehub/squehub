<?php

declare(strict_types=1);

namespace App\Clis;

use App\Changes\ChangeRenderer;
use App\Foundation\Application as SqueHubApplication;
use App\Frontend\Build\FrontendBuild;
use App\Frontend\Build\FrontendBuildException;
use App\Profiles\ProfileException;
use App\Profiles\ProfileManager;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Optional frontend tooling. Every mutating profile command prints the shared
 * ChangePlan, and a noninteractive caller must opt in with --yes. Status and
 * help never install packages or start Node.
 */
final class FrontendCommands
{
    public static function register(ConsoleApplication $cli, SqueHubApplication $app): void
    {
        $manager = new ProfileManager($app->basePath());
        $inspect = new Command('profile:inspect');
        $inspect->setDescription('Inspect the selected frontend application profile.')
            ->setHelp("Node required: no.\nWrites files: no.\nStarts processes: no.\n"
                . 'Development-only: no; profile inspection is available in any environment.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print safe machine-readable status.')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($manager): int {
                try {
                    $status = $manager->inspect();
                    $status['available'] = ProfileManager::available();
                    if ($input->getOption('json')) {
                        $output->writeln(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    } else {
                        $output->writeln('Frontend profile: ' . ($status['name'] ?? 'none'));
                        $output->writeln('Owned files: ' . ($status['valid'] ? 'valid' : 'changed or invalid'));
                        $output->writeln('SPA navigation: ' . ($status['spa'] ? 'selected' : 'off'));
                        $output->writeln('Available: ' . implode(', ', ProfileManager::available()));
                    }
                    return $status['valid'] ? Command::SUCCESS : Command::FAILURE;
                } catch (ProfileException) {
                    $output->writeln('<error>Frontend profile could not be inspected safely.</error>');
                    return Command::FAILURE;
                }
            });
        $cli->add($inspect);

        foreach (['apply', 'remove'] as $operation) {
            $command = new Command('profile:' . $operation);
            $command->setDescription($operation === 'apply'
                ? 'Review and apply an optional Vite, React, or Vue profile.'
                : 'Review removal of unmodified frontend profile files.')
                ->setHelp($operation === 'apply'
                    ? "Node required: no; this command never installs npm packages.\n"
                        . "Writes files: yes, only after a reviewed plan is confirmed; --preview writes nothing.\n"
                        . "Starts processes: no.\nDevelopment-only: no; profile composition is a source change."
                    : "Node required: no.\n"
                        . "Writes files: yes, removing unmodified owned files and restoring frontend configuration; --preview writes nothing.\n"
                        . "Starts processes: no.\nDevelopment-only: no; profile removal is a source change.")
                ->addArgument('name', InputArgument::REQUIRED, 'vite, react, or vue')
                ->addOption('preview', null, InputOption::VALUE_NONE, 'Show a no-write ChangePlan.')
                ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply the reviewed plan noninteractively.');
            if ($operation === 'apply') {
                $command->addOption('spa', null, InputOption::VALUE_NONE,
                    'Select the safe browser-navigation SPA fallback for this profile.');
            }
            $command->setCode(static function (InputInterface $input, OutputInterface $output)
                use ($manager, $operation): int {
                try {
                    $name = (string) $input->getArgument('name');
                    $plan = $operation === 'apply'
                        ? $manager->planInstall($name, (bool) $input->getOption('spa'))
                        : $manager->planRemove($name);
                    $output->writeln(ChangeRenderer::render($plan, 'SqueHub Frontend Profile'));
                    if ($input->getOption('preview')) {
                        return $plan->hasConflicts() ? Command::FAILURE : Command::SUCCESS;
                    }
                    if ($plan->hasConflicts()) return Command::FAILURE;
                    if (!$input->getOption('yes')) {
                        if (!$input->isInteractive() || !defined('STDIN')
                            || !function_exists('stream_isatty') || !@stream_isatty(STDIN)) {
                            $output->writeln('<error>Use --yes to apply the reviewed plan.</error>');
                            return Command::FAILURE;
                        }
                        $question = new ConfirmationQuestion('Apply this profile plan? [y/N] ', false);
                        $helper = new QuestionHelper();
                        if (!$helper->ask($input, $output, $question)) {
                            $output->writeln('No changes have been applied.');
                            return Command::FAILURE;
                        }
                    }
                    $result = $manager->apply($plan);
                    if (!$result->complete()) return Command::FAILURE;
                    $output->writeln('Frontend profile ' . $operation . ' complete.');
                    if ($operation === 'apply') {
                        $output->writeln('Run npm install in Project/Frontend, then php squehub frontend:build.');
                    }
                    return Command::SUCCESS;
                } catch (ProfileException) {
                    $output->writeln('<error>Frontend profile operation failed safely; review project files before retrying.</error>');
                    return Command::FAILURE;
                }
            });
            $cli->add($command);
        }

        $status = new Command('frontend:status');
        $status->setDescription('Inspect selected frontend adapter and build availability.')
            ->setHelp("Node required: no; installed tools are detected without running them.\n"
                . "Writes files: no.\nStarts processes: no; --probe only checks the configured loopback port.\n"
                . 'Development-only: no; build availability may be inspected in any environment.')
            ->addOption('probe', null, InputOption::VALUE_NONE,
                'Probe the configured local development-server port.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print safe machine-readable status.')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($app): int {
                try {
                    $result = (new FrontendBuild($app))->status((bool) $input->getOption('probe'));
                    if ($input->getOption('json')) {
                        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    } else {
                        foreach ($result as $key => $value) {
                            $output->writeln(str_replace('_', ' ', $key) . ': '
                                . ($value === null ? 'not checked' : (is_bool($value) ? ($value ? 'yes' : 'no') : $value)));
                        }
                    }
                    return Command::SUCCESS;
                } catch (FrontendBuildException) {
                    $output->writeln('<error>Frontend status is unavailable.</error>');
                    return Command::FAILURE;
                }
            });
        $cli->add($status);

        $build = new Command('frontend:build');
        $build->setDescription('Build the explicitly selected local Vite frontend.')
            ->setHelp("Node required: yes, with the selected local Vite installation.\n"
                . "Writes files: yes, the configured public frontend build output and manifest.\n"
                . "Starts processes: yes, one bounded local Vite build subprocess.\n"
                . 'Development-only: no; a production release may build its assets before deployment.')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($app): int {
                try {
                    $fingerprint = (new FrontendBuild($app))->build();
                    $output->writeln('Frontend build verified: sha256:' . substr($fingerprint, 0, 16));
                    return Command::SUCCESS;
                } catch (FrontendBuildException $exception) {
                    $output->writeln('<error>' . $exception->getMessage() . '</error>');
                    return Command::FAILURE;
                }
            });
        $cli->add($build);
    }
}
