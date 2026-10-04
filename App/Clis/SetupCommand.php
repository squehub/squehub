<?php

declare(strict_types=1);

namespace App\Clis;

use App\Changes\ChangeApplyException;
use App\Changes\ChangeRenderer;
use App\Foundation\Application;
use App\Foundation\Environment;
use App\Health\HealthManager;
use App\Health\HealthReport;
use App\Setup\SetupException;
use App\Setup\SetupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Throwable;

/**
 * Guides optional first-run configuration through a reviewed ChangePlan.
 * Setup never accepts secrets as CLI arguments and never runs migrations,
 * Seeders, or Package lifecycle operations. Doctor owns verification.
 */
final class SetupCommand extends Command
{
    public function __construct(private SetupManager $setup, private Application $app)
    {
        parent::__construct('setup');
    }

    protected function configure(): void
    {
        $this->setDescription('Inspect, plan, and optionally apply SqueHub application setup.')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED,
                'Deployment environment: development, local, staging, or production')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Database driver: sqlite or mysql')
            ->addOption('preview', null, InputOption::VALUE_NONE,
                'Show the setup plan without changing project files or running Doctor')
            ->addOption('yes', 'y', InputOption::VALUE_NONE,
                'Apply the reviewed plan without an interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('SqueHub Setup');
        try {
            $state = $this->setup->inspect();
            $this->showState($state, $output);
            $environment = $input->getOption('environment');
            $database = $input->getOption('database');
            if ($environment !== null && !is_string($environment)
                || $database !== null && !is_string($database)) {
                throw new SetupException('SqueHub Setup options are invalid.');
            }

            $environment = $this->select($input, $output, $environment,
                $state['environment_file'] === 'missing' || in_array($state['environment'], ['missing', 'invalid'], true),
                'environment', ['local', 'development', 'staging', 'production']);
            $database = $this->select($input, $output, $database,
                $state['environment_file'] === 'missing' || in_array($state['database'], ['missing', 'invalid'], true),
                'database', ['sqlite', 'mysql']);

            $plan = $this->setup->plan($environment, $database);
            if ($plan->actions === []) {
                $output->writeln('No required setup changes detected.');
                $output->writeln('Migrations and Seeders are not run by SqueHub Setup.');
                return $input->getOption('preview') ? Command::SUCCESS
                    : $this->showDoctor($this->doctor(false), $output, false);
            }

            $output->writeln(ChangeRenderer::render($plan, 'SqueHub Setup Plan'));
            if ($input->getOption('preview')) {
                return $plan->hasConflicts() ? Command::FAILURE : Command::SUCCESS;
            }
            if ($plan->hasConflicts()) {
                return Command::FAILURE;
            }
            if (!$input->getOption('yes')) {
                if (!$this->interactive($input)) {
                    $output->writeln('<error>Use --yes to apply the reviewed plan.</error>');
                    return Command::FAILURE;
                }
                $helper = $this->getHelper('question');
                if (!$helper instanceof QuestionHelper || !$helper->ask($input, $output,
                    new ConfirmationQuestion('Apply these changes? [y/N] ', false))) {
                    $output->writeln('No changes have been applied.');
                    return Command::SUCCESS;
                }
            }

            $result = $this->setup->apply($plan);
            if (!$result->complete()) {
                $output->writeln('<error>Setup application was incomplete; inspect the project before retrying.</error>');
                return Command::FAILURE;
            }
            foreach ($result->applied as $action) {
                $output->writeln(strtoupper($action->kind) . ' ' . $action->subject);
            }
            $output->writeln('Setup changes applied. Migrations and Seeders were not run.');
            try {
                return $this->showDoctor($this->doctor(true), $output, true);
            } catch (Throwable) {
                // A verification failure does not undo the completed file
                // changes. Do not expose bootstrap or service exceptions.
                $output->writeln('<error>Setup changes were applied, but Doctor verification could not complete.</error>');
                return Command::FAILURE;
            }
        } catch (ChangeApplyException $exception) {
            foreach ($exception->result->applied as $action) {
                $output->writeln('Applied: ' . strtoupper($action->kind) . ' ' . $action->subject);
            }
            if ($exception->result->recoveryPath !== null) {
                $output->writeln('Review ' . $exception->result->recoveryPath . ' before retrying.');
            }
            $output->writeln('<error>Setup was partially applied. Review the reported changes and rerun Setup.</error>');
            return Command::FAILURE;
        } catch (SetupException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return Command::FAILURE;
        } catch (Throwable) {
            // Bootstrap, Doctor, and application exceptions may contain secrets.
            $output->writeln('<error>SqueHub Setup could not complete. Review application configuration and retry.</error>');
            return Command::FAILURE;
        }
    }

