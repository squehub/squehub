<?php
namespace App\Clis\Make;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeDumper extends Command
{
    protected static $defaultName = 'make:dumper';

    protected function configure()
    {
        $this->setDescription('Generate a new data dumpper class in database/dumper/')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the dumper class (e.g., AdminUserDumper)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*Dumper$/', $name)) {
            $output->writeln("<error>❌ Invalid name. Class must end with 'Dumper' and start with a capital letter.</error>");
            return Command::FAILURE;
        }

        $directory = BASE_DIR . '/database/dumper';
        $filePath = $directory . "/{$name}.php";

        if (file_exists($filePath)) {
            $output->writeln("<comment>⚠ Dumper already exists:</comment> $filePath");
            return Command::FAILURE;
        }

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $template = <<<PHP
<?php
namespace Database\Dumper;

use App\Core\Dumper;

class {$name} extends Dumper
{
    public function run()
    {
        // TODO: Write your dumping logic
    }

    public function rollback()
    {
        // TODO: Undo the dumped data
    }
}
PHP;

        file_put_contents($filePath, $template);
        $output->writeln("<info>✔ Dumpper created:</info> database/dumper/{$name}.php");
        return Command::SUCCESS;
    }
}
