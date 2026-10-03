<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\ConfigurationException;
use App\Config\Loader;
use App\Config\Repository;
use App\Foundation\Environment;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class ConfigurationTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRepositorySupportsNestedGetSetDefaultsAndNull(): void
    {
        $config = new Repository(['app' => ['name' => 'SqueHub', 'optional' => null]]);
        self::assertSame('SqueHub', $config->get('app.name'));
        self::assertTrue($config->has('app.optional'));
        self::assertNull($config->get('app.optional', 'fallback'));
        self::assertSame('UTC', $config->get('app.timezone', 'UTC'));
        self::assertFalse($config->has('app.timezone'));
        $config->set('app.timezone', 'America/Los_Angeles');
        self::assertSame('America/Los_Angeles', $config->get('app.timezone'));
        self::assertSame('America/Los_Angeles', $config->all()['app']['timezone']);
    }

    public function testLoaderReadsTopLevelPhpArraysInDeterministicNamespaces(): void
    {
        $this->project->write('Config/App.php', '<?php return ["name" => "Fixture"];');
        $this->project->write('Config/Mail.php', '<?php return ["port" => 25];');
        $this->project->write('Config/Debug.php', '<?php throw new RuntimeException("executed");');
        $this->project->write('Config/Nested/Ignored.php', '<?php throw new RuntimeException("executed");');
        $this->project->write('Config/Notes.txt', 'not configuration');
        $config = new Repository();
        (new Loader())->load($this->project->path('Config'), new Environment($this->project->path()), $config);
        self::assertSame(['app' => ['name' => 'Fixture'], 'mail' => ['port' => 25]], $config->all());
    }

    public function testLoaderUsesSameNamespaceForLowercaseFilename(): void
    {
        $this->project->write('Config/app.php', '<?php return ["name" => "Lowercase filename"];');
        $config = new Repository();

        (new Loader())->load($this->project->path('Config'), new Environment($this->project->path()), $config);

        self::assertSame('Lowercase filename', $config->get('app.name'));
        self::assertSame(['app' => ['name' => 'Lowercase filename']], $config->all());
    }

    public function testLoaderRejectsConfigThatDoesNotReturnArray(): void
    {
        $this->project->write('Config/Broken.php', '<?php return 42;');
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('broken.php');
        (new Loader())->load($this->project->path('Config'), new Environment($this->project->path()), new Repository());
    }

    public function testLoaderRejectsMissingDirectory(): void
    {
        $this->expectException(ConfigurationException::class);
        (new Loader())->load($this->project->path('missing'), new Environment($this->project->path()), new Repository());
    }

    public function testRealAppConfigDisablesDebugByDefaultInProduction(): void
    {
        $root = dirname(__DIR__, 2);
        $this->project->write('.env', "APP_ENV=production\n");
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$environment = new App\\Foundation\\Environment(' . var_export($this->project->path(), true) . '); '
            . '$values = require ' . var_export($root . '/Config/App.php', true) . '; '
            . 'echo json_encode([$values["env"], $values["debug"]]);';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('["production",false]', $process->getOutput());
    }

    public function testRealAppConfigRequiresExplicitDebugOptInDuringDevelopment(): void
    {
        $root = dirname(__DIR__, 2);
        $this->project->write('.env', "APP_ENV=development\n");
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
            . '$environment = new App\\Foundation\\Environment(' . var_export($this->project->path(), true) . '); '
            . '$values = require ' . var_export($root . '/Config/App.php', true) . '; '
            . 'echo json_encode([$values["env"], $values["debug"]]);';
        $process = new Process([PHP_BINARY, '-r', $code], $root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('["development",false]', $process->getOutput());
    }
}
