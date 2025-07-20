<?php
// app/clis/clis.php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use App\Core\EventManager; // EventManager import
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$application = new Application('Custom CLI', '1.0.0');

// Define the start command
$startCommand = new Command('start');
$startCommand->setDescription('Start the PHP built-in server.')
    ->addArgument('host', InputArgument::OPTIONAL, 'The host to bind to', 'localhost')
    ->addArgument('port', InputArgument::OPTIONAL, 'The port to bind to', '8000')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $host = $input->getArgument('host');
        $port = $input->getArgument('port');
        $documentRoot = __DIR__ . '/../../';

        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $output->writeln("<error>Invalid host.</error>");
            exit(1);
        }

        if (!is_numeric($port) || $port < 1024 || $port > 65535) {
            $output->writeln("<error>Invalid port. It should be a number between 1024 and 65535.</error>");
            exit(1);
        }

        $output->writeln("Starting PHP built-in server on http://$host:$port...");
        $command = "php -S $host:$port -t $documentRoot";
        exec($command, $outputLines, $returnVar);

        if ($returnVar !== 0) {
            $output->writeln("<error>Failed to start server.</error>");
            $output->writeln("Output:\n" . implode("\n", $outputLines));
            exit(1);
        }
        $output->writeln("Server started at http://$host:$port");
    });

$application->add($startCommand);

// Define the migrate command
$migrateCommand = new Command('migrate');
$migrateCommand->setDescription('Run new database migrations.')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $pdo = App\Core\Database::connect();
        $migrationsDir = BASE_DIR . "/database/migrations/";

        // Ensure the migrations table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $executed = $pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
        $files = scandir($migrationsDir);

        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && !in_array($file, $executed)) {
                require_once $migrationsDir . $file;

                // Extract class name from filename
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $parts = explode('_', $filename);
                $className = implode('', array_map('ucfirst', array_slice($parts, 3))); // Start from "create"

                if (class_exists($className)) {
                    $migration = new $className();

                    if (method_exists($migration, 'up')) {
                        $migration->up($pdo);

                        $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (:migration)");
                        $stmt->execute(['migration' => $file]);

                        $output->writeln("<info>✔ Migrated:</info> $file");
                    } else {
                        $output->writeln("<error>✖ Migration class '$className' is missing the 'up()' method.</error>");
                    }
                } else {
                    $output->writeln("<error>✖ Migration class '$className' not found in $file.</error>");
                }
            }
        }
    });

$application->add($migrateCommand);




$rollbackCommand = new Command('migrate:rollback');
$rollbackCommand->setDescription('Rollback the last migration.')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $pdo = App\Core\Database::connect();
        
        // Fetch the last migration
        $stmt = $pdo->query("SELECT migration FROM migrations ORDER BY id DESC LIMIT 1");
        $migrationFile = $stmt->fetchColumn();
        
        if (!$migrationFile) {
            $output->writeln("<comment>No migrations to rollback.</comment>");
            return;
        }

        // Load and run the migration's `down()` method
        $migrationPath = BASE_DIR . "/database/migrations/$migrationFile";
        
        if (!file_exists($migrationPath)) {
            $output->writeln("<error>Migration file '$migrationFile' not found.</error>");
            return;
        }

        require_once $migrationPath;

        // Extract class name properly
        $filename = pathinfo($migrationFile, PATHINFO_FILENAME);
        $parts = explode('_', $filename);
        $className = implode('', array_map('ucfirst', array_slice($parts, 3)));

        if (!class_exists($className)) {
            $output->writeln("<error>Migration class '$className' not found in $migrationFile.</error>");
            return;
        }

        $migration = new $className();

        if (!method_exists($migration, 'down')) {
            $output->writeln("<error>Migration '$className' does not have a 'down()' method.</error>");
            return;
        }

        // Run the `down()` method
        $migration->down($pdo);

        // Remove migration record from database
        $pdo->prepare("DELETE FROM migrations WHERE migration = :migration")
            ->execute(['migration' => $migrationFile]);

        $output->writeln("<info>✔ Rolled back:</info> $migrationFile");
    });

$application->add($rollbackCommand);



$resetCommand = new Command('migrate:reset');
$resetCommand->setDescription('Reset the database by rolling back all migrations.')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $pdo = App\Core\Database::connect();
        $migrations = $pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($migrations as $migrationFile) {
            require_once BASE_DIR . "/database/migrations/$migrationFile";
            $migrationClass = pathinfo($migrationFile, PATHINFO_FILENAME);

            if (class_exists($migrationClass)) {
                $migration = new $migrationClass();
                $migration->down($pdo);
            }
        }

        $pdo->exec("TRUNCATE TABLE migrations");
        $output->writeln("<info>✔ All migrations rolled back.</info>");
    });

