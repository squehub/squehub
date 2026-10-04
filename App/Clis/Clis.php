<?php
/** Legacy CLI entry point backed by the Application and modern command services. */
// An already bootstrapped application lets a project host the CLI without
// changing the framework bootstrap or its configured database connection.
$squehubApp = $squehubApp ?? require __DIR__ . '/../../Bootstrap/App.php';
require_once __DIR__ . '/../../config.php';
$squehubMcpBootstrapBufferLevel = $squehubMcpBootstrapBufferLevel ?? null;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use App\Clis\FileLookup;
use App\Clis\HelpCommand;
use App\Clis\SetupCommand;
use App\Clis\FrontendCommands;
use App\Clis\PackageCommands;
use App\Clis\KitCommands;
use App\Clis\RecoveryCommands;
use App\Clis\BundleCommands;
use App\Clis\UpgradeCommands;
use App\Clis\MigrationPlanCommand;
use App\Clis\Make\Generator;
use App\Clis\Make\MakeCommand;
use App\Clis\Make\FeatureBlueprintCommand;
use App\Clis\Make\FeatureBlueprintGenerator;
use App\Dev\DevelopmentServer;
use App\Dev\DevException;
use App\Dev\DevSession;
use App\Support\SecureRandom;
use App\Foundation\FrameworkVersion;
$application = new Application('SqueHub', FrameworkVersion::CURRENT);
$application->add(new HelpCommand());
$application->add(new SetupCommand(new \App\Setup\SetupManager(BASE_DIR), $squehubApp));

// Print a fresh key only. This command never reads or rewrites APP_KEY/.env.
$keyGenerateCommand = new Command('key:generate');
$keyGenerateCommand->setDescription('Print a new 256-bit application key for APP_KEY.')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        $output->writeln(SecureRandom::applicationKey());
        return Command::SUCCESS;
    });
$application->add($keyGenerateCommand);

// Doctor reports current configuration and dependency state. Text and JSON
// share the same redacted HealthReport; neither prints raw exception messages.
$doctorCommand = new Command('doctor');
$doctorCommand->setDescription('Inspect runtime, configuration, and selected infrastructure safely.')
    ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine-readable safe report.')
    ->addOption('profile', null, InputOption::VALUE_REQUIRED,
        'Inspect a deployment profile: shared-hosting, single-server, worker, or multi-server.')
    ->addOption('probe', null, InputOption::VALUE_NONE,
        'Inspect selected local infrastructure without sending mail or dispatching jobs.')
    ->addOption('verify-url', null, InputOption::VALUE_REQUIRED,
        'Explicit HTTP base URL for bounded public-path checks (web profiles only).')
    ->addOption('asset-path', null, InputOption::VALUE_REQUIRED,
        'Public asset path to verify with --verify-url.', '/assets/default/favicon/site.webmanifest')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $profile = $input->getOption('profile');
        if ($profile !== null) {
            try {
                $evidence = (new \App\Health\DeploymentProof($squehubApp))->inspect(
                    (string) $profile,
                    (bool) $input->getOption('probe'),
                    is_string($input->getOption('verify-url')) ? $input->getOption('verify-url') : null,
                    (string) $input->getOption('asset-path'),
                );
                $output->writeln($input->getOption('json')
                    ? json_encode($evidence->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : $evidence->render());
                return $evidence->level() === 'not_ready' || $evidence->hasFailedObservation()
                    ? Command::FAILURE : Command::SUCCESS;
            } catch (\Throwable) {
                // HTTP/configuration errors may carry private paths or
                // credentials; the CLI exposes only a bounded failure.
                $output->writeln('<error>Deployment profile could not be inspected safely.</error>');
                return Command::FAILURE;
            }
        }
        if ($input->getOption('probe') || $input->getOption('verify-url') !== null) {
            $output->writeln('<error>Deployment proof options require --profile.</error>');
            return Command::FAILURE;
        }
        $report = $squehubApp->container()->make(\App\Health\HealthManager::class)->doctor();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('SqueHub Doctor');
            foreach ($report->results() as $result) {
                $output->writeln(sprintf('%-13s %-16s %-8s %s',
                    $result->category(), $result->name(), strtoupper($result->status()), $result->summary()));
            }
            $output->writeln('Overall: ' . ($report->hasFailures() ? 'FAIL'
                : ($report->hasWarnings() ? 'READY WITH WARNINGS' : 'PASS')));
        }
        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    });
$application->add($doctorCommand);

$infrastructureCommand = new Command('infrastructure');
$infrastructureCommand->setDescription('Show configured and selected local infrastructure drivers.')
    ->addOption('json', null, InputOption::VALUE_NONE, 'Print safe driver selection as JSON.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $rows = $squehubApp->container()->make(\App\Health\HealthManager::class)->infrastructure();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('Infrastructure');
            foreach ($rows as $name => $row) {
                $output->writeln(sprintf('%-13s %-12s -> %-12s %s', $name,
                    $row['configured'], $row['selected'] ?? 'unavailable', $row['reason'] ?? ''));
            }
        }
        return in_array('selection_failed', array_column($rows, 'reason'), true)
            ? Command::FAILURE : Command::SUCCESS;
    });
$application->add($infrastructureCommand);

