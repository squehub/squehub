<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Packages\PackageException;
use App\Packages\PackageFiles;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Installed Package Views may link within Views; managed source copying stays link-free. */
final class PackageViewLinkInspectionTest extends TestCase
{
    public function testContainedViewFileLinkIsInspectedOnlyInInstalledPackageMode(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Source/Views/Alias.squehub.php');
        try {
            $project->write('Source/Views/Actual.squehub.php', 'safe');
            if (!@symlink($project->path('Source/Views/Actual.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }

            PackageFiles::inspectTree($project->path('Source'), false, true);
            $files = PackageFiles::fingerprints($project->path('Source'), false, true);
            self::assertArrayHasKey('Views/Alias.squehub.php', $files);
            self::assertArrayHasKey('Views/Actual.squehub.php', $files);
            self::assertNotSame($files['Views/Alias.squehub.php'],
                $files['Views/Actual.squehub.php']);

            // The lifecycle copier cannot carry a link into a managed
            // Package. Its default inspection remains deliberately strict.
            foreach ([
                static fn () => PackageFiles::inspectTree($project->path('Source')),
                static fn () => PackageFiles::fingerprints($project->path('Source')),
            ] as $inspect) {
                try {
                    $inspect();
                    self::fail('A managed Package source link was accepted.');
                } catch (PackageException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        } finally {
            if (is_link($link)) { @unlink($link); }
            $project->remove();
        }
    }

    public function testInstalledViewLinkCannotLeaveItsOwnViewRoot(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Source/Views/Escape.squehub.php');
        try {
            $project->write('Source/Views/Actual.squehub.php', 'safe');
            $project->write('Source/Outside.squehub.php', 'outside');
            if (!@symlink($project->path('Source/Outside.squehub.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this test process.');
            }
            $this->expectException(PackageException::class);
            PackageFiles::inspectTree($project->path('Source'), false, true);
        } finally {
            if (is_link($link)) { @unlink($link); }
            $project->remove();
        }
    }
}
