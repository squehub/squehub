<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Agent commands stay visible in ordinary help without starting MCP. */
final class AgentCliTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "testing"];');
        $this->project->write('Config/Agent.php', '<?php return ["grants" => []];');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testHelpListsAgentCommandsWithoutStartingTransport(): void
    {
        $list = $this->runCli(['help', '--raw']);
        self::assertSame(0, $list->getExitCode(), $list->getErrorOutput());
        self::assertStringContainsString('agent:mcp', $list->getOutput());
        self::assertStringContainsString('agent:status', $list->getOutput());

        $help = $this->runCli(['agent:mcp', '--help', '--no-ansi']);
        self::assertSame(0, $help->getExitCode(), $help->getErrorOutput());
        self::assertStringContainsString('STDIO', $help->getOutput());
        self::assertStringContainsString('read-only', $help->getOutput());
        self::assertStringContainsString('mcp/sdk', $help->getOutput());
        self::assertDirectoryDoesNotExist($this->project->path('Storage'));
    }

    public function testStatusReportsOnlySafeMetadata(): void
    {
        $status = $this->runCli(['agent:status', '--json', '--no-ansi']);
        self::assertSame(0, $status->getExitCode(), $status->getOutput() . $status->getErrorOutput());
        $data = json_decode(trim($status->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SqueHub', $data['framework']['name']);
        self::assertSame('2.0.0', $data['framework']['version']);
        self::assertArrayNotHasKey('release', $data['framework']);
        self::assertSame('2025-11-25', $data['protocol']['supported_stdio']);
        self::assertSame('read-only', $data['mode']);
        self::assertFalse($data['remote_http']);
        self::assertArrayHasKey('mcp_sdk_available', $data);
        self::assertStringNotContainsString($this->project->path(), $status->getOutput());
        self::assertDirectoryDoesNotExist($this->project->path('Storage'));
    }

    public function testUnsafeCapabilityConfigurationFailsWithoutLeakingValues(): void
    {
        $marker = 'SQUEHUB_AGENT_CLI_SECRET_DO_NOT_LEAK';
        $this->project->write('Config/Agent.php', '<?php return ["grants" => '
            . '["read_source" => true], "marker" => ' . var_export($marker, true) . '];');

        $status = $this->runCli(['agent:status', '--json', '--no-ansi']);
        self::assertSame(1, $status->getExitCode());
        self::assertStringContainsString('could not be inspected safely', $status->getOutput());
        self::assertStringNotContainsString($marker, $status->getOutput() . $status->getErrorOutput());
    }

    /** Run the full command registry with one disposable Application. */
    private function runCli(array $arguments): Process
    {
        $root = dirname(__DIR__, 2);
        $runner = $this->project->path('RunCli.php');
        $this->project->write('RunCli.php', '<?php declare(strict_types=1);' . PHP_EOL
            . 'require_once ' . var_export($root . '/vendor/autoload.php', true) . ';' . PHP_EOL
            . '$squehubApp = new \\App\\Foundation\\Application(__DIR__);' . PHP_EOL
            . '\\App\\Foundation\\CliBootstrapMode::configure($squehubApp, $_SERVER["argv"] ?? []);' . PHP_EOL
            . '$squehubApp->bootstrap();' . PHP_EOL
            . 'require ' . var_export($root . '/App/Clis/Clis.php', true) . ';' . PHP_EOL);
        $process = new Process([PHP_BINARY, $runner, ...$arguments], $this->project->path(),
            ['APP_ENV' => 'testing']);
        $process->run();
        return $process;
    }
}