$startCommand = new Command('start');
$startCommand->setDescription('Start the PHP built-in server.')
    ->setHelp("Node required: no.\nWrites files: no project source/configuration; requests may write runtime state.\n"
        . "Starts processes: yes, the foreground PHP built-in server.\n"
        . 'Development-only: intended for local development, not production process management.')
    ->addArgument('host', InputArgument::OPTIONAL, 'The host to bind to', 'localhost')
    ->addArgument('port', InputArgument::OPTIONAL, 'The port to bind to (defaults to the first available port from 8000 to 8099)')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            return DevelopmentServer::prepare(
                $squehubApp,
                (string) $input->getArgument('host'),
                $input->getArgument('port') === null ? null : (string) $input->getArgument('port')
            )->run($output);
        } catch (DevException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });

$application->add($startCommand);

// Dev coordinates the ordinary server and explicitly selected development
// services. The standalone `start` command remains a server-only entry point.
$devCommand = new Command('dev');
$devCommand->setDescription('Run a SqueHub development session.')
    ->setHelp("Node required: no for PHP alone; --frontend requires local Node and Vite.\n"
        . "Writes files: no project source/configuration; requests and optional workers may write runtime state.\n"
        . "Starts processes: yes, PHP plus the selected --queue worker and/or --frontend Vite server.\n"
        . 'Development-only: intended for local development; --frontend is restricted to local/development environments.')
    ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The PHP server host.', 'localhost')
    ->addOption('port', null, InputOption::VALUE_REQUIRED, 'The PHP server port (defaults to the first available port from 8000 to 8099).')
    ->addOption('queue', null, InputOption::VALUE_NONE, 'Run the configured persistent Queue worker.')
    ->addOption('frontend', null, InputOption::VALUE_NONE, 'Run the selected local Vite frontend with PHP.')
    ->addOption('frontend-port', null, InputOption::VALUE_REQUIRED,
        'Explicit loopback port for the selected frontend.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            return DevSession::run(
                $squehubApp,
                $output,
                (string) $input->getOption('host'),
                $input->getOption('port') === null ? null : (string) $input->getOption('port'),
                (bool) $input->getOption('queue'),
                null,
                null,
                (bool) $input->getOption('frontend'),
                $input->getOption('frontend-port') === null
                    ? null : (string) $input->getOption('frontend-port')
            );
        } catch (DevException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($devCommand);
FrontendCommands::register($application, $squehubApp);

// Studio is a separate local inspector server. Its request router repeats the
// environment, opt-in, and loopback checks on every request; APP_DEBUG alone
// never authorizes access.
$studioCommand = new Command('studio');
$studioCommand->setDescription('Start the read-only SqueHub Studio on loopback in development.')
    ->addOption('port', null, InputOption::VALUE_REQUIRED,
        'Loopback port (defaults to the first available port from 8100 to 8199).')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        if ($squehubApp->environment() !== 'development'
            || $squehubApp->config()->get('studio.enabled', false) !== true) {
            $output->writeln('<error>Studio requires APP_ENV=development and STUDIO_ENABLED=true.</error>');
            return Command::FAILURE;
        }
        try {
            $port = $input->getOption('port');
            return DevelopmentServer::prepareStudio($squehubApp,
                $port === null ? null : (string) $port)->run($output);
        } catch (DevException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($studioCommand);

// Agent inspection is opt-in at the CLI boundary. No MCP transport or client
// process starts during ordinary Application boot or command listing.
$agentCliInventory = static function () use ($application): array {
    $items = [];
    foreach ($application->all() as $name => $command) {
        if ($name !== $command->getName()
            || preg_match('/\A[a-z][a-z0-9:-]{0,79}\z/D', $name) !== 1) {
            continue; // Symfony includes aliases and private completion entries.
        }
        if (count($items) >= 256) {
            break;
        }
        $description = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $command->getDescription()) ?? '';
        $items[] = ['name' => $name, 'description' => substr($description, 0, 240)];
    }
    return $items;
};
$agentStatusCommand = new Command('agent:status');
$agentStatusCommand->setDescription('Show the local Agent capability and MCP availability status.')
    ->addOption('json', null, InputOption::VALUE_NONE, 'Print compact safe JSON.')
    ->setCode(static function (InputInterface $input, OutputInterface $output)
        use ($squehubApp, $agentCliInventory): int {
        try {
            $status = (new \App\Agent\AgentManager($squehubApp, $agentCliInventory()))->status();
            $status['mcp_sdk_available'] = \App\Agent\Mcp\McpServerAdapter::available();
            $output->writeln(json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | ($input->getOption('json') ? 0 : JSON_PRETTY_PRINT)));
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Agent status could not be inspected safely.</error>');
            return Command::FAILURE;
        }
    });
$application->add($agentStatusCommand);

$agentMcpCommand = new Command('agent:mcp');
$agentMcpCommand->setDescription('Serve bounded SqueHub Agent resources over local MCP STDIO.')
    ->setHelp("Transport: local STDIO only. Standard output is reserved for MCP protocol frames.\n"
        . "Requires the optional mcp/sdk package. The default mode is read-only.\n"
        . "Use agent:status to review the granted capabilities from Config/Agent.php.\n"
        . 'No network listener, package provider execution, or mutation capability is enabled by this command.')
    ->setCode(static function (InputInterface $input, OutputInterface $output)
        use ($squehubApp, $agentCliInventory, &$squehubMcpBootstrapBufferLevel): int {
        // Configuration and command registration may execute application PHP.
        // Discard any incidental output before the JSON-RPC transport begins.
        if ($squehubMcpBootstrapBufferLevel !== null) {
            while (ob_get_level() > $squehubMcpBootstrapBufferLevel) {
                ob_end_clean();
            }
            $squehubMcpBootstrapBufferLevel = null;
        }
        try {
            return (new \App\Agent\Mcp\McpServerAdapter())
                ->run(new \App\Agent\AgentManager($squehubApp, $agentCliInventory()));
        } catch (\Throwable) {
            fwrite(STDERR, "SqueHub MCP server could not start safely. Check the Agent configuration and optional MCP SDK.\n");
            return Command::FAILURE;
        }
    });
