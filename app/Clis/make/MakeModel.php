<?php
namespace App\Clis\Make;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeModel extends Command
{
    protected static $defaultName = 'make:model';

    protected function configure()
    {
        $this->setDescription('Generate a new model class in project/Models/')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the model (e.g., User or Blog/Post)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawInput = $input->getArgument('name');
        $segments = explode('/', str_replace('\\', '/', $rawInput));
        $modelName = array_pop($segments);
        $relativePath = implode('/', $segments);
        $namespacePart = implode('\\', $segments);

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $modelName)) {
            $output->writeln("<error>❌ Invalid model name. It must start with a capital letter and contain only letters, numbers, or underscores.</error>");
            return Command::FAILURE;
        }

        $baseDir = BASE_DIR . '/project/Models';
        $targetDir = $relativePath ? "$baseDir/$relativePath" : $baseDir;
        $targetFile = "$targetDir/$modelName.php";

        if (file_exists($targetFile)) {
            $output->writeln("<comment>⚠ Model already exists:</comment> $rawInput");
            return Command::FAILURE;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $namespace = 'Project\\Models' . ($namespacePart ? '\\' . $namespacePart : '');

        $template = <<<PHP
<?php
namespace $namespace;

use App\Core\Model;

class $modelName extends Model
{
    // Define table name if different from default
    // protected static \$table = 'your_table';

    // Define primary key if not 'id'
    // protected static \$primaryKey = 'id';
}
PHP;

        file_put_contents($targetFile, $template);

        $output->writeln("<info>✔ Model created:</info> project/models/" . ($relativePath ? "$relativePath/" : "") . "$modelName.php");
        return Command::SUCCESS;
    }
}
