<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Changes\ChangePlan;
use App\Changes\ChangeRenderer;
use App\Upgrades\UpgradePreflight;
use App\Upgrades\UpgradeException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Static upgrade inspection must never execute source or expose source bytes. */
final class UpgradePreflightTest extends TestCase
{
    /** @var list<TemporaryProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) $project->remove();
        $this->projects = [];
    }

    private function source(): TemporaryProject
    {
        $project = new TemporaryProject();
        $this->projects[] = $project;
        // The old fixture creates lowercase config/ for legacy tests. Upgrade
        // inputs use the canonical Config/ spelling on every filesystem.
        self::assertTrue(rmdir($project->path('config')));
        $project->write('composer.json', $this->composer());
        $project->write('composer.lock', '{"packages":[]}');
        return $project;
    }

    /** @param array<string,string> $require */
    private function composer(array $require = ['php' => '^8.2']): string
    {
        return json_encode(['name' => 'example/application', 'require' => $require], JSON_THROW_ON_ERROR);
    }

    private function hasCode(array $findings, string $code): bool
    {
        return in_array($code, array_column($findings, 'code'), true);
    }

    public function testMatchingLocalSourceIsCompatibleWithoutWriting(): void
    {
        $current = $this->source();
        $target = $this->source();
        $current->write('Config/App.php', '<?php return ["debug" => false];');
        $target->write('Config/App.php', '<?php return ["debug" => false];');
        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('compatible', $report->status());
        self::assertInstanceOf(ChangePlan::class, $report->plan());
        self::assertSame('upgrade:preflight', $report->plan()->operation);
        $metadata = $report->plan()->metadata;
        self::assertNotNull($metadata);
        self::assertSame('upgrade', $metadata->source);
        self::assertContains('ownership', $metadata->verification);
        self::assertSame([], $report->plan()->actions);
        self::assertSame([], $report->findings());
        self::assertFileDoesNotExist($current->path('Project/Activation.json'));
    }

    public function testPhpAndRequiredExtensionMismatchBlockButUnsupportedConstraintIsUnknown(): void
    {
        $current = $this->source();
        $target = $this->source();
        $target->write('composer.json', $this->composer([
            'php' => '^99.0', 'ext-squehub_not_installed' => '*',
        ]));
        $blocked = (new UpgradePreflight())->inspect($current->path(), $target->path());
        self::assertSame('blocked', $blocked->status());
        self::assertTrue($this->hasCode($blocked->findings(), 'php_requirement_mismatch'));
        self::assertTrue($this->hasCode($blocked->findings(), 'required_extension_missing'));

        $target->write('composer.json', $this->composer(['php' => '~8.2']));
        $unknown = (new UpgradePreflight())->inspect($current->path(), $target->path());
        self::assertSame('unknown', $unknown->status());
        self::assertTrue($this->hasCode($unknown->findings(), 'php_constraint_unsupported'));
    }