$application->add($agentMcpCommand);

$migrator = static fn (): \App\Database\Migrations\Migrator => new \App\Database\Migrations\Migrator(
    \App\Database\Database::manager(),
    BASE_DIR
);

$migrateCommand = new Command('migrate');
$migrateCommand->setDescription('Run new database migrations.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($migrator): int {
        try {
            $applied = $migrator()->run();
            if ($applied === []) {
                $output->writeln('<comment>Nothing to migrate.</comment>');
            }
            foreach ($applied as $file) {
                $output->writeln("<info>Migrated:</info> {$file}");
            }
            return Command::SUCCESS;
        } catch (\App\Database\Migrations\MigrationException | \App\Database\Exception\DatabaseException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($migrateCommand);

$rollbackCommand = new Command('migrate:rollback');
$rollbackCommand->setDescription('Rollback the latest migration batch.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($migrator): int {
        try {
            $rolledBack = $migrator()->rollback();
            if ($rolledBack === []) {
                $output->writeln('<comment>No migrations to rollback.</comment>');
            }
            foreach ($rolledBack as $file) {
                $output->writeln("<info>Rolled back:</info> {$file}");
            }
            return Command::SUCCESS;
        } catch (\App\Database\Migrations\MigrationException | \App\Database\Exception\DatabaseException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($rollbackCommand);

$resetCommand = new Command('migrate:reset');
$resetCommand->setDescription('Roll back every migration in reverse order.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($migrator): int {
        try {
            $rolledBack = $migrator()->reset();
            if ($rolledBack === []) {
                $output->writeln('<comment>No migrations to reset.</comment>');
            }
            foreach ($rolledBack as $file) {
                $output->writeln("<info>Rolled back:</info> {$file}");
            }
            return Command::SUCCESS;
        } catch (\App\Database\Migrations\MigrationException | \App\Database\Exception\DatabaseException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($resetCommand);

$statusCommand = new Command('migrate:status');
$statusCommand->setDescription('Show applied and pending migrations.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($migrator): int {
        try {
            $status = $migrator()->status();
            if ($status === []) {
                $output->writeln('<comment>No migration files found.</comment>');
                return Command::SUCCESS;
            }
            $table = new Table($output);
            $table->setHeaders(['Migration', 'Status', 'Batch']);
            foreach ($status as $row) {
                $table->addRow([$row['migration'], $row['state'], $row['batch'] ?? '-']);
            }
            $table->render();
            return Command::SUCCESS;
        } catch (\App\Database\Migrations\MigrationException | \App\Database\Exception\DatabaseException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($statusCommand);

$seedCommand = new Command('seed');
$seedCommand->setDescription('Run the root or a named application Seeder.')
    ->addArgument('class', InputArgument::OPTIONAL, 'Seeder class in Database/Seeders', 'DatabaseSeeder')
    ->addOption('force', null, InputOption::VALUE_NONE, 'Allow seeding in production-like environments')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $name = (string) $input->getArgument('class');
        $environment = $squehubApp->environment();
        try {
            $runner = $squehubApp->container()->make(\App\Database\Seeding\SeederRunner::class);
            $output->writeln("Seeding database ({$environment})...");
            $runner->run($name, (bool) $input->getOption('force'),
                static function (string $class) use ($output): void {
                    $output->writeln('<info>' . $class . ' ........ DONE</info>');
                });
            $output->writeln('<info>Database seeded.</info>');
            return Command::SUCCESS;
        } catch (\App\Database\Seeding\SeederException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($seedCommand);

// Availability is derived from canonical Seeder files. Execution history is
// not persisted, so this command deliberately makes no run-state claim.
$seedStatusCommand = new Command('seed:status');
$seedStatusCommand->setDescription('List available application Seeders; execution history is not persisted.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $names = $squehubApp->container()->make(\App\Database\Seeding\SeederRunner::class)->available();
            $output->writeln('Available Seeders (execution history is not persisted):');
            if ($names === []) {
                $output->writeln('<comment>No Seeder files found.</comment>');
            }
            foreach ($names as $name) {
                $output->writeln('  ' . $name);
            }
            return Command::SUCCESS;
        } catch (\App\Database\Seeding\SeederException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($seedStatusCommand);

// Rollback executes only an application's explicit reversible Seeder hook.
// It does not infer run order or alter migration history.
$seedRollbackCommand = new Command('seed:rollback');
$seedRollbackCommand->setDescription('Run one explicit reversible Seeder rollback.')
    ->addArgument('class', InputArgument::REQUIRED, 'Seeder class in Database/Seeders')
    ->addOption('force', null, InputOption::VALUE_NONE, 'Allow rollback in production-like environments')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $squehubApp->container()->make(\App\Database\Seeding\SeederRunner::class)
                ->rollback((string) $input->getArgument('class'), (bool) $input->getOption('force'));
            $output->writeln('<info>Seeder rollback completed.</info>');
            return Command::SUCCESS;
        } catch (\App\Database\Seeding\SeederException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    });
$application->add($seedRollbackCommand);

$backupCommand = new Command('backup:dev');
$backupCommand->setDescription('Backup Project/, Config/, Database/, .env, and config.php into a zip file.')
    ->setCode(function (InputInterface $input, OutputInterface $output): int {
        // ZIP is optional for normal framework operation. Fail before creating
        // a backup directory when this explicit development command cannot run.
        if (!class_exists(ZipArchive::class)) {
            $output->writeln('<error>backup:dev requires the PHP zip extension. Enable zip in your PHP CLI php.ini and retry.</error>');
            return Command::FAILURE;
        }
        $backupDir = BASE_DIR . '/Storage/Backups/Dev';
        $timestamp = date('Ymd_His');
        $zipFile = "$backupDir/squehub_dev_backup_$timestamp.zip";

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            $output->writeln("<error>✖ Failed to create zip archive.</error>");
            return Command::FAILURE;
        }

        $addFolderToZip = function ($folderPath, $zipPath = '') use (&$zip) {
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

        // Application views are included with Project/.
        $devPath = is_dir(BASE_DIR . '/Project') ? BASE_DIR . '/Project' : BASE_DIR . '/project';
        $configPath = is_dir(BASE_DIR . '/Config') ? BASE_DIR . '/Config' : BASE_DIR . '/config';
        $databasePath = is_dir(BASE_DIR . '/Database') ? BASE_DIR . '/Database' : BASE_DIR . '/database';

        if (is_dir($devPath))   $addFolderToZip($devPath, 'Project');
        if (is_dir($configPath)) $addFolderToZip($configPath, 'Config');
        if (is_dir($databasePath)) $addFolderToZip($databasePath, 'Database');


        if (file_exists(BASE_DIR . '/.env')) {
            $zip->addFile(BASE_DIR . '/.env', '.env');
        }
        if (file_exists(BASE_DIR . '/config.php')) {
            $zip->addFile(BASE_DIR . '/config.php', 'config.php');
        }

        $zip->close();
        $output->writeln("<info>✔ Backup created:</info> $zipFile");
        return Command::SUCCESS;
    });

$application->add($backupCommand);



// Generation is a filesystem-only CLI operation. Package installation and
// Seeder execution remain separate commands with separate lifecycles.
$generator = new Generator($squehubApp->basePath());
foreach (['controller', 'middleware', 'migration', 'model', 'seeder'] as $kind) {
    $application->add(new MakeCommand($generator, $kind));
}
$application->add(new FeatureBlueprintCommand(new FeatureBlueprintGenerator($squehubApp->basePath())));

// Package inspection and mutations use the Application-owned lifecycle service.
// Command parsing and output stay independent from package source contents.
PackageCommands::register($application, $squehubApp->container()->make(\App\Packages\PackageManager::class), $squehubApp);

// Kit management uses static metadata until an explicit, reviewed lifecycle
// operation is applied. Kit entry classes never run during command discovery.
KitCommands::register($application, $squehubApp->container()->make(\App\Kits\KitManager::class));

// Portable source bundles remain independent of backup:dev and never load
// project PHP during inspection or reviewed import planning.
BundleCommands::register($application, $squehubApp->basePath());

// Upgrade preflight compares local source trees without loading target PHP.
UpgradeCommands::register($application, $squehubApp->basePath());

// Migration planning inventories source only; migrate remains the explicit
// command that connects to the database and executes migration classes.
MigrationPlanCommand::register($application, $squehubApp->basePath());

// Recovery planning classifies separate artifacts without restoring any of them.
RecoveryCommands::register($application, $squehubApp);

// Inspect registered routes without starting the legacy web or database bootstrap.
$routeListCommand = new Command('route:list');
$routeListCommand->setDescription('List application and package routes.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        require __DIR__ . '/../../Bootstrap/Routes.php';

        $registry = $squehubApp->container()->make(\App\Routing\RouteRegistry::class);
        $table = new Table($output);
        $table->setHeaders(['METHOD', 'URI', 'NAME', 'HANDLER', 'MIDDLEWARE', 'HOST', 'FALLBACK']);
        foreach ($registry->inspection() as $route) {
            $table->addRow([
                implode('|', $route['methods']),
                $route['path'],
                $route['name'] ?? '',
                $route['handler'],
                implode(', ', $route['middleware']),
                $route['host'] ?? '',
                $route['fallback'] ? 'yes' : '',
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    });
$application->add($routeListCommand);

// Contract export uses the same route loader as route:list. Buffer route-file
// output so application echoes cannot corrupt the machine-readable stdout.
$contractExportCommand = new Command('contract:export');
$contractExportCommand->setDescription('Export the declared application contract as JSON.')
    ->addOption('format', null, InputOption::VALUE_REQUIRED,
        'Export format: openapi or squehub.', 'openapi')
    ->addOption('api-version', null, InputOption::VALUE_REQUIRED,
        'Include only operations declared for this API version.')
    ->addOption('pretty', null, InputOption::VALUE_NONE,
        'Indent the generated JSON for reading.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['openapi', 'squehub'], true)) {
            fwrite(STDERR, "Contract export format must be openapi or squehub.\n");
            return Command::FAILURE;
        }

        $version = $input->getOption('api-version');
        if ($version !== null && !is_string($version)) {
            fwrite(STDERR, "Contract export API version is invalid.\n");
            return Command::FAILURE;
        }

        try {
            ob_start();
            try {
                require __DIR__ . '/../../Bootstrap/Routes.php';
            } finally {
                ob_end_clean();
            }

            $json = $squehubApp->container()->make(\App\Api\Contract\ContractManager::class)
                ->json($format, $version, (bool) $input->getOption('pretty'));
            $output->write($json);
            return Command::SUCCESS;
        } catch (\Throwable) {
            // Route declarations and contract examples may contain private
            // values. Keep exception details off both output streams.
            fwrite(STDERR, "Contract export failed. Check route and contract declarations.\n");
            return Command::FAILURE;
        }
    });
$application->add($contractExportCommand);

// Verification is an explicit CLI action. Route and case-file output is
// discarded so machine-readable reports remain the only stdout content.
$contractVerifyCommand = new Command('contract:verify');
$contractVerifyCommand->setDescription('Verify declared API operations and explicit request cases.')
    ->addOption('static', null, InputOption::VALUE_NONE,
        'Check declarations without executing application handlers.')
    ->addOption('mutations', null, InputOption::VALUE_NONE,
        'Include mutating-method or explicitly marked cases in development or testing.')
    ->addOption('operation', null, InputOption::VALUE_REQUIRED,
        'Verify one declared operation ID.')
    ->addOption('format', null, InputOption::VALUE_REQUIRED,
        'Report format: text or json.', 'text')
    ->addOption('strict', null, InputOption::VALUE_NONE,
        'Fail on warnings and public operations without a case.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['text', 'json'], true)) {
            fwrite(STDERR, "Contract verification format must be text or json.\n");
            return Command::FAILURE;
        }
        $operation = $input->getOption('operation');
        if ($operation !== null && (!is_string($operation) || trim($operation) === '')) {
            fwrite(STDERR, "Contract verification operation ID is invalid.\n");
            return Command::FAILURE;
        }
        if (is_string($operation)) {
            try {
                \App\Api\Contract\OperationContract::identifier($operation, 'operation ID');
            } catch (\Throwable) {
                fwrite(STDERR, "Contract verification operation ID is invalid.\n");
                return Command::FAILURE;
            }
        }

        $environment = strtolower(trim($squehubApp->environment()));
        $executableEnvironment = in_array($environment,
            ['development', 'testing', 'test', 'local'], true);
        $mutations = (bool) $input->getOption('mutations');
        $staticOnly = (bool) $input->getOption('static') || !$executableEnvironment;
        if ($mutations && $staticOnly) {
            fwrite(STDERR, "Mutation verification requires a development or testing environment without --static.\n");
            return Command::FAILURE;
        }

        try {
            ob_start();
            try {
                require __DIR__ . '/../../Bootstrap/Routes.php';
                // Cases are deliberately registered from one reviewed file.
                // Static and production-like verification never execute it.
                if (!$staticOnly) {
                    $cases = $squehubApp->projectPath() . '/Api/Verification.php';
                    if (is_file($cases)) {
                        require $cases;
                    }
                }
                // Fixture setup and cleanup can also echo. Keep all execution
                // output away from the report stream, even on an exception.
                $report = $squehubApp->container()->make(\App\Api\Contract\ContractVerifier::class)
                    ->verify($staticOnly, $operation, (bool) $input->getOption('strict'), $mutations);
            } finally {
                ob_end_clean();
            }
            $output->write(rtrim($format === 'json' ? $report->toJson() : $report->toText(), "\r\n") . "\n");
            return $report->exitCode();
        } catch (\Throwable) {
            // Case setup, handlers, and route files can carry private values.
            // The report has safe findings; failures before it are redacted.
            fwrite(STDERR, "Contract verification could not complete. Check declarations and case setup.\n");
            return Command::FAILURE;
        }
    });
$application->add($contractVerifyCommand);

// SDK generation consumes the native contract snapshot. Route output is
// discarded, and stale checks leave the selected output directory untouched.
$sdkGenerateCommand = new Command('sdk:generate');
$sdkGenerateCommand->setDescription('Generate a client SDK from the declared application contract.')
    ->addOption('language', null, InputOption::VALUE_REQUIRED,
        'Client language: typescript, javascript, or php.')
    ->addOption('output', null, InputOption::VALUE_REQUIRED,
        'Relative output directory beneath the application root.')
    ->addOption('api-version', null, InputOption::VALUE_REQUIRED,
        'Include only operations declared for this API version.')
    ->addOption('check', null, InputOption::VALUE_NONE,
        'Fail when generated source differs, without changing files.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $language = $input->getOption('language');
        $destination = $input->getOption('output');
        $version = $input->getOption('api-version');
        if (!is_string($language) || !in_array($language,
            ['typescript', 'javascript', 'php'], true)
            || !is_string($destination) || trim($destination) === ''
            || ($version !== null && !is_string($version))) {
            fwrite(STDERR, "SDK generation requires --language and --output with valid values.\n");
            return Command::FAILURE;
        }

        try {
            ob_start();
            try {
                require __DIR__ . '/../../Bootstrap/Routes.php';
            } finally {
                ob_end_clean();
            }
            $report = $squehubApp->container()->make(\App\Api\Sdk\SdkGenerator::class)
                ->generate($language, $destination, $squehubApp->basePath(),
                    $version, (bool) $input->getOption('check'));
            if (!$report['current']) {
                fwrite(STDERR, "Generated SDK is stale or missing. Run sdk:generate without --check.\n");
                return Command::FAILURE;
            }
            $action = $input->getOption('check') ? 'current' : 'generated';
            $output->writeln("SDK {$action}: {$report['operations']} operations, "
                . "{$report['schemas']} schemas, {$report['files']} files.");
            return Command::SUCCESS;
        } catch (\App\Api\Sdk\SdkException $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");
            return Command::FAILURE;
        } catch (\Throwable) {
            // Application route files may contain private configuration.
            fwrite(STDERR, "SDK generation failed. Check contract declarations and output configuration.\n");
            return Command::FAILURE;
        }
    });
$application->add($sdkGenerateCommand);

// Clear only the configured application namespace, never compiled views or sessions.
$cacheClearCommand = new Command('cache:clear');
$cacheClearCommand->setDescription('Clear the configured application data cache.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $squehubApp->container()->make(\App\Cache\CacheStore::class)->clear();
            $output->writeln('<info>Application cache cleared.</info>');
            return Command::SUCCESS;
        } catch (\App\Cache\CacheException $exception) {
            $output->writeln('<error>Application cache could not be cleared.</error>');
            return Command::FAILURE;
        }
    });
$application->add($cacheClearCommand);

// Base configuration can contain credentials. Report artifact state only;
// never echo cached values or the environment-derived source fingerprint.
$configCacheCommand = new Command('config:cache');
$configCacheCommand->setDescription('Build the private, source-validated base configuration cache.')
    ->addOption('preview', null, InputOption::VALUE_NONE,
        'Review the cache publication plan without writing private Storage')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $cache = $squehubApp->container()->make(\App\Config\ConfigCache::class);
            $environment = $squehubApp->container()->make(\App\Foundation\Environment::class);
            $plan = $cache->planBuild($squehubApp->configPath(), $environment);
            if ($input->getOption('preview')) {
                $output->writeln(\App\Changes\ChangeRenderer::render($plan,
                    'SqueHub Configuration Cache Plan'));
                return Command::SUCCESS;
            }
            // config:cache is already the caller's explicit authorization for
            // this rebuildable private artifact; apply still rechecks the plan.
            $report = $cache->applyBuild($plan, $squehubApp->configPath(), $environment);
            if (!$report['change']->complete()) {
                $output->writeln('<error>Configuration cache publication was incomplete.</error>');
                return Command::FAILURE;
            }
            $output->writeln('Configuration cache built: ' . $report['files'] . ' source files.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Configuration cache could not be built. Check Config sources and private Storage permissions.</error>');
            return Command::FAILURE;
        }
    });
