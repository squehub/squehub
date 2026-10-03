<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Agent\AgentException;
use App\Agent\AgentManager;
use App\Testing\TestApplication;
use PHPUnit\Framework\TestCase;

/** Agent plans are review artifacts derived from subsystem planners, never execution permits. */
final class AgentPlanTest extends TestCase
{
    public function testMigrationSourcePlanIsStableAndChangesWithSourceWithoutExecutingPhp(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => [
            'create_plan' => ['operations' => ['migration_source']],
        ]]]);
        try {
            $marker = $project->path('migration-executed.txt');
            $source = 'Database/Migrations/2026_10_01_create_accounts.php';
            $firstBytes = '<?php file_put_contents(' . var_export($marker, true)
                . ', "executed"); class CreateAccounts {}';
            $project->write($source, $firstBytes);
            $agent = new AgentManager($project->application());
            $first = $agent->tool('create_plan', ['operation' => 'migration_source']);
            $again = $agent->tool('create_plan', ['operation' => 'migration_source']);
            self::assertSame($first['fingerprint'], $again['fingerprint']);
            self::assertSame('migrate:plan', $first['plan']['operation']);
            self::assertSame('agent', $first['plan']['metadata']['source']);
            self::assertSame('agent', $first['provenance']['source']);
            self::assertSame('2.0.0-dev', $first['provenance']['framework_version']);
            self::assertFalse($first['applied']);
            self::assertFalse($first['apply_supported']);
            self::assertSame($source, $first['plan']['actions'][0]['subject']);
            self::assertFileDoesNotExist($marker);
            self::assertStringNotContainsString($project->root(),
                json_encode($first, JSON_THROW_ON_ERROR));
            $project->write($source, $firstBytes . ' // changed');
            $changed = $agent->tool('create_plan', ['operation' => 'migration_source']);
            self::assertNotSame($first['fingerprint'], $changed['fingerprint']);
            self::assertFileDoesNotExist($marker);
        } finally {
            $project->cleanup();
        }
    }

    public function testFeatureBlueprintPlanCreatesNoFilesAndNeverExposesApply(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => [
            'create_plan' => ['operations' => ['feature_blueprint']],
        ]]]);
        try {
            $agent = new AgentManager($project->application());
            $plan = $agent->tool('create_plan', ['operation' => 'feature_blueprint', 'target' => 'Post']);
            self::assertSame('make:feature', $plan['plan']['operation']);
            self::assertSame('Post', $plan['plan']['target']);
            self::assertNotEmpty($plan['plan']['actions']);
            self::assertSame('agent', $plan['plan']['metadata']['source']);
            self::assertFalse($plan['applied']);
            self::assertFalse($plan['apply_supported']);
            self::assertFileDoesNotExist($project->path('Project/Models/Post.php'));
            self::assertFileDoesNotExist($project->path('Project/Routes/Features/Post.php'));
            self::assertFileDoesNotExist($project->path('Tests/Integration/PostFeatureTest.php'));
            try {
                $agent->tool('apply_plan', ['fingerprint' => $plan['fingerprint']]);
                self::fail('An Agent plan cannot be applied through MCP.');
            } catch (AgentException) {
                self::assertTrue(true);
            }
        } finally {
            $project->cleanup();
        }
    }

    public function testPlanGrantIsOperationSpecificAndMalformedTargetsFailSafely(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => [
            'create_plan' => ['operations' => ['migration_source', 'package_enable', 'kit_enable', 'feature_blueprint']],
        ]]]);
        try {
            $agent = new AgentManager($project->application());
            foreach ([
                ['operation' => 'migration_source', 'target' => 'anything'],
                ['operation' => 'feature_blueprint', 'target' => '../.env'],
                ['operation' => 'package_enable', 'target' => '../Secret'],
                ['operation' => 'kit_enable', 'target' => 'Missing/Kit'],
                ['operation' => 'shell', 'target' => 'echo secret'],
            ] as $arguments) {
                try {
                    $agent->tool('create_plan', $arguments);
                    self::fail('Invalid plan operation or target must be rejected.');
                } catch (AgentException $exception) {
                    self::assertStringNotContainsString($project->root(), $exception->getMessage());
                    self::assertStringNotContainsString('.env', $exception->getMessage());
                }
            }
            self::assertFileDoesNotExist($project->path('Project/Models/Secret.php'));
        } finally {
            $project->cleanup();
        }
    }

    public function testOperationNotGrantedCannotBePlannedAndToolArgumentSurfaceIsClosed(): void
    {
        $project = TestApplication::temporary(['agent' => ['grants' => [
            'create_plan' => ['operations' => ['migration_source']],
        ]]]);
        try {
            $agent = new AgentManager($project->application());
            foreach ([
                ['operation' => 'feature_blueprint', 'target' => 'Post'],
                ['operation' => 'migration_source', 'command' => 'whoami'],
                ['operation' => 'migration_source', 'target' => "\n"],
            ] as $arguments) {
                try {
                    $agent->tool('create_plan', $arguments);
                    self::fail('An ungranted operation or extra argument must be rejected.');
                } catch (AgentException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $project->cleanup();
        }
    }
}
