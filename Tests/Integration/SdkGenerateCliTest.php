<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises the real SDK command against a configured disposable application. */
final class SdkGenerateCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $this->project->write('.example.env', "APP_ENV=development\nAPP_KEY=\n");
        $this->project->write('.env', "APP_ENV=testing\nAPP_KEY=base64:"
            . base64_encode(str_repeat('s', 32)) . "\n");
        $this->project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
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
        $this->project->write('Project/Routes/Api.php', $this->route('string'));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAllLanguagesGenerateAndCheckWithoutRepositoryEnvironment(): void
    {
        foreach (['typescript' => 'client.ts', 'javascript' => 'client.js',
            'php' => 'src/Client.php'] as $language => $source) {
            $output = 'Generated/' . ucfirst($language);
            $run = $this->command('--language=' . $language, '--output=' . $output);
            self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
            self::assertSame('', $run->getErrorOutput());
            self::assertFileExists($this->project->path($output . '/' . $source));
            $manifest = json_decode((string) file_get_contents(
                $this->project->path($output . '/squehub-sdk.json')),
                true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('1', $manifest['squehub_sdk']);
            self::assertSame($language, $manifest['language']);
            self::assertCount(1, $manifest['operations']);
            $check = $this->command('--language=' . $language,
                '--output=' . $output, '--check');
            self::assertSame(0, $check->getExitCode(), $check->getErrorOutput());
        }
    }

    public function testCheckFindsStaleOutputWithoutChangingFiles(): void
    {
        $arguments = ['--language=typescript', '--output=Generated/Client'];
        self::assertSame(0, $this->command(...$arguments)->getExitCode());
        $manifestPath = $this->project->path('Generated/Client/squehub-sdk.json');
        $before = (string) file_get_contents($manifestPath);
        $this->project->write('Project/Routes/Api.php', $this->route('integer'));

        $stale = $this->command(...[...$arguments, '--check']);
        self::assertNotSame(0, $stale->getExitCode());
        self::assertSame('', $stale->getOutput());
        self::assertStringContainsString('stale', $stale->getErrorOutput());
        self::assertSame($before, file_get_contents($manifestPath));

        self::assertSame(0, $this->command(...$arguments)->getExitCode());
        self::assertNotSame($before, file_get_contents($manifestPath));
        self::assertSame(0, $this->command(...[...$arguments, '--check'])->getExitCode());
    }

    public function testProtectedOutputAndEmptyContractFailClearly(): void
    {
        $unsafe = $this->command('--language=php', '--output=App/Generated');
        self::assertNotSame(0, $unsafe->getExitCode());
        self::assertFileDoesNotExist($this->project->path('App/Generated/src/Client.php'));

        $this->project->write('Project/Routes/Api.php', '<?php');
        $empty = $this->command('--language=php', '--output=Generated/Empty');
        self::assertNotSame(0, $empty->getExitCode());
        self::assertStringContainsString('No public contracted API operations',
            $empty->getErrorOutput());
    }

    private function command(string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $this->project->path('CliRunner.php'),
            'sdk:generate', ...$arguments, '--no-ansi'], $this->project->path());
        $process->run();
        return $process;
    }

    private function route(string $type): string
    {
        $schema = $type === 'string' ? 'ContractSchema::string()' : 'ContractSchema::integer()';
        return str_replace('__SCHEMA__', $schema, <<<'PHP'
<?php
use App\Plugins\{Contract, ContractSchema, Route};

Route::path('/api/items/{id}')
    ->get(static fn (): \App\Http\JsonResponse => new \App\Http\JsonResponse(['value' => 'sample']))
    ->named('items.show')
    ->contract(Contract::operation()
        ->path('id', ContractSchema::integer())
        ->response(200, ContractSchema::object([
            'value' => __SCHEMA__,
        ])->required(['value'])));
PHP
        );
    }
}
