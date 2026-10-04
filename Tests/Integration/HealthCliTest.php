<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\EnvironmentSetup;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real CLI subprocesses verify safe JSON, plain text, and exit semantics. */
final class HealthCliTest extends TestCase
{
    /** @param list<string> $arguments */
    private function command(array $arguments, array $environment = []): Process
    {
        $safe = [
            'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_DATABASE' => ':memory:',
            'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array',
            'RATE_LIMIT_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'REDIS_URL' => '', 'REDIS_HOST' => '',
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        ];
        $process = new Process([PHP_BINARY, 'squehub', ...$arguments, '--no-ansi'],
            dirname(__DIR__, 2), array_merge($safe, $environment));
        $process->run();
        return $process;
    }

    public function testDoctorTextAndJsonAreSafeAndReflectSetupState(): void
    {
        $secret = 'SQUEHUB_HEALTH_SECRET_DO_NOT_LEAK';
        $overrides = ['DB_PASSWORD' => $secret, 'MAIL_PASSWORD' => $secret, 'APP_KEY' => $secret,
            'REDIS_URL' => 'redis://user:' . $secret . '@127.0.0.1:1'];
        $text = $this->command(['doctor'], $overrides);
        $setupRequired = EnvironmentSetup::status(dirname(__DIR__, 2)) !== null;
        self::assertSame($setupRequired ? 1 : 0, $text->getExitCode(), $text->getErrorOutput() . $text->getOutput());
        self::assertStringContainsString('SqueHub Doctor', $text->getOutput());
        self::assertStringNotContainsString($secret, $text->getOutput() . $text->getErrorOutput());
        $json = $this->command(['doctor', '--json'], $overrides);
        self::assertSame($setupRequired ? 1 : 0, $json->getExitCode(), $json->getErrorOutput() . $json->getOutput());
        $report = json_decode($json->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('doctor', $report['type']);
        self::assertSame($setupRequired ? 1 : 0, $report['counts']['fail']);
        self::assertStringNotContainsString($secret, $json->getOutput() . $json->getErrorOutput());
    }

    public function testDoctorFailsForUnsafeProductionDebug(): void
    {
        $process = $this->command(['doctor', '--json'], ['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);
        self::assertSame(1, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $report = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertContains('debug_enabled_in_production', array_column($report['results'], 'code'));
    }

    public function testDoctorDeploymentProfileReportsBoundedEvidenceWithoutChangingDefaultDoctor(): void
    {
        $default = $this->command(['doctor', '--json']);
        $legacy = json_decode($default->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('doctor', $legacy['type']);

        $profile = $this->command(['doctor', '--profile=shared-hosting', '--json'],
            ['APP_KEY' => 'SQUEHUB_DEPLOYMENT_SECRET_DO_NOT_LEAK']);
        $data = json_decode($profile->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('shared-hosting', $data['profile']);
        self::assertSame('not_probed', $data['checks']['database']['reason']);
        self::assertNull($data['checks']['database']['reachable']);
        self::assertArrayNotHasKey('queue', $data['checks']);
        self::assertStringNotContainsString('SQUEHUB_DEPLOYMENT_SECRET_DO_NOT_LEAK',
            $profile->getOutput() . $profile->getErrorOutput());
        self::assertNotSame('', $data['checked_at']);

        $invalid = $this->command(['doctor', '--profile=shared-hosting',
            '--verify-url=https://user:secret@example.test/']);
        self::assertSame(1, $invalid->getExitCode());
        self::assertStringNotContainsString('user:secret',
            $invalid->getOutput() . $invalid->getErrorOutput());
    }

    public function testDoctorInspectsBrokenAndEnabledPackagesWithoutRunningTheirPhpOrLeakingSecrets(): void
    {
        $project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $marker = $project->path('package-executed.txt');
        $sourceSecret = 'PACKAGE_SOURCE_SECRET_DO_NOT_LEAK';
        $keySecret = 'PACKAGE_KEY_SECRET_DO_NOT_LEAK';
        try {
            $project->write('vendor/autoload.php', '<?php require '
                . var_export($root . '/vendor/autoload.php', true) . ';');
            $project->write('App/Core/Helper.php', '<?php require '
                . var_export($root . '/App/Core/Helper.php', true) . ';');
            $bootstrap = file_get_contents($root . '/Bootstrap/App.php');
            self::assertIsString($bootstrap);
            $project->write('Bootstrap/App.php', $bootstrap);
            $project->write('CliRunner.php', '<?php $squehubApp = require __DIR__ . "/Bootstrap/App.php"; require '
                . var_export($root . '/App/Clis/Clis.php', true) . ';');
            $project->write('composer.json', '{"require":{"php":"^8.2"}}');
            $project->write('.env', "APP_ENV=testing\nAPP_KEY={$keySecret}\n");
            foreach (glob($root . '/Config/*.php') ?: [] as $configFile) {
                $contents = file_get_contents($configFile);
                self::assertIsString($contents);
                $project->write('Config/' . basename($configFile), $contents);
            }

            foreach (['Enabled', 'Disabled', 'Broken'] as $name) {
                $entry = '<?php namespace Packages\\' . $name . '; file_put_contents('
                    . var_export($marker, true) . ', "executed", FILE_APPEND); final class ' . $name
                    . ' extends \\App\\Plugins\\ServiceProvider {}';
                $project->write('Project/Packages/' . $name . '/' . $name . '.php', $entry);
            }
            $project->write('Project/Packages/Broken/composer.json',
                '{"extra":{"squehub":{"requires":["Missing"]}}}');
            $record = static fn (bool $enabled): array => [
                'enabled' => $enabled, 'source_kind' => 'git',
                'source' => 'https://user:' . $sourceSecret . '@example.test/private.git', 'files' => [],
            ];
            $project->write('Project/Packages/State.json', json_encode([
                'version' => 1, 'packages' => [
                    'Enabled' => $record(true), 'Disabled' => $record(false), 'Broken' => $record(true),
                ],
            ], JSON_THROW_ON_ERROR));

            foreach ([['doctor', '--json'], ['doctor']] as $arguments) {
                $process = new Process([PHP_BINARY, $project->path('CliRunner.php'), ...$arguments,
                    '--no-ansi'], $project->path(), [
                        'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_DATABASE' => ':memory:',
                        'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array',
                        'RATE_LIMIT_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                        'REDIS_URL' => '', 'REDIS_HOST' => '',
                        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                    ]);
                $process->run();
                self::assertSame(1, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
                self::assertStringNotContainsString($sourceSecret,
                    $process->getOutput() . $process->getErrorOutput());
                self::assertStringNotContainsString($keySecret,
                    $process->getOutput() . $process->getErrorOutput());
                self::assertFileDoesNotExist($marker, 'Doctor must not include enabled Package entries.');
                if (in_array('--json', $arguments, true)) {
                    $report = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
                    $packages = array_values(array_filter($report['results'],
                        static fn (array $result): bool => $result['name'] === 'packages'));
                    self::assertCount(1, $packages);
                    self::assertSame('fail', $packages[0]['status']);
                    self::assertSame('packages_broken', $packages[0]['code']);
                    self::assertSame('3 installed: 1 enabled, 1 disabled, 1 broken. '
                        . 'Package Broken requires enabled Package Missing.', $packages[0]['summary']);
                } else {
                    self::assertStringContainsString('3 installed: 1 enabled, 1 disabled, 1 broken.',
                        $process->getOutput());
                }
            }
        } finally {
            $project->remove();
        }
    }

    public function testInfrastructureReportsSelectedDriversWithoutConnectionDetails(): void
    {
        $process = $this->command(['infrastructure', '--json']);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $rows = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('array', $rows['cache']['selected']);
        self::assertSame('array', $rows['session']['selected']);
        self::assertSame('array', $rows['rate_limit']['selected']);
        self::assertSame('sync', $rows['queue']['selected']);
        self::assertArrayHasKey('reason', $rows['cache']);
        self::assertArrayNotHasKey('host', $rows['cache']);
        $text = $this->command(['infrastructure']);
        self::assertSame(0, $text->getExitCode());
        self::assertStringContainsString('Infrastructure', $text->getOutput());
    }

    public function testWebEntryHealthEndpointsBypassLegacySessionAndDebugOutput(): void
    {
        $root = dirname(__DIR__, 2);
        $secret = 'SQUEHUB_HEALTH_SECRET_DO_NOT_LEAK';
        $environment = [
            'HEALTH_ENDPOINTS_ENABLED' => 'true', 'SESSION_DRIVER' => 'redis',
            'REDIS_URL' => 'redis://user:' . $secret . '@127.0.0.1:1',
            'DB_CONNECTION' => 'sqlite', 'DB_SQLITE_DATABASE' => ':memory:',
            'CACHE_DRIVER' => 'array', 'RATE_LIMIT_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'APP_DEBUG' => 'true',
            'MAIL_PASSWORD' => $secret, 'APP_KEY' => $secret,
        ];
        foreach (['/health/live' => '{"status":"ok"}|200',
            '/health/ready' => '{"status":"unavailable"}|503'] as $path => $expected) {
            $code = '$_SERVER["REQUEST_METHOD"]="GET"; '
                . '$_SERVER["REQUEST_URI"]=' . var_export($path, true) . '; '
                . 'require "public/index.php"; echo "|" . http_response_code();';
            $process = new Process([PHP_BINARY, '-r', $code], $root, $environment);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            if (EnvironmentSetup::status($root) !== null) {
                self::assertStringContainsString('SqueHub setup required', $process->getOutput());
                self::assertStringEndsWith('|503', trim($process->getOutput()));
            } else {
                self::assertSame($expected, trim($process->getOutput()));
            }
            self::assertStringNotContainsString($secret, $process->getOutput() . $process->getErrorOutput());
            self::assertStringNotContainsString('DEBUG MODE', $process->getOutput());
        }
    }
}