$application->add($configCacheCommand);

$configClearCommand = new Command('config:clear');
$configClearCommand->setDescription('Remove only the private base configuration cache artifact.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $removed = $squehubApp->container()->make(\App\Config\ConfigCache::class)->clear();
            $output->writeln($removed ? 'Configuration cache cleared.' : 'No configuration cache to clear.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Configuration cache could not be cleared. Check private Storage permissions.</error>');
            return Command::FAILURE;
        }
    });
$application->add($configClearCommand);

// Cached routes are derived declarations. A source rejected by the cache
// preflight still works through ordinary uncached route registration.
$routeCacheCommand = new Command('route:cache');
$routeCacheCommand->setDescription('Build a private cache of cacheable route declarations.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $report = (new \App\Routing\RouteCache($squehubApp))->build();
            $output->writeln('Route cache ' . ($report['reused'] ? 'reused' : 'built') . ': '
                . $report['routes'] . ' routes from ' . $report['files'] . ' source files.');
            return Command::SUCCESS;
        } catch (\App\Routing\RouteCacheException $exception) {
            $detail = preg_replace('/[\x00-\x1F\x7F]/', '?', $exception->getMessage())
                ?? 'Route source is unavailable.';
            $output->writeln('Route cache unavailable: ' . substr($detail, 0, 512),
                OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        } catch (\Throwable) {
            $output->writeln('<error>Route cache could not be built. Check route sources and private Storage permissions.</error>');
            return Command::FAILURE;
        }
    });
