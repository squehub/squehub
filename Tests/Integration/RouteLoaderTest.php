<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Http\Request;
use App\Http\HttpServiceProvider;
use App\Packages\PackageManager;
use App\Routing\Route;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;
use App\Routing\RouteDispatcher;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class RouteLoaderTest extends TestCase
{
    public function testGlobalShortcutsWorkInRouteFilesWithoutImports(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Routes/Web.php',
                "<?php Route::path('/welcome')->get(static fn (Request \$request): Response => new Response('Welcome'));"
            );

            $squehubApp = new Application($project->path());
            $squehubApp->register(HttpServiceProvider::class);
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            $result = $squehubApp->container()->make(RouteDispatcher::class)
                ->dispatch(new Request('GET', '/welcome'));
            self::assertSame('Welcome', $result->value->content());
            self::assertTrue(class_exists('View', false));
            self::assertTrue(is_a('View', \App\Core\View::class, true));
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }

    public function testProjectAndPackageRoutesLoadWhileAppRouteFilesAreIgnored(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Routes/Web.php', "<?php \$router->add('GET', '/legacy', static fn () => 'old', 'legacy');");
            $project->write('Project/Routes/ZModern.php',
                "<?php use App\\Core\\Route; Route::path('/modern')->get(static fn () => 'new')->named('modern');");
            $project->write('App/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/ignored-app')->get(static fn () => 'ignored')->named('ignored.app');");
            $project->write('app/routes/Lower.php',
                "<?php \\App\\Routing\\Route::path('/ignored-lower')->get(static fn () => 'ignored')->named('ignored.lower');");
            $project->write('Project/Packages/Example/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/package')->get(static fn () => 'package')->named('package');");
            $project->write('Project/Packages/Example/Example.php',
                '<?php namespace Packages\\Example; final class Example extends \\App\\Plugins\\ServiceProvider {}');

            $squehubApp = new Application($project->path());
            $packages = new PackageManager($squehubApp);
            $packages->apply($packages->planEnable('Example'));
            $squehubApp->register(HttpServiceProvider::class);
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            $registry = $squehubApp->container()->make(RouteRegistry::class);
            self::assertSame(['legacy', 'modern', 'package'],
                array_map(static fn ($route): ?string => $route->nameValue(), $registry->all()));
            self::assertFalse($registry->hasName('ignored.app'));
            self::assertFalse($registry->hasName('ignored.lower'));
            $matcher = new RouteMatcher();
            foreach (['/legacy', '/modern', '/package'] as $path) {
                self::assertSame($path, $matcher->match($registry, new Request('GET', $path))->route->uri());
            }
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }

    public function testLowercaseRouteDirectoryAndFileRemainReadable(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('project/routes/web.php',
                "<?php \\App\\Routing\\Route::path('/lowercase')->get(static fn () => 'ok')->named('lowercase');");

            $squehubApp = new Application($project->path());
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            $registry = $squehubApp->container()->make(RouteRegistry::class);
            self::assertSame('/lowercase', $registry->url('lowercase'));
            self::assertCount(1, $registry->all());
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }

    public function testManuallyImportedDisabledPackageRouteIsAbsent(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Packages/Unapproved/Unapproved.php',
                '<?php namespace Packages\\Unapproved; final class Unapproved extends \\App\\Plugins\\ServiceProvider {}');
            $project->write('Project/Packages/Unapproved/Routes/Web.php',
                "<?php \\App\\Routing\\Route::path('/unapproved')->get(static fn () => 'unsafe');");
            $squehubApp = new Application($project->path());
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';

            self::assertCount(0, $squehubApp->container()->make(RouteRegistry::class)->all());
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }

    public function testEnabledPackagesCannotSilentlyReplaceTheSameRoute(): void
    {
        $project = new TemporaryProject();
        try {
            foreach (['CollisionOne', 'CollisionTwo'] as $name) {
                $project->write("Project/Packages/{$name}/{$name}.php",
                    '<?php namespace Packages\\' . $name . '; final class ' . $name
                    . ' extends \\App\\Plugins\\ServiceProvider {}');
                $project->write("Project/Packages/{$name}/Routes/Web.php",
                    "<?php \\App\\Routing\\Route::path('/collision')->get(static fn () => '{$name}');");
            }
            $squehubApp = new Application($project->path());
            $packages = new PackageManager($squehubApp);
            $packages->apply($packages->planEnable('CollisionOne'));
            $packages->apply($packages->planEnable('CollisionTwo'));
            $squehubApp->register(RoutingServiceProvider::class);
            $squehubApp->bootstrap();

            $this->expectException(\LogicException::class);
            require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';
        } finally {
            Route::setResolver(null);
            $project->remove();
        }
    }
}