$application->add($resetCommand);

$backupCommand = new Command('backup:dev');
$backupCommand->setDescription('Backup dev/, config/, database/, views/, .env, and config.php into a zip file.')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $backupDir = BASE_DIR . '/storage/backups/dev';
        $timestamp = date('Ymd_His');
        $zipFile = "$backupDir/squehub_dev_backup_$timestamp.zip";

        // Ensure backup directory exists
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            $output->writeln("<error>✖ Failed to create zip archive.</error>");
            return;
        }

        // Helper to recursively add folders
        $addFolderToZip = function ($folderPath, $zipPath = '') use (&$zip, &$addFolderToZip) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderPath),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = $zipPath . '/' . substr($filePath, strlen($folderPath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
        };

        // Add dev/, views/
        $devPath = BASE_DIR . '/project';
        $configPath = BASE_DIR . '/config';
        $databasePath = BASE_DIR . '/database';
        $viewsPath = BASE_DIR . '/views';

        if (is_dir($devPath))   $addFolderToZip($devPath, 'project');
        if (is_dir($configPath)) $addFolderToZip($configPath, 'config');
        if (is_dir($databasePath)) $addFolderToZip($databasePath, 'database');
        if (is_dir($viewsPath)) $addFolderToZip($viewsPath, 'views');


        // Add .env and config.php
        if (file_exists(BASE_DIR . '/.env')) {
            $zip->addFile(BASE_DIR . '/.env', '.env');
        }
        if (file_exists(BASE_DIR . '/config.php')) {
            $zip->addFile(BASE_DIR . '/config.php', 'config.php');
        }

        $zip->close();
        $output->writeln("<info>✔ Backup created:</info> $zipFile");
    });

$application->add($backupCommand);



$dumperCommand = new Command('dump:run');
$dumperCommand->setDescription('Run a specified Dumper class from database/dumper/')
    ->addArgument('class', InputArgument::REQUIRED, 'The name of the Dumper class to run (e.g., UsersDumper)')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $class = $input->getArgument('class');

        // Run the existing script with the class name as an argument
        $cmd = PHP_BINARY . ' ' . BASE_DIR . '/app/clis/dump/dump.php ' . escapeshellarg($class);

        passthru($cmd, $statusCode);

        return $statusCode === 0 ? Command::SUCCESS : Command::FAILURE;
    });

$application->add($dumperCommand);



$rollbackCommand = new Command('dump:rollback');
$rollbackCommand->setDescription('Rollback a Dumper class from database/dumper/')
    ->addArgument('class', InputArgument::REQUIRED, 'The name of the Dumper class to rollback')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $class = $input->getArgument('class');
        $file = BASE_DIR . "/database/dumper/{$class}.php";

        if (!file_exists($file)) {
            $output->writeln("<error>❌ File for [$class] not found in database/dumper/.</error>");
            return Command::FAILURE;
        }

        require_once $file;

        // Prepend namespace here:
        $fqcn = "Database\\Dumper\\{$class}";

        if (!class_exists($fqcn)) {
            $output->writeln("<error>❌ Class [$fqcn] not found after requiring file.</error>");
            return Command::FAILURE;
        }

        $instance = new $fqcn();

        if (!($instance instanceof \App\Core\Dumper)) {
            $output->writeln("<error>❌ [$fqcn] must extend App\\Core\\Dumper.</error>");
            return Command::FAILURE;
        }

        if (!method_exists($instance, 'rollback')) {
            $output->writeln("<error>❌ Method rollback() not found in [$fqcn].</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>⏪ Rolling back:</info> $fqcn");

        $instance->rollback();

        $output->writeln("<info>✔ Rollback complete.</info>");
        return Command::SUCCESS;
    });

$application->add($rollbackCommand);



// Register make:controller command
$application->add(new \App\Clis\Make\MakeController());
$application->add(new \App\Clis\Make\MakeModel());
$application->add(new \App\Clis\Make\MakeMigration());
$application->add(new \App\Clis\Make\MakeMiddleware());
$application->add(new \App\Clis\Make\MakeDumper());



$helpCommand = new Command('help');
$helpCommand->setDescription('Show help information.')
    ->setCode(function (InputInterface $input, OutputInterface $output) use ($application) {
        $output->writeln("<info>Available CLI Commands:</info>");
        $output->writeln("");

        foreach ($application->all() as $command) {
            // Skip the 'list' command Symfony adds by default
            if ($command->getName() === 'list') {
                continue;
            }

            $output->writeln("  <comment>{$command->getName()}</comment> - {$command->getDescription()}");
        }

        $output->writeln("\nRun <info>php app/clis/clis.php [command]</info> to execute a command.");
    });

$application->add($helpCommand);


// Run the application
$application->run();
