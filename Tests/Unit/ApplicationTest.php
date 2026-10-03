<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Container\Container;
use App\Foundation\Application;
use App\Foundation\Environment;
use App\Foundation\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class ProviderTrace { public array $calls = []; }
final class ProvidedService {}

final class FirstProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->make(ProviderTrace::class)->calls[] = 'register:first';
        $this->app->container()->instance(ProvidedService::class, new ProvidedService());
    }

    public function boot(): void
    {
        $this->app->container()->make(ProviderTrace::class)->calls[] = 'boot:first';
    }
}

final class SecondProvider extends ServiceProvider
{
    public function __construct(Application $app, private ProvidedService $service)
    {
        parent::__construct($app);
    }

    public function register(): void
    {
        $this->app->container()->make(ProviderTrace::class)->calls[] = 'register:second';
    }

    public function boot(): void
    {
        if (!$this->service instanceof ProvidedService) {
            throw new RuntimeException('Dependency unavailable.');
        }
        $this->app->container()->make(ProviderTrace::class)->calls[] = 'boot:second';
    }
}

final class FailingProvider extends ServiceProvider
{
    public function register(): void
    {
        throw new RuntimeException('provider failed');
    }
}

final class FailingBootProvider extends ServiceProvider
{
    public function boot(): void
    {
        throw new RuntimeException('boot failed');
    }
}

final class ApplicationTest extends TestCase
{
    private TemporaryProject $project;
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["name" => $environment->get("APP_NAME", "Fixture"), "env" => $environment->get("APP_ENV", "testing"), "debug" => $environment->boolean("APP_DEBUG", false)];');
        foreach (['APP_NAME', 'APP_ENV', 'APP_DEBUG'] as $key) {
            $this->originalEnv[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null, getenv($key)];
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => [$present, $value, $external]) {
            if ($present) {
                $_ENV[$key] = $value;
            } else {
                unset($_ENV[$key]);
            }
            if ($external === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $external);
            }
        }
        $this->project->remove();
    }

    public function testOwnsPathsContainerAndConfiguration(): void
    {
        $app = new Application($this->project->path());
        self::assertSame(str_replace('\\', '/', realpath($this->project->path())), $app->basePath());
        self::assertSame($app->basePath('App'), $app->appPath());
        self::assertSame($app->basePath('Project'), $app->projectPath());
        self::assertSame($app->basePath('Config'), $app->configPath());
        self::assertSame($app->basePath('public'), $app->publicPath());
        self::assertSame($app, $app->container()->make(Application::class));
        self::assertSame($app->container(), $app->container()->make(Container::class));
        self::assertSame($app->config(), $app->container()->make(Repository::class));
        self::assertInstanceOf(Environment::class, $app->container()->make(Environment::class));
        self::assertFalse($app->isBooted());
        $app->bootstrap();
        self::assertTrue($app->isBooted());
        self::assertSame('Fixture', $app->config()->get('app.name'));
    }

    public function testEnvironmentAndDebugComeFromConfiguration(): void
    {
        $this->project->write('.env', "APP_ENV=staging\nAPP_DEBUG=yes\nAPP_NAME=Milestone\n");
        $app = new Application($this->project->path());
        $app->bootstrap();
        self::assertSame('staging', $app->environment());
        self::assertTrue($app->isDebug());
        self::assertSame('Milestone', $app->config()->get('app.name'));
    }

    public function testBootstrapsWithLegacyLowercaseConfigDirectoryAndFilename(): void
    {
        $legacy = new TemporaryProject();
        try {
            $legacy->write('config/app.php', '<?php return ["name" => "Legacy casing"];');
            $app = new Application($legacy->path());
            $app->bootstrap();

            self::assertSame('Legacy casing', $app->config()->get('app.name'));
        } finally {
            $legacy->remove();
        }
    }

    public function testProvidersRegisterBeforeAnyBootAndOnlyOnce(): void
    {
        $app = new Application($this->project->path());
        $trace = new ProviderTrace();
        $app->container()->instance(ProviderTrace::class, $trace);
        $app->register(FirstProvider::class);
        $app->register(FirstProvider::class);
        $app->register(SecondProvider::class);
        self::assertSame([FirstProvider::class, SecondProvider::class], $app->providers());
        self::assertTrue($app->hasProvider(FirstProvider::class));
        self::assertFalse($app->isProviderBooted(FirstProvider::class));
        $app->bootstrap();
        $app->bootstrap();
        self::assertSame(['register:first', 'register:second', 'boot:first', 'boot:second'], $trace->calls);
        self::assertTrue($app->isProviderBooted(FirstProvider::class));
        self::assertTrue($app->isProviderBooted(SecondProvider::class));
        $this->expectException(LogicException::class);
        $app->register(FailingProvider::class);
    }

    public function testInvalidBasePathAndProviderAreRejected(): void
    {
        try {
            new Application($this->project->path('missing'));
            self::fail('Invalid base path was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('base path', $exception->getMessage());
        }
        $app = new Application($this->project->path());
        $this->expectException(InvalidArgumentException::class);
        $app->register(ProvidedService::class);
    }

    public function testProviderFailureIsNotSwallowedOrRepeated(): void
    {
        $app = new Application($this->project->path());
        $app->register(FailingProvider::class);
        try {
            $app->bootstrap();
            self::fail('Provider failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('provider failed', $exception->getMessage());
        }
        self::assertFalse($app->isBooted());
        self::assertFalse($app->isProviderBooted(FailingProvider::class));
        $this->expectException(LogicException::class);
        $app->bootstrap();
    }

    public function testBootFailureLeavesApplicationUnbooted(): void
    {
        $app = new Application($this->project->path());
        $app->register(FailingBootProvider::class);
        try {
            $app->bootstrap();
            self::fail('Boot failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('boot failed', $exception->getMessage());
        }
        self::assertFalse($app->isBooted());
        self::assertFalse($app->isProviderBooted(FailingBootProvider::class));
    }
}
