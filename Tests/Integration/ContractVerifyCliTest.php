<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises report output and execution boundaries in disposable CLI applications. */
final class ContractVerifyCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
        $root = dirname(__DIR__, 2);
        $this->project->write('.example.env', "APP_ENV=development\nAPP_DEBUG=true\nAPP_KEY=\n");
        $this->project->write('.env', "APP_ENV=testing\nAPP_DEBUG=false\nAPP_KEY=base64:"
            . base64_encode(str_repeat('k', 32)) . "\n");
        // The real entry script checks its own directory for .env. Keep that
        // check inside the disposable application rather than the source tree.
        $this->project->write('squehub', (string) file_get_contents($root . '/squehub'));
        $this->project->write('vendor/autoload.php', '<?php require_once '
            . var_export($root . '/vendor/autoload.php', true) . ';');
        $this->project->write('App/Clis/Clis.php', '<?php require '
            . var_export($root . '/App/Clis/Clis.php', true) . ';');
        $runner = <<<'PHP'
<?php
declare(strict_types=1);
require __AUTOLOAD__;
$squehubApp = new \App\Foundation\Application(__DIR__);
$squehubApp->register(\App\Routing\RoutingServiceProvider::class);
$squehubApp->register(\App\Http\HttpServiceProvider::class);
$squehubApp->register(\App\Api\Contract\ContractServiceProvider::class);
$squehubApp->bootstrap();
require __CLI__;
PHP;
        $this->project->write('CliRunner.php', str_replace(
            ['__AUTOLOAD__', '__CLI__'],
            [var_export($root . '/vendor/autoload.php', true),
                var_export($this->project->path('squehub'), true)],
            $runner
        ));
        $this->project->write('Project/Routes/Api.php', <<<'PHP'
<?php
use App\Plugins\{Contract, ContractSchema, Route};

echo 'SQUEHUB_ROUTE_FILE_OUTPUT_SHOULD_BE_DISCARDED';
Route::path('/api/ping')
    ->get(static fn (): \App\Http\JsonResponse => new \App\Http\JsonResponse(['ok' => true]))
    ->named('ping')
    ->contract(Contract::operation()
        ->response(200, ContractSchema::object([
            'ok' => ContractSchema::boolean(),
        ])->required(['ok'])));
PHP);
        $this->project->write('Project/Api/Verification.php', <<<'PHP'
<?php
use App\Plugins\Contract;

echo 'SQUEHUB_CASE_FILE_OUTPUT_SHOULD_BE_DISCARDED';
file_put_contents(__DIR__ . '/loaded.marker', 'loaded');
Contract::verify('ping.success')->operation('ping')->expectStatus(200);
PHP);
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->remove();
        }
    }

    public function testHelpAndDefaultTextReport(): void
    {
        $help = $this->command('contract:verify', '--help');
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        foreach (['--static', '--format', '--mutations', '--operation', '--strict'] as $option) {
            self::assertStringContainsString($option, $help->getOutput());
        }

        $run = $this->command('contract:verify');
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput() . $run->getOutput());
        self::assertStringContainsString('SqueHub API Contract Verification', $run->getOutput());
        self::assertFileExists($this->project->path('Project/Api/loaded.marker'));
    }

    public function testJsonReportIsStableAndContainsNoCaseFileOutput(): void
    {
        $first = $this->command('contract:verify', '--format=json');
        $second = $this->command('contract:verify', '--format=json');
        self::assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        self::assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        self::assertSame($first->getOutput(), $second->getOutput());
        $report = json_decode($first->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('1', $report['squehub_verification']);
        self::assertTrue($report['passed']);
        self::assertArrayHasKey('summary', $report);
        self::assertArrayHasKey('findings', $report);
        self::assertStringNotContainsString('loaded.marker', $first->getOutput());
        self::assertStringNotContainsString('SQUEHUB_ROUTE_FILE_OUTPUT_SHOULD_BE_DISCARDED',
            $first->getOutput());
        self::assertStringNotContainsString('SQUEHUB_CASE_FILE_OUTPUT_SHOULD_BE_DISCARDED',
            $first->getOutput());
    }

    public function testStaticAndExportNeverLoadExecutableCases(): void
    {
        $static = $this->command('contract:verify', '--static', '--format=json');
        self::assertSame(0, $static->getExitCode(), $static->getErrorOutput());
        self::assertFileDoesNotExist($this->project->path('Project/Api/loaded.marker'));

        $export = $this->command('contract:export', '--format=squehub');
        self::assertSame(0, $export->getExitCode(), $export->getErrorOutput());
        self::assertFileDoesNotExist($this->project->path('Project/Api/loaded.marker'));
    }

    public function testProductionDefaultsToStaticAndRejectsMutationRequest(): void
    {
        $this->project->write('Config/App.php', "<?php return ['env' => 'production', 'debug' => false];");
        $static = $this->command('contract:verify', '--format=json');
        self::assertSame(0, $static->getExitCode(), $static->getErrorOutput());
        self::assertFileDoesNotExist($this->project->path('Project/Api/loaded.marker'));
        $report = json_decode($static->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('1', $report['squehub_verification']);

        $refused = $this->command('contract:verify', '--mutations');
        self::assertNotSame(0, $refused->getExitCode());
        self::assertSame('', $refused->getOutput());
        self::assertStringContainsString('requires a development or testing environment',
            $refused->getErrorOutput());
        self::assertFileDoesNotExist($this->project->path('Project/Api/loaded.marker'));
    }

    public function testInvalidOptionsFailWithoutPrivateReportOrMixedStdout(): void
    {
        foreach ([['--format=yaml'], ['--mutations', '--static']] as $options) {
            $run = $this->command('contract:verify', ...$options);
            self::assertNotSame(0, $run->getExitCode());
            self::assertSame('', $run->getOutput());
            self::assertNotSame('', $run->getErrorOutput());
            self::assertFileDoesNotExist($this->project->path('Project/Api/loaded.marker'));
        }
    }

    public function testRuntimeMismatchFailsWithSafeMachineFinding(): void
    {
        $this->project->write('Project/Routes/Api.php', <<<'PHP'
<?php
use App\Plugins\{Contract, ContractSchema, Route};

Route::path('/api/ping')
    ->get(static fn (): \App\Http\JsonResponse => new \App\Http\JsonResponse([
        'ok' => 'SQUEHUB_VERIFICATION_SECRET_DO_NOT_LEAK',
    ]))
    ->named('ping')
    ->contract(Contract::operation()
        ->response(200, ContractSchema::object([
            'ok' => ContractSchema::boolean(),
        ])->required(['ok'])));
PHP);

        $run = $this->command('contract:verify', '--format=json');
        self::assertNotSame(0, $run->getExitCode());
        self::assertSame('', $run->getErrorOutput());
        $report = json_decode($run->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertFalse($report['passed']);
        self::assertContains('response_schema_mismatch', array_column($report['findings'], 'code'));
        self::assertStringNotContainsString('SQUEHUB_VERIFICATION_SECRET_DO_NOT_LEAK',
            $run->getOutput() . $run->getErrorOutput());
    }

    public function testMutationCaseRequiresExplicitOption(): void
    {
        $this->project->write('Project/Routes/Api.php', <<<'PHP'
<?php
use App\Plugins\{Contract, ContractSchema, Route};

Route::path('/api/ping')
    ->get(static function (): \App\Http\JsonResponse {
        file_put_contents(dirname(__DIR__) . '/executed.marker', 'executed');
        return new \App\Http\JsonResponse(['ok' => true]);
    })
    ->named('ping')
    ->contract(Contract::operation()
        ->response(200, ContractSchema::object([
            'ok' => ContractSchema::boolean(),
        ])->required(['ok'])));
PHP);
        $this->project->write('Project/Api/Verification.php', <<<'PHP'
<?php
use App\Plugins\Contract;

Contract::verify('ping.mutation')->operation('ping')->mutation()->expectStatus(200);
PHP);

        $default = $this->command('contract:verify', '--format=json');
        self::assertSame(0, $default->getExitCode(), $default->getErrorOutput());
        self::assertFileDoesNotExist($this->project->path('Project/executed.marker'));
        $defaultReport = json_decode($default->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(0, $defaultReport['summary']['cases_executed']);
        self::assertSame(1, $defaultReport['summary']['cases_skipped']);

        $allowed = $this->command('contract:verify', '--mutations', '--format=json');
        self::assertSame(0, $allowed->getExitCode(), $allowed->getErrorOutput());
        self::assertFileExists($this->project->path('Project/executed.marker'));
        $allowedReport = json_decode($allowed->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1, $allowedReport['summary']['cases_executed']);
    }

    public function testUnmarkedPostCaseAlsoRequiresMutationOption(): void
    {
        $this->project->write('Project/Routes/Api.php', <<<'PHP'
<?php
use App\Plugins\{Contract, ContractSchema, Route};

Route::path('/api/ping')
    ->post(static function (): \App\Http\JsonResponse {
        file_put_contents(dirname(__DIR__) . '/post.executed', 'executed');
        return new \App\Http\JsonResponse(['ok' => true]);
    })
    ->named('ping')
    ->contract(Contract::operation()
        ->response(200, ContractSchema::object([
            'ok' => ContractSchema::boolean(),
        ])->required(['ok'])));
PHP);
        $this->project->write('Project/Api/Verification.php', <<<'PHP'
<?php
use App\Plugins\Contract;

// Deliberately unmarked: the unsafe HTTP method itself still gates execution.
Contract::verify('ping.post')->operation('ping')->expectStatus(200);
PHP);

        $default = $this->command('contract:verify', '--format=json');
        self::assertSame(0, $default->getExitCode(), $default->getErrorOutput());
        self::assertFileDoesNotExist($this->project->path('Project/post.executed'));
        $defaultReport = json_decode($default->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1, $defaultReport['summary']['cases_skipped']);

        $allowed = $this->command('contract:verify', '--mutations', '--format=json');
        self::assertSame(0, $allowed->getExitCode(), $allowed->getErrorOutput());
        self::assertFileExists($this->project->path('Project/post.executed'));
        $allowedReport = json_decode($allowed->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1, $allowedReport['summary']['cases_executed']);
    }

    public function testStrictCoverageFailsForUncoveredOperation(): void
    {
        $this->project->write('Project/Routes/Other.php', <<<'PHP'
<?php
use App\Plugins\{Contract, ContractSchema, Route};

Route::path('/api/other')
    ->get(static fn (): \App\Http\JsonResponse => new \App\Http\JsonResponse(['ok' => true]))
    ->named('other')
    ->contract(Contract::operation()
        ->response(200, ContractSchema::object([
            'ok' => ContractSchema::boolean(),
        ])->required(['ok'])));
PHP);

        $default = $this->command('contract:verify', '--format=json');
        self::assertSame(0, $default->getExitCode(), $default->getErrorOutput());
        $strict = $this->command('contract:verify', '--strict', '--format=json');
        self::assertNotSame(0, $strict->getExitCode());
        $report = json_decode($strict->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertFalse($report['passed']);
        self::assertSame(1, $report['summary']['operations_without_cases']);
        self::assertContains('operation_uncovered', array_column($report['findings'], 'code'));
    }

    public function testOperationFilterNarrowsReportAndUnknownOperationFails(): void
    {
        $selected = $this->command('contract:verify', '--operation=ping', '--format=json');
        self::assertSame(0, $selected->getExitCode(), $selected->getErrorOutput());
        $report = json_decode($selected->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['summary']['public_operations']);
        self::assertSame(1, $report['summary']['cases_executed']);

        $unknown = $this->command('contract:verify', '--operation=missing.operation', '--format=json');
        self::assertNotSame(0, $unknown->getExitCode());
        $unknownReport = json_decode($unknown->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertContains('route_missing', array_column($unknownReport['findings'], 'code'));
        self::assertStringNotContainsString('SQUEHUB_CASE_FILE_OUTPUT_SHOULD_BE_DISCARDED',
            $unknown->getOutput());
    }

    public function testCaseRegistrationFailureIsRedactedFromCliStreams(): void
    {
        $this->project->write('Project/Api/Verification.php', <<<'PHP'
<?php
throw new \RuntimeException('SQUEHUB_VERIFICATION_SECRET_DO_NOT_LEAK');
PHP);

        $run = $this->command('contract:verify', '--format=json');
        self::assertNotSame(0, $run->getExitCode());
        self::assertSame('', $run->getOutput());
        self::assertStringContainsString('Contract verification could not complete',
            $run->getErrorOutput());
        self::assertStringNotContainsString('SQUEHUB_VERIFICATION_SECRET_DO_NOT_LEAK',
            $run->getOutput() . $run->getErrorOutput());
    }

    public function testSetupAndCleanupEchoCannotCorruptJsonReport(): void
    {
        $this->project->write('Project/Api/Verification.php', <<<'PHP'
<?php
use App\Plugins\Contract;

Contract::verify('ping.with.fixture')
    ->operation('ping')
    ->setup(static function (\App\Foundation\Application $app): void {
        echo 'SQUEHUB_FIXTURE_SETUP_OUTPUT_SHOULD_BE_DISCARDED';
    })
    ->cleanup(static function (\App\Foundation\Application $app): void {
        echo 'SQUEHUB_FIXTURE_CLEANUP_OUTPUT_SHOULD_BE_DISCARDED';
    })
    ->expectStatus(200);
PHP);

        $run = $this->command('contract:verify', '--format=json');
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $report = json_decode($run->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        self::assertTrue($report['passed']);
        self::assertSame(1, $report['summary']['cases_executed']);
        self::assertStringNotContainsString('SQUEHUB_FIXTURE_SETUP_OUTPUT_SHOULD_BE_DISCARDED',
            $run->getOutput());
        self::assertStringNotContainsString('SQUEHUB_FIXTURE_CLEANUP_OUTPUT_SHOULD_BE_DISCARDED',
            $run->getOutput());
    }

    private function command(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'),
            ...$arguments, '--no-ansi'], $this->project->path());
        $process->run();
        return $process;
    }
}
