<?php
namespace App\Clis\Make;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeController extends Command
{
    protected static $defaultName = 'make:controller';

    protected function configure()
    {
        $this->setDescription('Generate a new controller class in project/controllers/')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the controller (e.g., Blog/PostController)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = trim($input->getArgument('name'), '/\\');

        // Extract path and class name
        $segments = explode('/', str_replace('\\', '/', $inputPath));
        $className = array_pop($segments);

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*Controller$/', $className)) {
            $output->writeln("<error>❌ Invalid controller name. It should end with 'Controller' and start with a capital letter.</error>");
            return Command::FAILURE;
        }

        $subDir = implode('/', $segments);
        $controllerDir = BASE_DIR . '/project/Controllers' . ($subDir ? "/$subDir" : '');
        $controllerFile = "$controllerDir/{$className}.php";

        if (file_exists($controllerFile)) {
            $output->writeln("<comment>⚠ Controller already exists:</comment> $inputPath");
            return Command::FAILURE;
        }

        // Ensure directory exists
        if (!is_dir($controllerDir)) {
            mkdir($controllerDir, 0755, true);
        }

        // Build namespace based on subdirectories
        $namespaceParts = array_map('ucfirst', $segments);
        $namespace = 'project\\Controllers' . ($namespaceParts ? '\\' . implode('\\', $namespaceParts) : '');

        $template = <<<PHP
<?php
namespace {$namespace};

use App\Core\Controller;

class {$className} extends Controller
{
    public function index()
    {
        // TODO: Implement logic
    }
}
PHP;

        file_put_contents($controllerFile, $template);
        $output->writeln("<info>✔ Controller created:</info> project/controllers/" . ($subDir ? "$subDir/" : "") . "{$className}.php");
        return Command::SUCCESS;
    }
}
