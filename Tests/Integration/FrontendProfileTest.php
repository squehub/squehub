<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Profiles\ProfileException;
use App\Profiles\ProfileManager;
use App\Profiles\ProfileTemplates;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\Request;
use App\Routing\Route;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\Csrf;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\Session;
use App\Session\SessionServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Real isolated profile publication exercises review, ownership and removal. */
final class FrontendProfileTest extends TestCase
{
    public function testFrontendCommandHelpDeclaresOperationalBoundaries(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['profile:inspect', 'profile:apply', 'profile:remove',
            'frontend:status', 'frontend:build'] as $name) {
            $help = new Process([PHP_BINARY, 'squehub', $name, '--help', '--no-ansi'], $root);
            $help->run();
            self::assertSame(0, $help->getExitCode(), $name . ': ' . $help->getErrorOutput());
            foreach (['Node required:', 'Writes files:', 'Starts processes:',
                'Development-only:'] as $boundary) {
                self::assertStringContainsString($boundary, $help->getOutput(), $name);
            }
        }
    }

    public function testEveryProfileIsReviewedAndCanBeRemovedWithoutNode(): void
    {
        foreach (ProfileManager::available() as $name) {
            $project = $this->project();
            try {
                $manager = new ProfileManager($project->path());
                $before = file_get_contents($project->path('Config/Frontend.php'));
                $plan = $manager->planInstall($name, true);
                self::assertSame('profile:apply', $plan->operation);
                self::assertFalse($plan->hasConflicts());
                self::assertSame($before, file_get_contents($project->path('Config/Frontend.php')));
                self::assertFileDoesNotExist($project->path('Project/Frontend/package.json'));
                self::assertTrue($manager->apply($plan)->complete());
                self::assertSame(['name' => $name, 'spa' => true, 'valid' => true],
                    $manager->inspect());
                self::assertFileExists($project->path('Project/Frontend/package.json'));
                self::assertFileDoesNotExist($project->path('Project/Frontend/node_modules'));
                $selected = file_get_contents($project->path('Config/Frontend.php'));
                self::assertStringContainsString("'adapter' => 'vite'", $selected);
                self::assertStringContainsString("'view' => 'Frontend.App'", $selected);
                self::assertStringContainsString("'prefix' => '/frontend'", $selected);
                $remove = $manager->planRemove($name);
                self::assertSame('destructive', $remove->risk());
                if ($name === 'vite') {
                    $project->write('Project/Frontend/package-lock.json', "{\"lockfileVersion\":3}\n");
                }
                self::assertTrue($manager->apply($remove)->complete());
                self::assertSame($before, file_get_contents($project->path('Config/Frontend.php')));
                self::assertFileDoesNotExist($project->path('Project/Frontend/package.json'));
                if ($name === 'vite') {
                    self::assertFileExists($project->path('Project/Frontend/package-lock.json'));
                }
                self::assertSame(['name' => null, 'spa' => false, 'valid' => true],
                    $manager->inspect());
            } finally { $project->remove(); }
        }
    }

    public function testExistingAndModifiedFilesBlockPublicationAndRemoval(): void
    {
        $project = $this->project();
        try {
            $manager = new ProfileManager($project->path());
            $project->write('Project/Frontend/package.json', "owned by developer\n");
            $conflicted = $manager->planInstall('react');
            self::assertTrue($conflicted->hasConflicts());
            $this->expectException(ProfileException::class);
            $manager->apply($conflicted);
        } finally { $project->remove(); }
    }

    public function testStalePlanAndEditedOwnedFileAreProtected(): void
    {
        $project = $this->project();
        try {
            $manager = new ProfileManager($project->path());
            $plan = $manager->planInstall('vue');
            $project->write('Config/Frontend.php',
                file_get_contents($project->path('Config/Frontend.php')) . "\n// changed\n");
            try {
                $manager->apply($plan);
                self::fail('A stale plan was accepted.');
            } catch (ProfileException) {
                self::assertFileDoesNotExist($project->path('Project/Frontend/Profile.json'));
            }
            $project->write('Config/Frontend.php',
                file_get_contents(dirname(__DIR__, 2) . '/Config/Frontend.php'));
            self::assertTrue($manager->apply($manager->planInstall('vue'))->complete());
            $project->write('Project/Frontend/Src/App.vue', '<template>changed</template>');
            $remove = $manager->planRemove('vue');
            self::assertTrue($remove->hasConflicts());
            self::assertFalse($manager->inspect()['valid']);
            self::assertFileExists($project->path('Project/Frontend/Src/App.vue'));
        } finally { $project->remove(); }
    }

    public function testGeneratedSourcesAreSmallAndUseBackendShell(): void
    {
        foreach (ProfileManager::available() as $name) {
            $files = ProfileTemplates::files($name);
            self::assertStringContainsString("@frontend('app')",
                $files['Project/Views/Frontend/App.squehub.php']);
            self::assertStringContainsString('csrf-token',
                $files['Project/Views/Frontend/App.squehub.php']);
            self::assertStringContainsString('headers.set(csrfHeader, token)',
                $files['Project/Frontend/Src/Api.js']);
            self::assertStringContainsString("View::response('Frontend.App'",
                $files['Project/Routes/Frontend.php']);
            self::assertStringNotContainsString('index.html',
                $files['Project/Frontend/vite.config.mjs']);
            self::assertLessThan(10000, strlen($files['Project/Frontend/vite.config.mjs']));
        }
    }

    public function testCanonicalSourceCasingIsRequiredBeforeAnyWrite(): void
    {
        $project = $this->project();
        try {
            $project->write('Project/frontend/existing.txt', 'preserved');
            $manager = new ProfileManager($project->path());
            $this->expectException(ProfileException::class);
            $manager->planInstall('vite');
        } finally { $project->remove(); }
    }

    public function testCliPreviewApplyAndRemovalUseTheSameReviewedProfile(): void
    {
        $project = $this->project();
        try {
            $root = dirname(__DIR__, 2);
            $project->write('CliRunner.php', '<?php declare(strict_types=1); require '
                . var_export($root . '/vendor/autoload.php', true) . '; '
                . '$squehubApp=new \\App\\Foundation\\Application(__DIR__); '
                . '$squehubApp->bootstrap(); require '
                . var_export($root . '/App/Clis/Clis.php', true) . ';');
            $run = static function (array $args) use ($project): Process {
                $process = new Process([PHP_BINARY, $project->path('CliRunner.php'),
                    ...$args, '--no-ansi'], $project->path());
                $process->run();
                return $process;
            };
            $preview = $run(['profile:apply', 'react', '--spa', '--preview']);
            self::assertSame(0, $preview->getExitCode(), $preview->getErrorOutput());
            self::assertStringContainsString('CREATE', $preview->getOutput());
            self::assertStringContainsString('Config/Frontend.php', $preview->getOutput());
            self::assertFileDoesNotExist($project->path('Project/Frontend/Profile.json'));
            $apply = $run(['profile:apply', 'react', '--spa', '--yes']);
            self::assertSame(0, $apply->getExitCode(), $apply->getErrorOutput() . $apply->getOutput());
            self::assertFileExists($project->path('Project/Frontend/Profile.json'));
            $inspect = $run(['profile:inspect', '--json']);
            self::assertSame(0, $inspect->getExitCode());
            self::assertSame('react', json_decode($inspect->getOutput(), true)['name']);
            $remove = $run(['profile:remove', 'react', '--yes']);
            self::assertSame(0, $remove->getExitCode(), $remove->getErrorOutput() . $remove->getOutput());
            self::assertFileDoesNotExist($project->path('Project/Frontend/Profile.json'));
        } finally { $project->remove(); }
    }

    public function testGeneratedRouteRendersMountAwareCsrfShellAndScopedSpa(): void
    {
        $project = $this->project();
        try {
            $manager = new ProfileManager($project->path());
            self::assertTrue($manager->apply($manager->planInstall('vite', true))->complete());
            $project->write('Config/App.php', '<?php return ["env"=>"development","debug"=>false];');
            $project->write('Config/Http.php', '<?php return ["base_path"=>"/app"];');
            $project->write('Config/Session.php', '<?php return ["driver"=>"array"];');
            $project->write('Config/Csrf.php',
                '<?php return ["enabled"=>true,"field"=>"_csrf","header"=>"X-SqueHub-CSRF","except"=>[]];');
            $app = new Application($project->path());
            foreach ([SessionServiceProvider::class, CsrfServiceProvider::class,
                HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
                $app->register($provider);
            }
            $app->bootstrap();
            require $project->path('Project/Routes/Frontend.php');
            Route::path('/api/form')->post(static fn (): array => ['saved' => true]);
            $kernel = $app->container()->make(Kernel::class);
            $response = $kernel->handle(new Request('GET', '/app/frontend',
                headers: ['Accept' => 'text/html']));
            self::assertSame(200, $response->status(), $response->content());
            self::assertSame('private, no-store', $response->header('Cache-Control'));
            self::assertMatchesRegularExpression('/name="csrf-token" content="[a-f0-9]{64}"/',
                $response->content());
            self::assertStringContainsString('name="squehub-csrf-header" content="X-SqueHub-CSRF"',
                $response->content());
            self::assertStringContainsString('name="squehub-base-path" content="/app/"',
                $response->content());
            self::assertStringContainsString('http://127.0.0.1:5173/Src/Main.js',
                $response->content());
            $nested = $kernel->handle(new Request('GET', '/app/frontend/dashboard',
                headers: ['Accept' => 'text/html']));
            self::assertSame(200, $nested->status(), $nested->content());
            self::assertSame(404, $kernel->handle(new Request('GET', '/app/api/missing',
                headers: ['Accept' => 'text/html']))->status());
            preg_match('/name="csrf-token" content="([a-f0-9]{64})"/',
                $response->content(), $match);
            self::assertSame(403, $kernel->handle(new Request('POST', '/app/api/form',
                headers: ['Accept' => 'application/json', 'X-CSRF-Token' => $match[1]]))->status());
            self::assertSame(200, $kernel->handle(new Request('POST', '/app/api/form',
                headers: ['Accept' => 'application/json',
                    'X-SqueHub-CSRF' => $match[1]]))->status());
        } finally {
            Route::setResolver(null);
            Session::setResolver(null);
            Csrf::setResolver(null);
            $project->remove();
        }
    }

    private function project(): TemporaryProject
    {
        $project = new TemporaryProject();
        // TemporaryProject ships a lowercase config directory for older tests;
        // profile paths deliberately enforce canonical Config casing.
        rename($project->path('config'), $project->path('config-rename'));
        rename($project->path('config-rename'), $project->path('Config'));
        $project->write('Config/Frontend.php',
            file_get_contents(dirname(__DIR__, 2) . '/Config/Frontend.php'));
        return $project;
    }
}
