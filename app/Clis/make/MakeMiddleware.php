<?php
namespace App\Clis\Make;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MakeMiddleware extends Command
{
    protected static $defaultName = 'make:middleware';

    protected function configure(): void
    {
        $this->setDescription('Generate a new middleware class in project/Middleware/')
             ->addArgument('name', InputArgument::REQUIRED, 'The name of the middleware (e.g., AuthMiddleware or Auth/AdminMiddleware)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameInput = $input->getArgument('name');

        // Normalize name and extract class
        $parts = explode('/', str_replace('\\', '/', $nameInput));
        $className = array_pop($parts);

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $className)) {
            $output->writeln("<error>❌ Invalid middleware class name. It must start with a capital letter and contain only letters, numbers, or underscores.</error>");
            return Command::FAILURE;
        }

        // Determine path
        $subPath = implode('/', $parts);
        $middlewareDir = BASE_DIR . '/project/Middleware' . ($subPath ? "/$subPath" : '');
        $middlewareFile = "$middlewareDir/{$className}.php";

        if (file_exists($middlewareFile)) {
            $output->writeln("<comment>⚠ Middleware already exists:</comment> $middlewareFile");
            return Command::FAILURE;
        }

        if (!is_dir($middlewareDir)) {
            mkdir($middlewareDir, 0755, true);
        }

        $namespace = 'Project\\Middleware' . ($subPath ? '\\' . str_replace('/', '\\', $subPath) : '');

        $template = <<<PHP
<?php
namespace {$namespace};

use App\Core\MiddlewareHandler;

class {$className} extends MiddlewareHandler
{
    public function handle(\$request, \$next)
    {
        // TODO: Write your middleware logic here

        return \$next(\$request);
    }
}
PHP;

        file_put_contents($middlewareFile, $template);

        $output->writeln("<info>✔ Middleware created:</info> project/Middleware/" . ($subPath ? "$subPath/" : "") . "{$className}.php");
        return Command::SUCCESS;
    }
}
