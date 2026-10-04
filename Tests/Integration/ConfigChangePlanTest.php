<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Changes\ChangePlan;
use App\Changes\ChangeRenderer;
use App\Config\ConfigCache;
use App\Config\ConfigurationException;
use App\Foundation\Environment;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** A config cache publication plan is read-only and tied to its source state. */
final class ConfigChangePlanTest extends TestCase
{
    public function testPreviewDoesNotEvaluateConfigurationOrCreatePrivateStorage(): void
    {
        $project = new TemporaryProject();
        try {
            $marker = $project->path('executed.txt');
            $project->write('Config/App.php', '<?php file_put_contents('
                . var_export($marker, true) . ', "ran"); return ["name" => "SQUEHUB_PRIVATE_VALUE"];');
            $cache = new ConfigCache($project->path());
            $plan = $cache->planBuild($project->path('Config'), new Environment($project->path()));

            self::assertInstanceOf(ChangePlan::class, $plan);
            self::assertSame('config:cache', $plan->operation);
            self::assertSame('configuration', $plan->metadata?->source);
            self::assertSame('configuration', $plan->actions[0]->category);
            self::assertSame('create', $plan->actions[0]->kind);
            self::assertFileDoesNotExist($marker);
            self::assertDirectoryDoesNotExist($project->path('Storage/Cache/Framework'));
            self::assertStringNotContainsString('SQUEHUB_PRIVATE_VALUE',
                json_encode($plan->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringContainsString('CREATE', ChangeRenderer::render($plan));
        } finally {
            $project->remove();
        }
    }

    public function testApplyBuildRejectsChangedSourceAndChangedArtifactBeforePublishing(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "one"];');
            $cache = new ConfigCache($project->path());
            $environment = new Environment($project->path());
            $plan = $cache->planBuild($project->path('Config'), $environment);
            $project->write('Config/App.php', '<?php return ["name" => "two"];');
            try {
                $cache->applyBuild($plan, $project->path('Config'), $environment);
                self::fail('Changed source must invalidate the plan.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertDirectoryDoesNotExist($project->path('Storage/Cache/Framework'));

            $plan = $cache->planBuild($project->path('Config'), $environment);
            $otherCache = new ConfigCache($project->path());
            $otherCache->build($project->path('Config'), $environment);
            $artifact = $project->path('Storage/Cache/Framework/Config.json');
            $before = file_get_contents($artifact);
            try {
                $cache->applyBuild($plan, $project->path('Config'), $environment);
                self::fail('A concurrently published artifact must invalidate the plan.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame($before, file_get_contents($artifact));
        } finally {
            $project->remove();
        }
    }

    public function testSuccessfulApplyReportsVerifiedSharedChangeResult(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "private-config"];');
            $cache = new ConfigCache($project->path());
            $environment = new Environment($project->path());
            $plan = $cache->planBuild($project->path('Config'), $environment);
            $report = $cache->applyBuild($plan, $project->path('Config'), $environment);

            self::assertSame(1, $report['files']);
            self::assertTrue($report['change']->complete());
            self::assertSame($plan->fingerprint(), $report['change']->toArray()['plan_sha256']);
            self::assertSame('current', $cache->status($project->path('Config'), $environment)['status']);
            self::assertFileExists($project->path('Storage/Cache/Framework/Config.json'));
            try {
                $cache->applyBuild($plan, $project->path('Config'), $environment);
                self::fail('An already-applied plan must not overwrite the new artifact.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
        } finally {
            $project->remove();
        }
    }

    public function testAnotherCacheInstanceCannotApplyAReviewedPlan(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "private-config"];');
            $environment = new Environment($project->path());
            $plan = (new ConfigCache($project->path()))->planBuild(
                $project->path('Config'), $environment);
            $other = new ConfigCache($project->path());
            $this->expectException(ConfigurationException::class);
            try {
                $other->applyBuild($plan, $project->path('Config'), $environment);
            } finally {
                self::assertDirectoryDoesNotExist($project->path('Storage/Cache/Framework'));
            }
        } finally {
            $project->remove();
        }
    }

    public function testArtifactChangedDuringConfigEvaluationFailsAtLockedPublishBoundary(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', '<?php return ["name" => "original"];');
            $cache = new ConfigCache($project->path());
            $environment = new Environment($project->path());
            $cache->build($project->path('Config'), $environment);
            $artifact = $project->path('Storage/Cache/Framework/Config.json');
            $project->write('Config/App.php', '<?php file_put_contents('
                . var_export($artifact, true)
                . ', "changed during evaluation"); return ["name" => "replacement"];');
            $plan = $cache->planBuild($project->path('Config'), $environment);

            try {
                $cache->applyBuild($plan, $project->path('Config'), $environment);
                self::fail('A changed artifact must not be overwritten after review.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('stale', $exception->getMessage());
            }
            self::assertSame('changed during evaluation', file_get_contents($artifact));
        } finally {
            $project->remove();
        }
    }
}
