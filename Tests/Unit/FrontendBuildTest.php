<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Application;
use App\Frontend\Build\FrontendBuild;
use App\Frontend\Build\FrontendBuildException;
use App\Health\CoreHealthChecks;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Optional build tooling is absent from the PHP-only request path. */
final class FrontendBuildTest extends TestCase
{
    public function testUnselectedApplicationNeedsNoNodeOrBuildOutput(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            self::assertSame([
                'adapter' => 'none', 'configured' => false, 'node_installed' => false,
                'vite_installed' => false, 'dev_reachable' => null, 'build_available' => false,
            ], (new FrontendBuild($app))->status());
            self::assertSame('skipped', (new CoreHealthChecks($app))->frontend()->status());
            self::assertFileDoesNotExist($project->path('Project/Frontend/package.json'));
        } finally { $project->remove(); }
    }

    public function testSelectedAdapterReportsMissingLocalPackagesWithoutStartingNode(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Frontend/package.json', "{}\n");
            $app = new Application($project->path());
            $app->config()->set('frontend', [
                'adapter' => 'vite', 'source' => 'Project/Frontend',
                'entries' => ['app' => 'Src/Main.js'],
                'build' => ['directory' => 'public/assets/build',
                    'manifest' => '.vite/manifest.json'],
                'development' => ['enabled' => true, 'url' => 'http://127.0.0.1:5173'],
            ]);
            $build = new FrontendBuild($app);
            $status = $build->status();
            self::assertTrue($status['configured']);
            self::assertFalse($status['vite_installed']);
            self::assertFalse($status['build_available']);
            self::assertNull($status['dev_reachable']);
            self::assertSame('warning', (new CoreHealthChecks($app))->frontend()->status());
            self::assertFileDoesNotExist($project->path('Project/Frontend/node_modules'));
            try {
                $build->build();
                self::fail('Build should require a selected local Vite installation.');
            } catch (FrontendBuildException $exception) {
                self::assertStringContainsString('npm install', $exception->getMessage());
            }
            $this->expectException(FrontendBuildException::class);
            $build->developmentPort('invalid');
        } finally { $project->remove(); }
    }

    public function testProductionDoctorRequiresBuildFilesButNotLocalNodePackages(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            $app->config()->set('app.env', 'production');
            $app->config()->set('frontend', [
                'adapter' => 'vite', 'source' => 'Project/Frontend',
                'entries' => ['app' => 'Src/Main.js'],
                'build' => ['directory' => 'public/assets/build',
                    'manifest' => '.vite/manifest.json'],
                'development' => ['enabled' => true,
                    'url' => 'http://127.0.0.1:5173'],
            ]);
            self::assertSame('fail', (new CoreHealthChecks($app))->frontend()->status());
            $project->write('public/assets/build/assets/Main-abc.js', 'export {};');
            $project->write('public/assets/build/.vite/manifest.json',
                '{"Src/Main.js":{"file":"assets/Main-abc.js","isEntry":true}}');
            $status = (new FrontendBuild($app))->status();
            self::assertTrue($status['build_available']);
            self::assertFalse($status['vite_installed']);
            self::assertSame('pass', (new CoreHealthChecks($app))->frontend()->status());
        } finally { $project->remove(); }
    }
}
