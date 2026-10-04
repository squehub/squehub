<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Config\ConfigCache;
use App\Config\ConfigurationException;
use App\Config\Repository;
use App\Foundation\Application;
use App\Foundation\Environment;
use App\Packages\PackageManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Config artifacts are private source-derived data, never Package snapshots. */
final class ConfigCacheIntegrationTest extends TestCase
{
    public function testCacheHitSkipsConfigExecutionAndStatusHasNoValues(): void
    {
        $project = new TemporaryProject();
        try {
            $marker = $project->path('executions.txt');
            $project->write('Config/App.php', '<?php file_put_contents(' . var_export($marker, true)
                . ', "x", FILE_APPEND); return ["name" => "private-config-secret"];');
            $cache = new ConfigCache($project->path());
            $built = $cache->build($project->path('Config'), new Environment($project->path()));
            self::assertSame(1, $built['files']);
            self::assertSame('x', file_get_contents($marker));

            $app = new Application($project->path());
            $app->bootstrap();
            self::assertSame('private-config-secret', $app->config()->get('app.name'));
            self::assertSame('x', file_get_contents($marker));
            $status = $cache->status($project->path('Config'), new Environment($project->path()));
            self::assertSame('current', $status['status']);
            self::assertTrue($status['active']);
            self::assertStringNotContainsString('private-config-secret', json_encode($status));
            self::assertFileExists($project->path('Storage/Cache/Framework/Config.json'));
        } finally {
            $project->remove();
        }
    }

