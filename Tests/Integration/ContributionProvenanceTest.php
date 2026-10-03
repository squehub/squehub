<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Contributions\ContributionOwner;
use App\Core\View;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real Package boot and route loading should yield safe, active provenance. */
final class ContributionProvenanceTest extends TestCase
{
    private ?PackageManager $previousViewManager = null;
    private ?string $previousViewRoot = null;
    private array $previousViewPaths = [];

    protected function setUp(): void
    {
        $this->previousViewManager = (new \ReflectionProperty(View::class, 'packageManager'))->getValue();
        $this->previousViewRoot = (new \ReflectionProperty(View::class, 'applicationRoot'))->getValue();
        $this->previousViewPaths = (new \ReflectionProperty(View::class, 'viewPaths'))->getValue();
    }

    protected function tearDown(): void
    {
        View::setPackageManager($this->previousViewManager, $this->previousViewRoot);
        (new \ReflectionProperty(View::class, 'viewPaths'))->setValue($this->previousViewPaths);
    }

    public function testEnabledPackageAndApplicationRegistrationsHaveDistinctOwners(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
            $project->write('Project/Routes/web.php', <<<'PHP'
<?php \App\Routing\Route::path('/local')->get(static fn (): string => 'local')->named('local');
PHP);
            $project->write('Project/Packages/ProvenanceWeather/ProvenanceWeather.php', <<<'PHP'
<?php namespace Packages\ProvenanceWeather;
final class ProvenanceWeather extends \App\Plugins\ServiceProvider {
    public function register(): void {
        $this->app->container()->singleton('forecast.service', \stdClass::class);
        $this->app->container()->make(\App\Routing\MiddlewareRegistry::class)
            ->alias('forecast.auth', \stdClass::class);
        $this->app->config()->set('packages.ProvenanceWeather.units',
            'PLANTED_CONFIG_SECRET_DO_NOT_PERSIST');
    }
}
PHP);
            $project->write('Project/Packages/ProvenanceWeather/Routes/web.php', <<<'PHP'
<?php \App\Routing\Route::path('/forecast')->get(
    [\Packages\ProvenanceWeather\Controllers\ForecastController::class, 'show']
)->named('forecast.show')->through('forecast.auth');
PHP);
            $project->write('Project/Packages/ProvenanceWeather/Views/Welcome.squehub.php',
                'Package welcome');
            $project->write('Project/Packages/ProvenanceWeather/Views/PackageOnly.squehub.php',
                'Package only');
            $project->write('Project/Views/Welcome.squehub.php', 'Application welcome');

            $manager = new PackageManager(new Application($project->path()));
            $manager->apply($manager->planEnable('ProvenanceWeather'));

            $app = new Application($project->path());
            $app->register(RoutingServiceProvider::class);
            $app->bootstrap();
            $squehubApp = $app;
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            $registry = $app->contributions();
            $package = new ContributionOwner('package', 'ProvenanceWeather');
            self::assertSame('package:ProvenanceWeather',
                $registry->ownerOf('route', 'GET /forecast')?->key());
            self::assertSame('application:Project',
                $registry->ownerOf('route', 'GET /local')?->key());
            $route = $registry->byType('route');
            $forecast = array_values(array_filter($route,
                static fn ($item): bool => $item->identifier === 'GET /forecast'))[0];
            self::assertSame('forecast.show', $forecast->metadata['name']);
            self::assertSame('forecast.auth', $forecast->metadata['middleware']);
            self::assertSame('Packages\\ProvenanceWeather\\Controllers\\ForecastController',
                $forecast->metadata['controller']);
            self::assertSame('Project/Packages/ProvenanceWeather/Routes/web.php', $forecast->source);
            self::assertCount(2, $app->container()->make(RouteRegistry::class)->all());

            self::assertSame($package->key(),
                $registry->ownerOf('middleware', 'forecast.auth')?->key());
            self::assertSame($package->key(),
                $registry->ownerOf('service', 'forecast.service')?->key());
            self::assertSame($package->key(),
                $registry->ownerOf('config', 'packages.ProvenanceWeather.units')?->key());

            $packageView = View::sourceOf('packageOnly');
            self::assertSame($package->key(), $packageView?->owner->key());
            self::assertSame('Project/Packages/ProvenanceWeather/Views/PackageOnly.squehub.php',
                $packageView?->source);
            $selected = View::sourceOf('welcome');
            self::assertSame('application:Project', $selected?->owner->key());
            self::assertSame('Project/Packages/ProvenanceWeather/Views/Welcome.squehub.php',
                $selected?->metadata['overrides']);

            $metadata = json_encode(array_map(static fn ($item): array => $item->toArray(),
                $registry->byOwner($package)), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('PLANTED_CONFIG_SECRET_DO_NOT_PERSIST', $metadata);
            self::assertStringNotContainsString(str_replace('\\', '/', $project->path()), $metadata);
            self::assertNull($registry->currentOwner());
        } finally {
            $project->remove();
        }
    }

    public function testDisabledPackageDoesNotContributeRoutesAtRuntime(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', "<?php return ['env' => 'testing', 'debug' => false];");
            $project->write('Project/Packages/ProvenanceDisabled/ProvenanceDisabled.php',
                '<?php namespace Packages\\ProvenanceDisabled; final class ProvenanceDisabled '
                . 'extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/ProvenanceDisabled/Routes/web.php',
                '<?php \\App\\Routing\\Route::path("/disabled")->get(static fn () => "disabled");');

            $app = new Application($project->path());
            $app->register(RoutingServiceProvider::class);
            $app->bootstrap();
            $squehubApp = $app;
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            self::assertSame([], $app->container()->make(RouteRegistry::class)->all());
            self::assertNull($app->contributions()->ownerOf('route', 'GET /disabled'));
            self::assertSame([], $app->contributions()->byOwner(
                new ContributionOwner('package', 'ProvenanceDisabled')));
        } finally {
            $project->remove();
        }
    }
}
