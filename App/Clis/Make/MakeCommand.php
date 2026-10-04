<?php

declare(strict_types=1);

namespace App\Clis\Make;

use App\Changes\ChangeRenderer;
use App\Changes\ChangeApplyException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/** One CLI surface for the five generators; file planning and writes stay in Generator. */
final class MakeCommand extends Command
{
    public function __construct(private Generator $generator, private string $kind)
    {
        parent::__construct('make:' . $kind);
    }

    protected function configure(): void
    {
        $this->setDescription('Create a SqueHub ' . ucfirst($this->kind) . ' file.')
            ->addArgument('name', InputArgument::REQUIRED, 'Class name or snake_case migration name')
            ->addOption('package', null, InputOption::VALUE_REQUIRED,
                'Existing Package target (controllers, middleware, and models only)')
            ->addOption('preview', null, InputOption::VALUE_NONE,
                'Show the shared change plan without writing files')
            ->addOption('yes', 'y', InputOption::VALUE_NONE,
                'Confirm application without an interactive prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $package = $input->getOption('package');
            $plan = $this->generator->plan($this->kind, (string) $input->getArgument('name'),
                is_string($package) ? $package : null);
            $output->writeln(ChangeRenderer::render($plan));
            if ($input->getOption('preview')) {
                return $plan->hasConflicts() ? Command::FAILURE : Command::SUCCESS;
            }
            if ($plan->hasConflicts()) {
                return Command::FAILURE;
            }
            if (!$input->getOption('yes')) {
                // Symfony's interactive flag alone can be true for piped input.
                // A real TTY is required before asking a question, so CI never hangs.
                if (!$input->isInteractive() || !defined('STDIN') || !function_exists('stream_isatty')
                    || !@stream_isatty(STDIN)) {
                    $output->writeln('<error>Use --yes to apply.</error>');
                    return Command::FAILURE;
                }
                $helper = $this->getHelper('question');
                if (!$helper instanceof QuestionHelper || !$helper->ask($input, $output,
                    new ConfirmationQuestion('Apply this plan? [y/N] ', false))) {
                    $output->writeln('No changes have been applied.');
                    return Command::FAILURE;
                }
            }
            $result = $this->generator->apply($plan);
            if (!$result->verified) {
                $output->writeln('<error>Generated file could not be verified.</error>');
                return Command::FAILURE;
            }
            $output->writeln('Created ' . $plan->actions[0]->subject);
            return Command::SUCCESS;
        } catch (ChangeApplyException $exception) {
            $output->writeln('<error>Generated file may exist but could not be verified.</error>');
            if ($exception->result->recoveryPath !== null) {
                $output->writeln('Inspect ' . $exception->result->recoveryPath . ' before retrying.');
            }
            return Command::FAILURE;
        } catch (GeneratorException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