    public function testProjectCollisionAndCaseCollisionAreBlockedWithoutLeakingContent(): void
    {
        $current = $this->source();
        $target = $this->source();
        $secret = 'SQUEHUB_UPGRADE_SECRET_DO_NOT_LEAK';
        $current->write('Project/Services/Account.php', '<?php // ' . $secret);
        $target->write('Project/Services/Account.php', '<?php // changed');
        $current->write('Project/Views/Home.php', '<?php // old');
        $target->write('Project/Views/home.php', '<?php // new');
        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'application_file_collision'));
        self::assertTrue($this->hasCode($report->findings(), 'case_collision'));
        self::assertSame(hash('sha256', '<?php // ' . $secret),
            $report->plan()->preconditions['Project/Services/Account.php']);
        $output = json_encode($report->toArray(), JSON_THROW_ON_ERROR)
            . ChangeRenderer::render($report->plan());
        self::assertStringNotContainsString($secret, $output);
        self::assertStringNotContainsString($current->path(), $output);
        self::assertStringNotContainsString($target->path(), $output);
    }

    public function testConfigAndMigrationChangesStayInertAndExplicitlyUnproven(): void
    {
        $current = $this->source();
        $target = $this->source();
        $marker = $target->path('executed.txt');
        $current->write('Config/App.php', '<?php return ["a" => 1];');
        $target->write('Config/App.php', '<?php file_put_contents('
            . var_export($marker, true) . ', "config"); return ["a" => 2];');
        $target->write('Database/Migrations/CreateAccounts.php', '<?php file_put_contents('
            . var_export($marker, true) . ', "migration");');
        $target->write('.env', "APP_KEY=SQUEHUB_UPGRADE_SECRET_DO_NOT_LEAK\n");
        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('unknown', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'config_semantics_unproven'));
        self::assertTrue($this->hasCode($report->findings(), 'new_migration_uninspected'));
        self::assertContains('Database/Migrations/CreateAccounts.php',
            array_column($report->plan()->toArray()['actions'], 'subject'));
        self::assertNotContains('.env', array_column($report->plan()->toArray()['actions'], 'subject'));
        self::assertFileDoesNotExist($marker);
        self::assertStringNotContainsString('SQUEHUB_UPGRADE_SECRET_DO_NOT_LEAK',
            json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testEnabledPackageDependencyAndKitRequirementAreVisibleWithoutHookExecution(): void
    {
        $current = $this->source();
        $target = $this->source();
        $marker = $target->path('hook-executed.txt');
        $current->write('Project/Activation.json', json_encode([
            'format' => 1,
            'packages' => ['Weather' => [
                'enabled' => true, 'source_kind' => 'manual', 'source' => 'manual', 'files' => (object) [],
            ]],
            'kits' => ['Reports' => [
                'enabled' => true, 'source_kind' => 'manual', 'source' => 'manual',
                'definition' => (object) [], 'published' => (object) [], 'requires' => [],
            ]],
        ], JSON_THROW_ON_ERROR));
        $target->write('Project/Packages/Weather/Weather.php', '<?php namespace Packages\\Weather; '
            . 'file_put_contents(' . var_export($marker, true) . ', "package"); '
            . 'final class Weather extends \\App\\Plugins\\ServiceProvider {}');
        $target->write('Project/Packages/Weather/composer.json', json_encode([
            'version' => '1.0.0', 'extra' => ['squehub' => ['name' => 'Weather',
                'requires' => ['Missing']]],
        ], JSON_THROW_ON_ERROR));
        $target->write('Project/Kits/Reports/Reports.php', '<?php namespace Project\\Kits\\Reports; '
            . 'file_put_contents(' . var_export($marker, true) . ', "kit"); '
            . 'final class Reports extends \\App\\Plugins\\Kit {}');
        $target->write('Project/Kits/Reports/kit.json', json_encode([
            'format' => 1, 'name' => 'Reports', 'version' => '1.0.0',
            'requires' => ['Missing'], 'hooks' => ['beforeEnable'],
        ], JSON_THROW_ON_ERROR));
        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'package_dependency_unavailable'));
        self::assertTrue($this->hasCode($report->findings(), 'kit_requirement_unavailable'));
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($current->path('Project/Activation.lock'));
    }

    public function testEnabledContributionsMissingFromTargetRemainExplicitlyUnresolved(): void
    {
        $current = $this->source();
        $target = $this->source();
        $current->write('Project/Activation.json', json_encode([
            'format' => 1,
            'packages' => ['Weather' => [
                'enabled' => true, 'source_kind' => 'manual', 'source' => 'manual', 'files' => (object) [],
            ]],
            'kits' => ['Reports' => [
                'enabled' => true, 'source_kind' => 'manual', 'source' => 'manual',
                'definition' => (object) [], 'published' => (object) [], 'requires' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('unknown', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'enabled_package_absent_from_target'));
        self::assertTrue($this->hasCode($report->findings(), 'enabled_kit_absent_from_target'));
        self::assertSame([], $report->plan()->actions);
        self::assertFileExists($current->path('Project/Activation.json'));
    }

    public function testPrivateDirectoryIsExcludedBeforeItsContentsAreVisited(): void
    {
        $current = $this->source();
        $target = $this->source();
        $secret = 'SQUEHUB_PRIVATE_DIRECTORY_SECRET';
        $target->write('Project/Services/.env.secret/Private.php', $secret);

        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'private_source_excluded'));
        self::assertSame([], $report->plan()->actions);
        self::assertSame([['status' => 'blocked', 'code' => 'private_source_excluded',
            'subject' => 'target']], $report->findings());
        self::assertStringNotContainsString($secret,
            json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testReportFindingsAreBoundedForManyConflictingFiles(): void
    {
        $current = $this->source();
        $target = $this->source();
        for ($index = 0; $index < 1030; $index++) {
            $name = sprintf('Project/Services/Conflict%04d.php', $index);
            $current->write($name, '<?php // current');
            $target->write($name, '<?php // target');
        }

        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertCount(1024, $report->findings());
        self::assertTrue($this->hasCode($report->findings(), 'finding_limit'));
        self::assertCount(1030, $report->plan()->actions);
    }

    public function testUnicodeSourceNamesUsePortableCollisionPolicy(): void
    {
        $current = $this->source();
        $target = $this->source();
        $current->write('Project/Views/Résumé.php', '<?php // current');
        $target->write('Project/Views/résumé.php', '<?php // target');

        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'case_collision')
            || $this->hasCode($report->findings(), 'unsafe_source_path'));
    }

    public function testSuppliedRootRejectsLinkedAncestorBeforeCanonicalization(): void
    {
        $current = $this->source();
        $target = $this->source();
        $entry = $this->source();
        $current->write('Project/README.md', 'source');
        if (!@symlink($current->path(), $entry->path('shortcut'))) {
            self::markTestSkipped('Directory symlinks are unavailable on this host.');
        }

        $this->expectException(UpgradeException::class);
        (new UpgradePreflight())->inspect($entry->path('shortcut/Project/..'), $target->path());
    }

    public function testModifiedOwnedPackageFileBlocksUpgrade(): void
    {
        $current = $this->source();
        $target = $this->source();
        $original = '<?php namespace Packages\\Weather; final class Weather extends \\App\\Plugins\\ServiceProvider {}';
        $current->write('Project/Packages/Weather/Weather.php', $original . '// local edit');
        $target->write('Project/Packages/Weather/Weather.php', $original . '// upstream edit');
        $current->write('Project/Activation.json', json_encode([
            'format' => 1,
            'packages' => ['Weather' => [
                'enabled' => true, 'source_kind' => 'local', 'source' => 'local:Weather#123456789abc',
                'files' => ['Weather.php' => hash('sha256', $original)],
            ]],
            'kits' => (object) [],
        ], JSON_THROW_ON_ERROR));
        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'owned_file_modified'));
        self::assertFileDoesNotExist($current->path('Project/Activation.lock'));
    }

    public function testOmittedFrameworkSourceIsUnknownRatherThanAnImplicitDelete(): void
    {
        $current = $this->source();
        $target = $this->source();
        $current->write('App/Services/Example.php', '<?php final class Example {}');

        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('unknown', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'target_framework_file_missing'));
        self::assertSame([], $report->plan()->actions);
        self::assertFileExists($current->path('App/Services/Example.php'));
    }

    public function testSharedKitPublicationOwnershipIsBlocked(): void
    {
        $current = $this->source();
        $target = $this->source();
        $publication = ['Project/Controllers/Report.php' => [
            'hash' => hash('sha256', 'same'), 'kind' => 'generated',
        ]];
        $kit = static fn (): array => [
            'enabled' => false, 'source_kind' => 'manual', 'source' => 'manual',
            'definition' => (object) [], 'published' => $publication, 'requires' => [],
        ];
        $current->write('Project/Activation.json', json_encode([
            'format' => 1, 'packages' => (object) [],
            'kits' => ['Reports' => $kit(), 'Exports' => $kit()],
        ], JSON_THROW_ON_ERROR));

        $report = (new UpgradePreflight())->inspect($current->path(), $target->path());

        self::assertSame('blocked', $report->status());
        self::assertTrue($this->hasCode($report->findings(), 'shared_ownership'));
        self::assertFileDoesNotExist($current->path('Project/Activation.lock'));
    }
}
