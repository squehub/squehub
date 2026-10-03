<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Packages\PackageException;
use App\Packages\PackageFiles;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Package source discovery must reject names that collapse on another host. */
final class PackageFilesPortabilityTest extends TestCase
{
    public function testSameBasenameInDifferentDirectoriesIsPortable(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Source/Weather.php', 'root');
            $project->write('Source/Config/Weather.php', 'config');
            $project->write('Source/Views/Weather.php', 'view');
            $source = $project->path('Source');

            PackageFiles::inspectTree($source);
            self::assertSame(
                ['Config/Weather.php', 'Views/Weather.php', 'Weather.php'],
                array_keys(PackageFiles::fingerprints($source))
            );
            self::assertSame(['Config', 'Views'], PackageFiles::directories($source));
        } finally {
            $project->remove();
        }
    }

    public function testCaseSensitiveSourceRejectsSiblingCaseCollisionsBeforePlanning(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Source/Foo.php', 'first');
            $project->write('Source/foo.php', 'second');
            $source = $project->path('Source');
            $names = scandir($source) ?: [];
            if (!in_array('Foo.php', $names, true) || !in_array('foo.php', $names, true)) {
                self::markTestSkipped('This filesystem cannot store case-distinct sibling names.');
            }

            foreach ([
                static fn () => PackageFiles::inspectTree($source),
                static fn () => PackageFiles::fingerprints($source),
                static fn () => PackageFiles::directories($source),
            ] as $inspection) {
                try {
                    $inspection();
                    self::fail('Case-colliding Package source was accepted.');
                } catch (PackageException $exception) {
                    self::assertStringContainsString('case-colliding', $exception->getMessage());
                }
            }
        } finally {
            $project->remove();
        }
    }

    public function testNestedCaseOnlyRelativePathsAreRejected(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Source/Routes/Web.php', 'first');
            $project->write('Source/routes/web.php', 'second');
            $source = $project->path('Source');
            $names = scandir($source) ?: [];
            if (!in_array('Routes', $names, true) || !in_array('routes', $names, true)) {
                self::markTestSkipped('This filesystem cannot store case-distinct sibling directories.');
            }

            foreach ([
                static fn () => PackageFiles::inspectTree($source),
                static fn () => PackageFiles::fingerprints($source),
                static fn () => PackageFiles::directories($source),
            ] as $inspection) {
                try {
                    $inspection();
                    self::fail('Case-colliding Package paths were accepted.');
                } catch (PackageException $exception) {
                    self::assertStringContainsString('case-colliding', $exception->getMessage());
                }
            }
        } finally {
            $project->remove();
        }
    }
}
