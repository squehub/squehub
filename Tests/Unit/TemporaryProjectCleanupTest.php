<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Disposable project cleanup removes links without following their targets. */
final class TemporaryProjectCleanupTest extends TestCase
{
    public function testRemovesOrdinaryFilesAndNestedDirectories(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Project/Nested/Example.txt', 'fixture');
            self::assertFileExists($project->path('Project/Nested/Example.txt'));

            $project->remove();

            self::assertDirectoryDoesNotExist($project->path());
        } finally {
            $project->remove();
        }
    }

    public function testFileLinkIsRemovedWithoutDeletingItsOutsideTarget(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        try {
            $outside->write('Protected/keep.txt', 'outside file');
            $project->write('public/assets/ordinary.txt', 'ordinary');
            $link = $project->path('public/assets/outside.txt');
            if (!function_exists('symlink') || !@symlink($outside->path('Protected/keep.txt'), $link)) {
                self::markTestSkipped('Creating file symlinks is unavailable on this host.');
            }

            $project->remove();

            self::assertDirectoryDoesNotExist($project->path());
            self::assertSame('outside file', file_get_contents($outside->path('Protected/keep.txt')));
        } finally {
            try {
                $project->remove();
            } finally {
                $outside->remove();
            }
        }
    }

    public function testDirectoryLinkIsRemovedWithoutTraversingItsOutsideTarget(): void
    {
        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        try {
            $outside->write('Protected/Nested/keep.txt', 'outside directory');
            $project->write('public/assets/ordinary.txt', 'ordinary');
            $link = $project->path('public/assets/linked');
            if (!function_exists('symlink') || !@symlink($outside->path('Protected'), $link)) {
                self::markTestSkipped('Creating directory symlinks is unavailable on this host.');
            }

            $project->remove();

            self::assertDirectoryDoesNotExist($project->path());
            self::assertSame('outside directory',
                file_get_contents($outside->path('Protected/Nested/keep.txt')));
        } finally {
            try {
                $project->remove();
            } finally {
                $outside->remove();
            }
        }
    }

    public function testBrokenLinkIsRemovedAfterItsTargetDisappears(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('secret.txt', 'removed target');
            $project->write('public/assets/ordinary.txt', 'ordinary');
            $link = $project->path('public/assets/outside.txt');
            if (!function_exists('symlink') || !@symlink($project->path('secret.txt'), $link)) {
                self::markTestSkipped('Creating file symlinks is unavailable on this host.');
            }
            unlink($project->path('secret.txt'));
            self::assertTrue(is_link($link));
            self::assertFileDoesNotExist($project->path('secret.txt'));

            $project->remove();

            self::assertDirectoryDoesNotExist($project->path());
        } finally {
            $project->remove();
        }
    }

    public function testWindowsJunctionIsRemovedWithoutTraversingItsOutsideTarget(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
            self::markTestSkipped('Directory junctions require Windows command execution.');
        }

        $project = new TemporaryProject();
        $outside = new TemporaryProject();
        $link = $project->path('public/linked');
        try {
            $project->write('public/ordinary.txt', 'ordinary');
            $outside->write('Protected/keep.txt', 'outside directory');
            $command = 'cmd /d /s /c mklink /J "' . str_replace('/', '\\', $link)
                . '" "' . str_replace('/', '\\', $outside->path('Protected')) . '"';
            $output = [];
            @exec($command, $output, $status);
            if ($status !== 0) {
                self::markTestSkipped('Directory junction creation is unavailable to this process.');
            }

            $project->remove();

            self::assertDirectoryDoesNotExist($project->path());
            self::assertSame('outside directory',
                file_get_contents($outside->path('Protected/keep.txt')));
        } finally {
            if (file_exists($link) || is_link($link)) {
                @rmdir($link);
            }
            $project->remove();
            $outside->remove();
        }
    }
}