$application->add($routeCacheCommand);

$routeClearCommand = new Command('route:clear');
$routeClearCommand->setDescription('Remove only the private route cache artifact.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $removed = (new \App\Routing\RouteCache($squehubApp))->clear();
            $output->writeln($removed ? 'Route cache cleared.' : 'No route cache to clear.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Route cache could not be cleared. Check private Storage permissions.</error>');
            return Command::FAILURE;
        }
    });
$application->add($routeClearCommand);

// Compiled Views are disposable source-derived artifacts, separate from the
// application data cache. Warming compiles templates without rendering them.
$viewCacheCommand = new Command('view:cache');
$viewCacheCommand->setDescription('Precompile current Views without rendering them.')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        try {
            $report = \App\Core\View::warm();
            $output->writeln('Compiled Views');
            $output->writeln('Compiled: ' . $report['compiled']);
            $output->writeln('Reused:   ' . $report['reused']);
            $output->writeln('Failed:   ' . $report['failed']);
            foreach ($report['errors'] as $error) {
                // A logical View name/reason is useful; raw output prevents a
                // source-controlled name from becoming Console markup.
                $output->writeln($error, OutputInterface::OUTPUT_RAW);
            }
            return $report['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable) {
            $output->writeln('<error>Compiled Views could not be prepared. Check View source and Storage permissions.</error>');
            return Command::FAILURE;
        }
    });