    /**
     * A piped console may still report itself as interactive. A real TTY is
     * required so unattended installs cannot block on a question.
     *
     * @param list<string> $choices
     */
    private function select(InputInterface $input, OutputInterface $output, ?string $selected,
        bool $required, string $name, array $choices): ?string
    {
        if ($selected !== null || !$required) {
            return $selected;
        }
        if (!$this->interactive($input)) {
            throw new SetupException('Choose missing settings with --environment=local --database=sqlite, '
                . 'or configure .env manually before running SqueHub Setup.');
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new SetupException('SqueHub Setup cannot prompt for a required choice.');
        }
        $question = new ChoiceQuestion('Select ' . $name . ': ', $choices);
        $answer = $helper->ask($input, $output, $question);
        if (!is_string($answer)) {
            throw new SetupException('SqueHub Setup choice is invalid.');
        }
        return $answer;
    }

    private function interactive(InputInterface $input): bool
    {
        return $input->isInteractive() && defined('STDIN') && function_exists('stream_isatty')
            && @stream_isatty(STDIN);
    }

    /** @param array{environment_file:string,app_key:string,environment:string,debug:string,database:string,storage:string} $state */
    private function showState(array $state, OutputInterface $output): void
    {
        foreach (['environment_file' => 'Environment file', 'app_key' => 'APP_KEY',
            'environment' => 'Environment', 'debug' => 'Debug', 'database' => 'Database',
            'storage' => 'Storage'] as $key => $label) {
            $output->writeln(sprintf('%-18s %s', $label, $state[$key]));
        }
    }

    /**
     * Reboot after a write so Doctor sees the new .env instead of the CLI's
     * earlier Config snapshot. Only values published from the old .env are
     * removed; genuine shell variables retain their intended precedence.
     */
    private function doctor(bool $fresh): HealthReport
    {
        $application = $this->app;
        if ($fresh) {
            foreach ($application->container()->make(Environment::class)->publishedDotenvKeys() as $key) {
                unset($_ENV[$key]);
            }
            $bootstrap = $application->basePath('Bootstrap/App.php');
            if (!is_file($bootstrap)) {
                throw new SetupException('Application bootstrap is unavailable for Doctor verification.');
            }
            $application = require $bootstrap;
            if (!$application instanceof Application) {
                throw new SetupException('Application bootstrap did not return a valid Application.');
            }
        }
        return $application->container()->make(HealthManager::class)->doctor();
    }

    private function showDoctor(HealthReport $report, OutputInterface $output, bool $applied): int
    {
        $output->writeln('SqueHub Doctor');
        foreach ($report->results() as $result) {
            $output->writeln(sprintf('%-13s %-16s %-8s %s', $result->category(),
                $result->name(), strtoupper($result->status()), $result->summary()));
        }
        if ($report->hasFailures()) {
            $output->writeln($applied ? 'SETUP APPLIED — DOCTOR FAILURES REMAIN'
                : 'SETUP NEEDS ATTENTION — DOCTOR FAILURES REMAIN');
            return Command::FAILURE;
        }
        $output->writeln($report->hasWarnings() ? ($applied
            ? 'SETUP APPLIED — DOCTOR WARNINGS REMAIN' : 'SETUP COMPLETE — DOCTOR WARNINGS REMAIN')
            : 'SETUP COMPLETE');
        return Command::SUCCESS;
    }
}
