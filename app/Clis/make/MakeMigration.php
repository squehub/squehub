<?php
namespace App\Clis\Make;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeMigration extends Command
{
    protected static $defaultName = 'make:migration';

    protected function configure()
    {
        $this->setDescription('Generate a new migration file in database/migrations/')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the migration (e.g., create_users_table)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            $output->writeln("<error>❌ Invalid migration name. Use snake_case like create_users_table.</error>");
            return Command::FAILURE;
        }

        $date = date('Y_m_d');
        $filename = "{$date}_{$name}.php";
        $filepath = BASE_DIR . "/database/migrations/{$filename}";

        // Convert name to class: create_users_table → CreateUsersTable
        $className = implode('', array_map('ucfirst', explode('_', $name)));

        $template = <<<PHP
<?php

class {$className}
{
    public function up(\$pdo)
    {
        // TODO: Write your migration logic here (CREATE TABLE, etc.)
    }

    public function down(\$pdo)
    {
        // TODO: Reverse the migration (DROP TABLE, etc.)
    }
}
PHP;

        if (!is_dir(BASE_DIR . '/database/migrations')) {
            mkdir(BASE_DIR . '/database/migrations', 0755, true);
        }

        file_put_contents($filepath, $template);
        $output->writeln("<info>✔ Migration created:</info> database/migrations/{$filename}");

        return Command::SUCCESS;
    }
}
