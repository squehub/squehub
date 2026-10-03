<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\EnvironmentSetup;
use App\Http\EnvironmentSetupResponse;
use App\Http\JsonResponse;
use App\Http\Request;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises the real CLI and web entry files against isolated setup states. */
final class EnvironmentSetupTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $root = dirname(__DIR__, 2);
        $this->project->write('vendor/autoload.php', '<?php require_once '
            . var_export($root . '/vendor/autoload.php', true) . ';');
        $this->project->write('squehub', (string) file_get_contents($root . '/squehub'));
        $this->project->write('App/Clis/Clis.php', '<?php echo "CLI_READY";');
        $this->project->write('Bootstrap/Web.php', (string) file_get_contents($root . '/Bootstrap/Web.php'));
        $this->project->write('web.php', <<<'PHP'
            <?php
            $_SERVER['REQUEST_METHOD'] = $argv[1] ?? 'GET';
            $_SERVER['REQUEST_URI'] = '/';
            if (($argv[2] ?? '') === 'json') $_SERVER['HTTP_ACCEPT'] = 'application/json';
            require __DIR__ . '/Bootstrap/Web.php';
            echo '|' . http_response_code();
            PHP);
        $this->project->write('.example.env', "APP_NAME=Example\nAPP_KEY=\n");
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testMissingDotenvShowsWebPageAndCliNoticeWithoutBlockingCommands(): void
    {
        self::assertStringContainsString('.env file is missing',
            (string) EnvironmentSetup::notice($this->project->path()));

        $cli = $this->runEntry('squehub');
        self::assertSame(0, $cli->getExitCode(), $cli->getErrorOutput());
        self::assertSame('CLI_READY', $cli->getOutput());
        self::assertStringContainsString('.env file is missing', $cli->getErrorOutput());

        $web = $this->runEntry('web.php');
        self::assertSame(0, $web->getExitCode(), $web->getErrorOutput());
        self::assertStringContainsString('<h1>SqueHub setup required</h1>', $web->getOutput());
        self::assertStringContainsString('.env file is missing', $web->getOutput());
        self::assertStringEndsWith('|503', $web->getOutput());

        $head = $this->runEntry('web.php', 'HEAD');
        self::assertSame('|503', $head->getOutput());
    }

    public function testUnchangedTemplateWarnsForHtmlJsonAndCliUntilValuesChange(): void
    {
        // A comment, line-ending change, or reordered key does not configure
        // the copied template. Only parsed value changes dismiss the notice.
        $this->project->write('.env', "APP_KEY=\r\n# Reviewed\r\nAPP_NAME=Example\r\n");
        self::assertStringContainsString('example settings',
            (string) EnvironmentSetup::notice($this->project->path()));

        $cli = $this->runEntry('squehub');
        self::assertSame('CLI_READY', $cli->getOutput());
        self::assertStringContainsString('example settings', $cli->getErrorOutput());

        $html = $this->runEntry('web.php');
        self::assertStringContainsString('example settings', $html->getOutput());
        self::assertStringEndsWith('|503', $html->getOutput());

        $json = $this->runEntry('web.php', 'GET', 'json');
        self::assertStringEndsWith('|503', $json->getOutput());
        $body = substr($json->getOutput(), 0, -4);
        self::assertStringContainsString('example settings',
            json_decode($body, true, 32, JSON_THROW_ON_ERROR)['message']);
        self::assertStringNotContainsString('<html', $body);

        $this->project->write('.env', "APP_NAME=Example\nAPP_KEY=base64:configured\n");
        self::assertNull(EnvironmentSetup::notice($this->project->path()));
        $configuredCli = $this->runEntry('squehub');
        self::assertSame('CLI_READY', $configuredCli->getOutput());
        self::assertSame('', $configuredCli->getErrorOutput());
    }

    public function testInvalidFileUsesNormalBootstrapErrorPath(): void
    {
        $this->project->write('.env', "APP KEY=secret-should-not-appear\n");
        self::assertNull(EnvironmentSetup::notice($this->project->path()));
    }

    public function testSetupResponseIsUncachedAndEscapesHtml(): void
    {
        $notice = 'Review <APP_KEY> before continuing.';
        $html = EnvironmentSetupResponse::forRequest(new Request(), $notice);
        self::assertSame(503, $html->status());
        self::assertSame('no-store', $html->header('Cache-Control'));
        self::assertStringContainsString('background: #3782ab', $html->content());
        self::assertStringContainsString('name="viewport"', $html->content());
        self::assertStringContainsString('Copy-Item .example.env .env', $html->content());
        self::assertStringContainsString('&lt;APP_KEY&gt;', $html->content());
        self::assertStringNotContainsString('<APP_KEY>', $html->content());

        $json = EnvironmentSetupResponse::forRequest(
            new Request(headers: ['Accept' => 'application/json']), $notice);
        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame(503, $json->status());
        self::assertSame('no-store', $json->header('Cache-Control'));
        self::assertSame(['message' => $notice], json_decode($json->content(), true, 32, JSON_THROW_ON_ERROR));
    }

    private function runEntry(string $entry, string ...$arguments): Process
    {
        $process = new Process([PHP_BINARY, $entry, ...$arguments], $this->project->path());
        $process->run();
        return $process;
    }
}