    public function testNormalBootstrapWithoutCacheDoesNotCreateFrameworkStorage(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "ordinary"];');
            self::assertSame('ordinary', $this->boot($project)->config()->get('app.name'));
            self::assertDirectoryDoesNotExist($project->path('Storage/Cache/Framework'));
        } finally {
            $project->remove();
        }
    }

    public function testSourceAndDotenvChangesInvalidateWithoutManualClear(): void
    {
        $project = new TemporaryProject();
        $key = 'SQUEHUB_CACHE_DOTENV_' . strtoupper(bin2hex(random_bytes(4)));
        try {
            $project->write('.env', "$key=first\n");
            $project->write('Config/App.php', '<?php return ["name" => $environment->get('
                . var_export($key, true) . ')];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            self::assertSame('first', $this->boot($project)->config()->get('app.name'));
            $project->write('.env', "$key=second\n");
            self::assertSame('stale', $cache->status($project->path('Config'),
                new Environment($project->path()))['status']);
            self::assertSame('second', $this->boot($project)->config()->get('app.name'));

            $project->write('Config/App.php', '<?php return ["name" => "source-replaced"];');
            self::assertSame('source-replaced', $this->boot($project)->config()->get('app.name'));
        } finally {
            $project->remove();
        }
    }

    public function testAddedConfigFileInvalidatesTheExactSourceManifest(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "base"];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            $project->write('Config/Feature.php', '<?php return ["enabled" => true];');
            self::assertSame('stale', $cache->status($project->path('Config'),
                new Environment($project->path()))['status']);
            self::assertTrue($this->boot($project)->config()->get('feature.enabled'));
        } finally {
            $project->remove();
        }
    }

    public function testExternalEnvironmentOverrideAndAppEnvironmentInvalidate(): void
    {
        $project = new TemporaryProject();
        $key = 'SQUEHUB_CACHE_ENV_' . strtoupper(bin2hex(random_bytes(4)));
        $previousKey = getenv($key);
        $previousApp = getenv('APP_ENV');
        $previousAppArray = [array_key_exists('APP_ENV', $_ENV), $_ENV['APP_ENV'] ?? null];
        try {
            unset($_ENV['APP_ENV']);
            $project->write('Config/App.php', '<?php return ["name" => $environment->get('
                . var_export($key, true) . '), "env" => $environment->get("APP_ENV")];');
            putenv($key . '=shell-one');
            putenv('APP_ENV=development');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            self::assertSame('shell-one', $this->boot($project)->config()->get('app.name'));
            putenv($key . '=shell-two');
            self::assertSame('shell-two', $this->boot($project)->config()->get('app.name'));
            putenv('APP_ENV=production');
            self::assertSame('production', $this->boot($project)->config()->get('app.env'));
        } finally {
            $previousKey === false ? putenv($key) : putenv($key . '=' . $previousKey);
            $previousApp === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousApp);
            if ($previousAppArray[0]) {
                $_ENV['APP_ENV'] = $previousAppArray[1];
            } else {
                unset($_ENV['APP_ENV']);
            }
            $project->remove();
        }
    }

    public function testCorruptArtifactFailsSafelyAndClearAllowsNormalBootstrap(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "current"];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            $project->write('Storage/Cache/Framework/Config.json', '{invalid json with private-secret');
            self::assertSame('corrupt', $cache->status($project->path('Config'),
                new Environment($project->path()))['status']);
            try {
                $this->boot($project);
                self::fail('Corruption must not be treated as a fresh cache miss.');
            } catch (ConfigurationException $exception) {
                self::assertStringNotContainsString('private-secret', $exception->getMessage());
            }
            self::assertTrue($cache->clear());
            self::assertFalse($cache->clear());
            self::assertSame('current', $this->boot($project)->config()->get('app.name'));
        } finally {
            $project->remove();
        }
    }

    public function testFailedRebuildLeavesExistingArtifactIntactAndNormalLoaderAvailable(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "cached"];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            $path = $project->path('Storage/Cache/Framework/Config.json');
            $before = file_get_contents($path);
            $project->write('Config/App.php', '<?php return ["callback" => static fn () => true];');
            $this->expectException(ConfigurationException::class);
            try {
                $cache->build($project->path('Config'), new Environment($project->path()));
            } finally {
                self::assertSame($before, file_get_contents($path));
                $app = $this->boot($project);
                self::assertInstanceOf(\Closure::class, $app->config()->get('app.callback'));
            }
        } finally {
            $project->remove();
        }
    }

    public function testOnlyBaseConfigIsCachedAndPackageDefaultsStayLive(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "base"];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            $project->write('Project/Packages/CacheProbe/CacheProbe.php',
                '<?php namespace Packages\\CacheProbe; final class CacheProbe extends \\App\\Plugins\\ServiceProvider {'
                . ' public function register(): void { $this->app->config()->set("packages.CacheProbe.live", true); } }');
            $manager = new PackageManager(new Application($project->path()));
            $manager->apply($manager->planEnable('CacheProbe'));

            $app = $this->boot($project);
            self::assertSame('base', $app->config()->get('app.name'));
            self::assertTrue($app->config()->get('packages.CacheProbe.live'));
            self::assertStringNotContainsString('CacheProbe',
                (string) file_get_contents($project->path('Storage/Cache/Framework/Config.json')));
        } finally {
            $project->remove();
        }
    }

    public function testSeparateApplicationsNeverShareCacheValues(): void
    {
        $one = new TemporaryProject();
        $two = new TemporaryProject();
        try {
            $one->write('Config/App.php', '<?php return ["name" => "one"];');
            $two->write('Config/App.php', '<?php return ["name" => "two"];');
            (new ConfigCache($one->path()))->build($one->path('Config'), new Environment($one->path()));
            (new ConfigCache($two->path()))->build($two->path('Config'), new Environment($two->path()));
            self::assertSame('one', $this->boot($one)->config()->get('app.name'));
            self::assertSame('two', $this->boot($two)->config()->get('app.name'));
        } finally {
            $one->remove();
            $two->remove();
        }
    }

    public function testDynamicEnvironmentKeyCannotBeCachedButNormalLoadingWorks(): void
    {
        $project = new TemporaryProject();
        $key = 'SQUEHUB_CACHE_DYNAMIC_' . strtoupper(bin2hex(random_bytes(4)));
        try {
            $project->write('Config/App.php', '<?php return ["name" => "before"];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            $project->write('Config/App.php', '<?php $key = ' . var_export($key, true)
                . '; return ["name" => $environment->get($key, "ok")];');
            try {
                $cache->build($project->path('Config'), new Environment($project->path()));
                self::fail('Dynamic environment keys cannot be tracked.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('literal environment keys', $exception->getMessage());
            }
            self::assertSame('unsupported', $cache->status($project->path('Config'),
                new Environment($project->path()))['status']);
            self::assertSame('ok', $this->boot($project)->config()->get('app.name'));
        } finally {
            $project->remove();
        }
    }

    public function testConfigCacheRejectsSymlinkArtifactWithoutFollowingIt(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "safe"];');
            $cache = new ConfigCache($project->path());
            $cache->build($project->path('Config'), new Environment($project->path()));
            $path = $project->path('Storage/Cache/Framework/Config.json');
            $outside = $project->path('outside.txt');
            $project->write('outside.txt', 'outside-secret');
            unlink($path);
            if (!@symlink($outside, $path)) {
                self::markTestSkipped('File symlinks are not available in this environment.');
            }
            try {
                $this->boot($project);
                self::fail('Linked cache artifacts must be rejected.');
            } catch (ConfigurationException $exception) {
                self::assertStringNotContainsString('outside-secret', $exception->getMessage());
                self::assertSame('outside-secret', file_get_contents($outside));
            }
        } finally {
            $project->remove();
        }
    }

    private function boot(TemporaryProject $project): Application
    {
        $app = new Application($project->path());
        $app->bootstrap();
        return $app;
    }
}
