<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Request;
use App\Packages\PackageManager;
use App\Routing\ControllerDispatcher;
use App\Routing\Route;
use App\Routing\RouteDefinition;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__) . '/Fixtures/Controllers/PackageProbeController.php';
require_once dirname(__DIR__) . '/Fixtures/Controllers/LegacyPackageController.php';

final class PackageCasingTest extends TestCase
{
    public function testCanonicalPackageRouteDirectoryLoadsOnce(): void
    {
        $this->assertPackageRouteLoads(
            'Project/Packages/Canonical/Routes/Web.php',
            '/canonical'
        );
    }

    public function testLegacyCasingDoesNotSilentlyActivatePackage(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('project/packages/Legacy/routes/web.php',
                "<?php \\App\\Routing\\Route::path('/legacy-package')->get(static fn (): string => 'loaded');");
            $project->write('project/packages/Legacy/Legacy.php',
                '<?php namespace Packages\\Legacy; final class Legacy extends \\App\\Plugins\\ServiceProvider {}');
            $squehubApp = new Application($project->path());
            $squehubApp->register(HttpServiceProvider::class);
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            self::assertCount(0, $squehubApp->container()->make(RouteRegistry::class)->all());
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }

    public function testLegacyControllerLookupUsesOnlyEnabledCanonicalPackages(): void
    {
        $canonical = new TemporaryProject();
        $legacy = new TemporaryProject();

        try {
            // Each application has one physical Project root. Keeping the
            // legacy path in a separate app tests lookup without a case-only
            // collision that is invalid on a case-sensitive filesystem.
            $canonical->write('Project/Packages/PhaseFourPackage/PhaseFourPackage.php',
                '<?php namespace Packages\\PhaseFourPackage; final class PhaseFourPackage extends \\App\\Plugins\\ServiceProvider {}');
            $canonical->write('Project/Packages/LegacyCasePackage/LegacyCasePackage.php',
                '<?php namespace Packages\\LegacyCasePackage; final class LegacyCasePackage extends \\App\\Plugins\\ServiceProvider {}');
            $legacy->write('project/packages/LegacyCasePackage/readme.txt', 'legacy path');

            $app = new Application($canonical->path());
            $packages = new PackageManager($app);
            $packages->apply($packages->planEnable('PhaseFourPackage'));
            self::assertFalse($packages->isEnabled('LegacyCasePackage'));
            $controllers = new ControllerDispatcher($app->container(), $app);
            $registry = new RouteRegistry();

            $canonicalRoute = new RouteDefinition(
                $registry, ['GET'], '/canonical/{id}', 'PackageProbeController@show', [], true
            );
            $canonicalRequest = new Request('GET', '/canonical/7');
            $canonicalRequest->setAttribute('route.params', ['id' => '7']);
            self::assertSame('package:7', $controllers->dispatch($canonicalRoute, $canonicalRequest)->value);

            $legacyRoute = new RouteDefinition(
                $registry, ['GET'], '/legacy/{id}', 'LegacyPackageController@show', [], true
            );
            $legacyRequest = new Request('GET', '/legacy/8');
            $legacyRequest->setAttribute('route.params', ['id' => '8']);
            self::assertSame(
                "Controller 'LegacyPackageController' not found in any known namespaces.",
                $controllers->dispatch($legacyRoute, $legacyRequest)->value
            );
            $legacyApp = new Application($legacy->path());
            $legacyControllers = new ControllerDispatcher($legacyApp->container(), $legacyApp);
            self::assertSame(
                "Controller 'LegacyPackageController' not found in any known namespaces.",
                $legacyControllers->dispatch($legacyRoute, $legacyRequest)->value
            );
        } finally {
            $canonical->remove();
            $legacy->remove();
        }
    }

    private function assertPackageRouteLoads(string $file, string $uri): void
    {
        $project = new TemporaryProject();
        try {
            $project->write($file,
                "<?php \\App\\Routing\\Route::path('{$uri}')->get(static fn (): string => 'loaded');");
            $package = 'Canonical';
            $entryPath = dirname(dirname($file)) . '/' . $package . '.php';
            $project->write($entryPath,
                '<?php namespace Packages\\' . $package . '; final class ' . $package
                . ' extends \\App\\Plugins\\ServiceProvider {}');
            $squehubApp = new Application($project->path());
            $packages = new PackageManager($squehubApp);
            $packages->apply($packages->planEnable($package));
            $squehubApp->register(HttpServiceProvider::class);
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            $registry = $squehubApp->container()->make(RouteRegistry::class);
            self::assertCount(1, $registry->all());
            self::assertSame($uri,
                (new RouteMatcher())->match($registry, new Request('GET', $uri))->route->uri());
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }
}