$application->add($viewCacheCommand);

$viewClearCommand = new Command('view:clear');
$viewClearCommand->setDescription('Remove SqueHub-owned compiled View artifacts.')
    ->setCode(static function (InputInterface $input, OutputInterface $output): int {
        try {
            $removed = \App\Core\View::clearCompiled();
            $output->writeln('<info>Compiled Views cleared.</info>');
            $output->writeln('Removed: ' . $removed);
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Compiled Views could not be cleared. Check Storage permissions and OPcache settings.</error>');
            return Command::FAILURE;
        }
    });
$application->add($viewClearCommand);

// The command controls polling only; reservation and retry rules live in Worker.
$queueWorkCommand = new Command('queue:work');
$queueWorkCommand->setDescription('Process persistent Queue jobs.')
    ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Queue connection name')
    ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Logical queue name', 'default')
    ->addOption('tries', null, InputOption::VALUE_REQUIRED, 'Maximum reservation attempts', '3')
    ->addOption('backoff', null, InputOption::VALUE_REQUIRED, 'Retry delay in seconds', '5')
    ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Idle polling interval in seconds', '1')
    ->addOption('once', null, InputOption::VALUE_NONE, 'Poll once, then exit')
    ->addOption('stop-when-empty', null, InputOption::VALUE_NONE, 'Stop after the queue has no eligible job')
    ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Stop after processing this many jobs')
    ->addOption('max-time', null, InputOption::VALUE_REQUIRED, 'Stop after this many runtime seconds')
    ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Stop between jobs above this memory limit in MB')
    ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Limit one job attempt in seconds')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        $tries = (string) $input->getOption('tries');
        $backoff = (string) $input->getOption('backoff');
        $sleep = (string) $input->getOption('sleep');
        $maxJobs = $input->getOption('max-jobs');
        $maxTime = $input->getOption('max-time');
        $memory = $input->getOption('memory');
        $timeout = $input->getOption('timeout');
        if (!ctype_digit($tries) || (int) $tries < 1 || !ctype_digit($backoff)
            || !ctype_digit($sleep) || (int) $sleep < 1
            || ($maxJobs !== null && (!ctype_digit((string) $maxJobs) || (int) $maxJobs < 1))
            || ($maxTime !== null && (!ctype_digit((string) $maxTime) || (int) $maxTime < 1))
            || ($memory !== null && (!ctype_digit((string) $memory) || (int) $memory < 1))
            || ($timeout !== null && (!ctype_digit((string) $timeout) || (int) $timeout < 1))) {
            $output->writeln('<error>Invalid Queue worker options.</error>');
            return Command::FAILURE;
        }
        try {
            $worker = $squehubApp->container()->make(\App\Queue\Worker::class);
            // Signal handlers only request a stop. Worker finishes the current
            // reservation before leaving its polling loop.
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
                pcntl_async_signals(true);
                if (defined('SIGTERM')) pcntl_signal(SIGTERM, static fn () => $worker->stop());
                if (defined('SIGINT')) pcntl_signal(SIGINT, static fn () => $worker->stop());
            }
            $count = $worker->run((string) $input->getOption('queue'),
                $input->getOption('connection'), (int) $tries, (int) $backoff,
                (int) $sleep, (bool) $input->getOption('once'),
                $maxJobs === null ? null : (int) $maxJobs,
                $maxTime === null ? null : (int) $maxTime,
                $memory === null ? null : (int) $memory,
                $timeout === null ? null : (int) $timeout,
                (bool) $input->getOption('stop-when-empty'));
            $output->writeln("Processed {$count} Queue job(s).");
            $output->writeln('Worker exit: ' . $worker->exitReason() . '.');
            return Command::SUCCESS;
        } catch (\Throwable) {
            // Job payloads and exception messages may contain credentials.
            $output->writeln('<error>Queue worker failed. Check connection, migration, and worker settings.</error>');
            return Command::FAILURE;
        }
    });
