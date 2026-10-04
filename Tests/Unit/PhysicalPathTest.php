<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Packages\PackageException;
use App\Packages\PackageFiles;
use App\Support\PhysicalPath;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Physical identity must accept Windows aliases without accepting real links. */
final class PhysicalPathTest extends TestCase
{
    public function testOrdinaryPackageEntriesRemainPhysical(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Source/Views/Welcome.php', 'safe');
            $source = $project->path('Source');
            $file = $project->path('Source/Views/Welcome.php');

            self::assertTrue(PhysicalPath::unlinked($source));
            self::assertTrue(PhysicalPath::unlinked($file));
            self::assertTrue(PhysicalPath::same($file, (string) realpath($file)));
            self::assertTrue(PhysicalPath::within($file, $source));
            PackageFiles::inspectTree($source);
            self::assertSame(['Views/Welcome.php'], array_keys(PackageFiles::fingerprints($source)));
        } finally {
            $project->remove();
        }
    }

    public function testWindowsDosShortNameIsTheSameUnlinkedEntryWhenAvailable(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
            self::markTestSkipped('DOS short names require Windows command execution.');
        }
        $project = new TemporaryProject();
        try {
            $project->write('Source/Welcome.php', 'safe');
            $long = $project->path('Source');
            // The test asks Windows for its actual short spelling. Some volumes
            // disable 8.3 names; those hosts cannot exercise this representation.
            $command = 'cmd /d /s /c for %I in ("' . str_replace('/', '\\', $long)
                . '") do @echo %~sI';
            $lines = [];
            @exec($command, $lines, $status);
            $short = trim(implode('', $lines));
            if ($status !== 0 || $short === ''
                || strcasecmp(str_replace('\\', '/', $short), str_replace('\\', '/', $long)) === 0) {
                self::markTestSkipped('This volume has no distinct DOS short path for the fixture.');
            }

            self::assertTrue(PhysicalPath::same($long, $short));
            self::assertSame(PhysicalPath::identity($long), PhysicalPath::identity($short));
            self::assertTrue(PhysicalPath::unlinked($short));
            self::assertSame('Welcome.php', PhysicalPath::relativeTo($short . '/Welcome.php', $long));
            PackageFiles::inspectTree($short);
            self::assertSame(PackageFiles::fingerprints($long), PackageFiles::fingerprints($short));
        } finally {
            $project->remove();
        }
    }

    public function testFileSymlinkCannotEnterPackageSource(): void
    {
        $project = new TemporaryProject();
        $link = $project->path('Source/Linked.php');
        try {
            $project->write('Source/Welcome.php', 'safe');
            $project->write('Outside/Secret.php', 'outside');
            if (!@symlink($project->path('Outside/Secret.php'), $link)) {
                self::markTestSkipped('File symlinks are unavailable to this process.');
            }

            self::assertFalse(PhysicalPath::unlinked($link));
            $this->expectException(PackageException::class);
            PackageFiles::inspectTree($project->path('Source'));
        } finally {
            if (is_link($link)) {
                @unlink($link);
            }
            $project->remove();
        }
    }

    public function testWindowsJunctionCannotEnterPackageSourceWhenAvailable(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
            self::markTestSkipped('Directory junctions require Windows command execution.');
        }
        $project = new TemporaryProject();
        $link = $project->path('Source/Linked');
        try {
            $project->write('Source/Welcome.php', 'safe');
            $project->write('Outside/Secret.php', 'outside');
            $command = 'cmd /d /s /c mklink /J "' . str_replace('/', '\\', $link)
                . '" "' . str_replace('/', '\\', $project->path('Outside')) . '"';
            $output = [];
            @exec($command, $output, $status);
            if ($status !== 0) {
                self::markTestSkipped('Directory junction creation is unavailable to this process.');
            }

            self::assertFalse(PhysicalPath::unlinked($link));
            try {
                PackageFiles::inspectTree($project->path('Source'));
                self::fail('A Package source junction was accepted.');
            } catch (PackageException) {
                self::assertSame('outside', file_get_contents($project->path('Outside/Secret.php')));
            }
        } finally {
            if (file_exists($link) || is_link($link)) {
                @rmdir($link); // Windows removes the junction, not its target.
            }
            $project->remove();
        }
    }
}
