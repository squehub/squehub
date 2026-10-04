<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Bundles\ProjectBundle;
use App\Changes\ChangeAction;
use App\Changes\ChangePlan;
use App\Changes\ChangeRenderer;
use App\Upgrades\UpgradePreflight;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real bundle and upgrade inspectors publish one review schema without applying it. */
final class UnifiedChangePlanTest extends TestCase
{
    public function testBundleAndUpgradePlansShareBoundedReviewMetadata(): void
    {
        $source = new TemporaryProject();
        $target = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            self::assertTrue(rmdir($source->path('config')));
            self::assertTrue(rmdir($target->path('config')));
            $composer = json_encode(['name' => 'squehub/unified-review-test',
                'require' => ['php' => '^8.2']], JSON_THROW_ON_ERROR);
            $source->write('composer.json', $composer);
            $target->write('composer.json', $composer);
            $source->write('Project/Routes/Web.php', '<?php // Reviewed application source.');
            $target->write('Project/Routes/Web.php', '<?php // Reviewed application source.');

            $archive = $holder->path('project.sqhb');
            $bundle = new ProjectBundle($source->path());
            $bundle->export($archive);
            $bundleTarget = $holder->path('Restored');
            $bundlePlan = $bundle->planImport($archive, $bundleTarget);
            $upgradePlan = (new UpgradePreflight())->inspect($source->path(), $target->path())->plan();

            foreach ([$bundlePlan, $upgradePlan] as $plan) {
                self::assertInstanceOf(ChangePlan::class, $plan);
                self::assertArrayHasKey('metadata', $plan->toArray());
                self::assertSame(['source', 'source_sha256', 'security', 'compatibility',
                    'verification'], array_keys($plan->toArray()['metadata']));
                self::assertStringContainsString('Expected verification:', ChangeRenderer::render($plan));
            }
            self::assertSame('bundle', $bundlePlan->metadata?->source);
            self::assertSame(hash_file('sha256', $archive),
                $bundlePlan->metadata->sourceFingerprint);
            self::assertSame('upgrade', $upgradePlan->metadata?->source);
            self::assertContains('file', array_map(static fn (ChangeAction $action): ?string =>
                $action->category, $bundlePlan->actions));
            self::assertContains('composer', array_map(static fn (ChangeAction $action): ?string =>
                $action->category, $bundlePlan->actions));
            self::assertFileDoesNotExist($bundleTarget);
            self::assertFileDoesNotExist($source->path('Project/Activation.json'));
        } finally {
            $holder->remove();
            $target->remove();
            $source->remove();
        }
    }
}