$application->add($queueWorkCommand);

// Database workers use a project-local restart marker; Redis workers share
// one marker through their Queue namespace. Neither marker contains job data.
// Supervisors start replacements after workers finish their current attempt.
$queueRestartCommand = new Command('queue:restart');
$queueRestartCommand->setDescription('Request a graceful restart of this application’s Queue workers.')
    ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Queue connection name')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $manager = $squehubApp->container()->make(\App\Queue\QueueManager::class);
            $signal = $manager->restartSignal($input->getOption('connection'))
                ?? $squehubApp->container()->make(\App\Queue\RestartSignal::class);
            $signal->mark();
            $output->writeln('<info>Queue worker restart requested.</info>');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Queue restart request failed.</error>');
            return Command::FAILURE;
        }
    });
$application->add($queueRestartCommand);

// Status is a bounded snapshot of backend records. It never inspects payloads
// and cannot establish whether an external worker process is still alive.
$queueStatusCommand = new Command('queue:status');
$queueStatusCommand->setDescription('Show safe Queue record counts for one logical queue.')
    ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Queue connection name')
    ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Logical queue name', 'default')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($squehubApp): int {
        try {
            $manager = $squehubApp->container()->make(\App\Queue\QueueManager::class);
            $connection = $input->getOption('connection');
            $queue = \App\Queue\QueueManager::name((string) $input->getOption('queue'));
            $driver = $manager->driver($connection);
            if (!$driver instanceof \App\Queue\QueueStatusDriver) {
                $output->writeln('Persistent Queue counts are unavailable for this connection.');
                return Command::SUCCESS;
            }
            $status = $driver->status($queue);
            $output->writeln('Ready: ' . $status->ready);
            $output->writeln('Delayed: ' . $status->delayed);
            $output->writeln('Reserved leases: ' . $status->reserved);
            $output->writeln('Failed (all queues): ' . $status->failed);
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Queue status is unavailable. Check the selected connection and migration.</error>');
            return Command::FAILURE;
        }
    });
