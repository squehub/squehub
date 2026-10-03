<?php

declare(strict_types=1);

namespace App\Clis\Make;

use App\Changes\ChangeApplyException;
use App\Changes\ChangeRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/** Reviews one multi-file Feature Blueprint before any application source is written. */
final class FeatureBlueprintCommand extends Command
{
    public function __construct(private FeatureBlueprintGenerator $generator)
    {
        parent::__construct('make:feature');
    }

    protected function configure(): void
    {
        $this->setDescription('Plan a Model, migration, controller, validation, resource, route, and test.')
            ->setHelp(<<<'HELP'
                Use a singular capitalized feature name, for example Post. The default
                table and route keep that singular spelling; --table and --route
                choose explicit alternatives. --package targets an existing Package
                using its exact name. --preview performs no writes. Review the plan
                and use --yes to apply without an interactive terminal.

                The generated migration is never executed by make:feature.
                Seeders are never run by make:feature.
                HELP)
            ->addArgument('name', InputArgument::REQUIRED, 'Singular feature class name, for example Post')
            ->addOption('package', null, InputOption::VALUE_REQUIRED,
                'Existing Package target, using its exact canonical spelling')
            ->addOption('table', null, InputOption::VALUE_REQUIRED,
                'Explicit database table name (defaults to singular snake_case; Package-prefixed)')
            ->addOption('route', null, InputOption::VALUE_REQUIRED,
                'Explicit URL path (defaults to the singular lowercase feature path)')
            ->addOption('preview', null, InputOption::VALUE_NONE,
                'Show the complete change plan without writing files')
            ->addOption('yes', 'y', InputOption::VALUE_NONE,
                'Apply the reviewed plan without an interactive prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $plan = $this->generator->plan(
                (string) $input->getArgument('name'),
                $this->optionalString($input, 'package'),
                $this->optionalString($input, 'table'),
                $this->optionalString($input, 'route'),
            );
            $output->writeln(ChangeRenderer::render($plan, 'SqueHub Feature Blueprint'));
            $output->writeln('Migration execution: NOT INCLUDED');
            $output->writeln('Seeder execution: NOT INCLUDED');
            $output->writeln('Validation: empty rules skeleton; define rules before accepting writes.');
            if ($input->getOption('preview')) {
                return $plan->hasConflicts() ? Command::FAILURE : Command::SUCCESS;
            }
            if ($plan->hasConflicts()) {
                return Command::FAILURE;
            }
            if (!$input->getOption('yes')) {
                // Piped input is not an interactive approval. Explicit --yes is
                // required when no terminal can display and confirm the plan.
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
                $output->writeln('<error>Generated files could not all be verified.</error>');
                return Command::FAILURE;
            }
            foreach ($result->applied as $action) {
                $output->writeln('Created ' . $action->subject);
            }
            return Command::SUCCESS;
        } catch (ChangeApplyException $exception) {
            $output->writeln('<error>Some generated files may exist; inspect the reported outcome before retrying.</error>');
            if ($exception->result->recoveryPath !== null) {
                $output->writeln('Inspect ' . $exception->result->recoveryPath . ' before retrying.');
            }
            return Command::FAILURE;
        } catch (GeneratorException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function optionalString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        return is_string($value) ? $value : null;
    }
}
