<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Bundles\ProjectBundle;
use App\Foundation\Application;
use App\Recovery\RecoveryException;
use App\Recovery\RecoveryPlanner;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Recovery evidence is categorical and never implies a complete backup. */
final class RecoveryPlannerTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private function application(string $storageDriver = 'local'): Application
    {
        $app = new Application($this->project->path());
        $app->config()->set('database.default', 'sqlite');
        $app->config()->set('database.connections.sqlite.driver', 'sqlite');
        $app->config()->set('storage.default', 'selected');
        $app->config()->set('storage.drives.selected.driver', $storageDriver);
        $app->config()->set('crypt.keys.primary', 'RECOVERY_PRIVATE_KEY_DO_NOT_LEAK');
        $app->config()->set('database.connections.sqlite.password', 'RECOVERY_DB_SECRET_DO_NOT_LEAK');
        return $app;
    }

    public function testBoundariesNeverTreatSourceAsDatabaseOrSecrets(): void
    {
        $report = (new RecoveryPlanner($this->application()))->plan();
        $data = $report->toArray()['boundaries'];
        self::assertSame('missing', $data['source']['status']);
        self::assertSame('missing', $data['database']['status']);
        self::assertSame('separate_local_backup_required', $data['uploads']['status']);
        self::assertSame('required_separately', $data['secrets']['status']);
        self::assertSame('excluded_rebuildable', $data['runtime']['status']);
        self::assertSame('separate_decision_required', $data['queue']['status']);
        self::assertSame('at_least_once', $data['queue']['delivery']);
        self::assertFalse($data['database']['restored']);
        self::assertStringNotContainsString('RECOVERY_PRIVATE_KEY_DO_NOT_LEAK',
            json_encode($report->toArray(), JSON_THROW_ON_ERROR) . $report->render());
        self::assertStringNotContainsString('RECOVERY_DB_SECRET_DO_NOT_LEAK',
            json_encode($report->toArray(), JSON_THROW_ON_ERROR) . $report->render());
    }

    public function testExternalSnapshotIsFingerprintedButConsistencyRemainsUnverified(): void
    {
        $this->project->write('snapshot.sqlite', 'operator-produced snapshot');
        $report = (new RecoveryPlanner($this->application()))->plan(
            databaseSnapshot: $this->project->path('snapshot.sqlite'), snapshotDriver: 'sqlite');
        $database = $report->toArray()['boundaries']['database'];
        self::assertSame('provided_unverified', $database['status']);
        self::assertSame('operator_declaration_required', $database['consistency']);
        self::assertSame(hash('sha256', 'operator-produced snapshot'), $database['sha256']);
        self::assertSame('calculated_unanchored', $database['checksum']);
        self::assertNull($database['captured_at']);
        self::assertFalse($database['restored']);
    }

    public function testSnapshotChecksumMustMatchIndependentExpectedValue(): void
    {
        $this->project->write('snapshot.sqlite', 'operator-produced snapshot');
        $path = $this->project->path('snapshot.sqlite');
        $report = (new RecoveryPlanner($this->application()))->plan(
            databaseSnapshot: $path, snapshotDriver: 'sqlite',
            expectedSnapshotSha256: hash('sha256', 'operator-produced snapshot'));
        self::assertSame('verified_against_expected',
            $report->toArray()['boundaries']['database']['checksum']);
        $this->expectException(RecoveryException::class);
        (new RecoveryPlanner($this->application()))->plan(
            databaseSnapshot: $path, snapshotDriver: 'sqlite',
            expectedSnapshotSha256: str_repeat('0', 64));
    }

    public function testWrongDatabaseDriverCannotBePresentedAsMatchingEvidence(): void
    {
        $this->project->write('snapshot.sql', 'content');
        $this->expectException(RecoveryException::class);
        (new RecoveryPlanner($this->application()))->plan(
            databaseSnapshot: $this->project->path('snapshot.sql'), snapshotDriver: 'mysql');
    }

    public function testRemoteUploadsRemainAnExternalRecoveryBoundary(): void
    {
        $data = (new RecoveryPlanner($this->application('s3')))->plan()->toArray()['boundaries'];
        self::assertSame('external_object_backup_required', $data['uploads']['status']);
        self::assertFalse($data['uploads']['included']);
    }

    public function testCorruptBundleCannotBeClaimedAsAvailableSource(): void
    {
        $this->project->write('broken.sqhb', 'not a bundle');
        $data = (new RecoveryPlanner($this->application()))->plan(
            sourceBundle: $this->project->path('broken.sqhb'))->toArray()['boundaries'];
        self::assertSame('invalid', $data['source']['status']);
    }

    public function testValidatedSourceBundleStillLeavesDataAndSecretsSeparate(): void
    {
        rmdir($this->project->path('config')); // The fixture creates a legacy lowercase directory.
        $this->project->write('composer.json', '{"name":"squehub/squehub","require":{"php":"^8.2"}}');
        $this->project->write('Project/Views/Welcome.php', '<h1>Welcome</h1>');
        $archive = $this->project->path('source.sqhb');
        (new ProjectBundle($this->project->path()))->export($archive);
        $data = (new RecoveryPlanner($this->application()))->plan(sourceBundle: $archive)
            ->toArray()['boundaries'];
        self::assertSame('available', $data['source']['status']);
        self::assertSame('verified_against_manifest', $data['source']['integrity']);
        self::assertFalse($data['source']['included']);
        self::assertSame('missing', $data['database']['status']);
        self::assertFalse($data['secrets']['included']);
    }
}