$application->add($queueStatusCommand);

/** Select the chosen persistent driver's safe failed-job operations. */
$failedQueueDriver = static function (?string $connection) use ($squehubApp): \App\Queue\FailedQueueDriver {
    $driver = $squehubApp->container()->make(\App\Queue\QueueManager::class)->driver($connection);
    if (!$driver instanceof \App\Queue\FailedQueueDriver) {
        throw new \App\Queue\QueueException('Failed-job commands require a persistent Queue connection.');
    }
    return $driver;
};

$queueFailedCommand = new Command('queue:failed');
$queueFailedCommand->setDescription('List safe failed Queue job metadata.')
    ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Queue connection name')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($failedQueueDriver): int {
        try {
            $rows = $failedQueueDriver($input->getOption('connection'))->failed();
            $table = new Table($output);
            $table->setHeaders(['ID', 'Queue', 'Job', 'Attempts', 'Failed at', 'Type']);
            foreach ($rows as $row) {
                $table->addRow([$row['id'], $row['queue'], $row['job_class'],
                    $row['attempts'], $row['failed_at'], $row['error_type']]);
            }
            $table->render();
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Failed Queue jobs could not be listed.</error>');
            return Command::FAILURE;
        }
    });
$application->add($queueFailedCommand);

foreach (['queue:retry' => 'retry', 'queue:forget' => 'forget'] as $commandName => $operation) {
    $command = new Command($commandName);
    $command->setDescription($operation === 'retry' ? 'Requeue one failed job.' : 'Remove one failed job.')
        ->addArgument('id', InputArgument::REQUIRED, 'Positive failed-job ID')
        ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Queue connection name')
        ->setCode(static function (InputInterface $input, OutputInterface $output) use ($failedQueueDriver, $operation): int {
            $id = (string) $input->getArgument('id');
            if (!ctype_digit($id) || (int) $id < 1) {
                $output->writeln('<error>Failed-job ID must be a positive integer.</error>');
                return Command::FAILURE;
            }
            try {
                $done = $failedQueueDriver($input->getOption('connection'))->{$operation}((int) $id);
                $output->writeln($done ? 'Failed Queue job updated.' : 'Failed Queue job not found.');
                return $done ? Command::SUCCESS : Command::FAILURE;
            } catch (\Throwable) {
                $output->writeln('<error>Failed Queue job operation failed.</error>');
                return Command::FAILURE;
            }
        });
    $application->add($command);
}

$queuePruneCommand = new Command('queue:prune');
$queuePruneCommand->setDescription('Remove failed Queue jobs older than the retention threshold.')
    ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Queue connection name')
    ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Minimum failure age in hours', '168')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($failedQueueDriver): int {
        $hours = (string) $input->getOption('hours');
        if (!ctype_digit($hours) || (int) $hours < 1 || (int) $hours > 87600) {
            $output->writeln('<error>Retention hours are invalid.</error>');
            return Command::FAILURE;
        }
        try {
            $count = $failedQueueDriver($input->getOption('connection'))->prune((int) $hours);
            $output->writeln("Pruned {$count} failed Queue job(s).");
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Failed Queue jobs could not be pruned.</error>');
            return Command::FAILURE;
        }
    });
$application->add($queuePruneCommand);

// Schedule definitions are loaded only for Scheduler commands. Including the
// files registers tasks; it must never execute them during ordinary app boot.
$loadSchedules = static function () use ($squehubApp): \App\Scheduler\Scheduler {
    (new \App\Scheduler\ScheduleLoader())->load($squehubApp);
    return $squehubApp->container()->make(\App\Scheduler\Scheduler::class);
};

$scheduleRunCommand = new Command('schedule:run');
$scheduleRunCommand->setDescription('Run due application schedules once.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($loadSchedules): int {
        try {
            $result = $loadSchedules()->run();
            $output->writeln(sprintf('Schedule: %d due, %d executed, %d dispatched, %d skipped, %d failed.',
                $result->due, $result->executed, $result->queued, $result->skipped, $result->failed));
            return $result->successful() ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable) {
            // Application callback exceptions can contain secrets. CLI output
            // reports aggregate failure only and never prints the throwable.
            $output->writeln('<error>Scheduler could not run. Check definitions and lock storage.</error>');
            return Command::FAILURE;
        }
    });
$application->add($scheduleRunCommand);

$scheduleListCommand = new Command('schedule:list');
$scheduleListCommand->setDescription('List registered application schedules.')
    ->setCode(static function (InputInterface $input, OutputInterface $output) use ($loadSchedules): int {
        try {
            $tasks = $loadSchedules()->inspectDefinitions();
            if ($tasks === []) {
                $output->writeln('<comment>No schedules registered.</comment>');
                return Command::SUCCESS;
            }
            $table = new Table($output);
            $table->setHeaders(['Name', 'Frequency', 'Timezone', 'Mode', 'Queue', 'Overlap']);
            foreach ($tasks as $task) {
                $table->addRow([$task['name'], $task['schedule'], $task['timezone'],
                    $task['mode'], $task['queue'],
                    $task['overlap'] === null ? '-' : $task['overlap'] . 's']);
            }
            $table->render();
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Scheduler definitions could not be listed.</error>');
            return Command::FAILURE;
        }
    });
$application->add($scheduleListCommand);



// SqueHub's HelpCommand extends Symfony's to preserve its `--help` injection.
$application->run();
