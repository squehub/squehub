<?php

declare(strict_types=1);

namespace App\Clis;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand as SymfonyHelpCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists SqueHub commands by default while retaining Symfony's command help.
 *
 * Symfony passes a selected command through setCommand() for `--help`. That
 * path must keep using the parent implementation, even without a positional
 * command name in the HelpCommand input.
 */
final class HelpCommand extends SymfonyHelpCommand
{
    private bool $selectedCommand = false;

    protected function configure(): void
    {
        parent::configure();

        $this->setAliases(['h']);
        $this->setDescription('List commands or display help for one command');
        $this->getDefinition()->getArgument('command_name')->setDefault(null);
        $this->setHelp(<<<'HELP'
            Run <info>%command.full_name%</info> to list all available commands.
            Run <info>%command.full_name% route:list</info> for command-specific help.
            <info>php squehub h</info> is a short form of the command list.
            HELP);
    }

    public function setCommand(Command $command): void
    {
        $this->selectedCommand = true;
        parent::setCommand($command);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->selectedCommand && $input->getArgument('command_name') === null) {
            // Delegate to Symfony's list command so formats and future command
            // registrations stay identical to `php squehub list`.
            $arguments = ['--format' => $input->getOption('format')];
            if ($input->getOption('raw')) {
                $arguments['--raw'] = true;
            }

            return $this->getApplication()->get('list')->run(new ArrayInput($arguments), $output);
        }

        try {
            return parent::execute($input, $output);
        } finally {
            $this->selectedCommand = false;
        }
    }
}
