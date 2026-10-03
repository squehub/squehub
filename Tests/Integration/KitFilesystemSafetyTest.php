<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Kits\KitException;
use App\Kits\KitManager;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Lifecycle review rejects filesystem changes that could escape Kit ownership. */
final class KitFilesystemSafetyTest extends TestCase
{
    public function testChangedLocalSourceMakesInstallPlanStaleWithoutPublishing(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $this->source($source, 'StaleKit');
            $manager = (new Application($project->path()))->container()->make(KitManager::class);
            $plan = $manager->planInstall($source->path('StaleKit'));
            $source->write('StaleKit/kit.json',
                '{"format":1,"name":"StaleKit","version":"2.0.0"}');
            $this->expectException(KitException::class);
            try {
                $manager->apply($plan);
            } finally {
                self::assertDirectoryDoesNotExist($project->path('Project/Kits/StaleKit'));
            }
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    public function testArchiveAndRemoteSourcesAreNotKitInstallSources(): void
    {
        $project = new TemporaryProject();
        $sources = new TemporaryProject();
        try {
            $sources->write('AppStarter.zip', 'not a Kit directory');
            $manager = (new Application($project->path()))->container()->make(KitManager::class);
            foreach ([
                $sources->path('AppStarter.zip'),
                'file:///AppStarter',
                'https://example.test/AppStarter.zip',
                'https://github.com/squehub/app-starter/releases/download/v1.0.0/squehub-app-starter-1.0.0.zip',
                'https://github.com/squehub/app-starter',
                'https://git.example.test/team/AppStarter.git',
            ] as $source) {
                try {
                    $manager->planInstall($source);
                    self::fail('Unsupported Kit source form was accepted: ' . $source);
                } catch (KitException) {
                    self::assertDirectoryDoesNotExist($project->path('Project/Kits/AppStarter'));
                }
            }
        } finally {
            $sources->remove();
            $project->remove();
        }
    }

    public function testLinkedSourceEntryIsRejectedBeforeInstallation(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        $linked = $source->path('LinkedKit/Templates/linked.php');
        try {
            $this->source($source, 'LinkedKit');
            $source->write('Outside/Secret.php', '<?php // outside Kit source');
            if (!is_dir(dirname($linked))) { mkdir(dirname($linked), 0777, true); }
            if (!@symlink($source->path('Outside/Secret.php'), $linked)) {
                self::markTestSkipped('This host cannot create a file symlink.');
            }
            $manager = (new Application($project->path()))->container()->make(KitManager::class);
            $this->expectException(KitException::class);
            try {
                $manager->planInstall($source->path('LinkedKit'));
            } finally {
                self::assertDirectoryDoesNotExist($project->path('Project/Kits/LinkedKit'));
            }
        } finally {
            if (is_link($linked)) { unlink($linked); }
            $source->remove();
            $project->remove();
        }
    }

    public function testLinkedPublicationParentIsAConflictAndNeverWritesOutsideProject(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        $linked = $project->path('Project/Routes');
        try {
            $this->source($source, 'RouteKit', [[
                'source' => 'Templates/Routes/Shop.php',
                'target' => 'Project/Routes/Shop.php',
            ]]);
            $source->write('RouteKit/Templates/Routes/Shop.php', '<?php // route');
            $project->write('Outside/Keep.txt', 'untouched');
            if (!is_dir(dirname($linked))) { mkdir(dirname($linked), 0777, true); }
            if (!@symlink($project->path('Outside'), $linked)) {
                self::markTestSkipped('This host cannot create a directory symlink.');
            }
            $manager = (new Application($project->path()))->container()->make(KitManager::class);
            $manager->apply($manager->planInstall($source->path('RouteKit')));
            $plan = $manager->planEnable('RouteKit');
            self::assertTrue($plan->hasConflicts());
            self::assertFileDoesNotExist($project->path('Outside/Shop.php'));
            self::assertSame('untouched', file_get_contents($project->path('Outside/Keep.txt')));
        } finally {
            if (is_link($linked)) { unlink($linked); }
            $source->remove();
            $project->remove();
        }
    }

    public function testCaseOnlyTargetCollisionDoesNotOverwriteExistingRoute(): void
    {
        $project = new TemporaryProject();
        $source = new TemporaryProject();
        try {
            $this->source($source, 'CaseKit', [[
                'source' => 'Templates/Routes/Shop.php',
                'target' => 'Project/Routes/Shop.php',
            ]]);
            $source->write('CaseKit/Templates/Routes/Shop.php', '<?php // Kit version');
            $project->write('Project/Routes/shop.php', '<?php // Application version');
            $manager = (new Application($project->path()))->container()->make(KitManager::class);
            $manager->apply($manager->planInstall($source->path('CaseKit')));
            self::assertTrue($manager->planEnable('CaseKit')->hasConflicts());
            self::assertSame('<?php // Application version',
                file_get_contents($project->path('Project/Routes/shop.php')));
        } finally {
            $source->remove();
            $project->remove();
        }
    }

    /** @param list<array{source:string,target:string}> $files */
    private function source(TemporaryProject $source, string $name, array $files = []): void
    {
        $source->write($name . '/' . $name . '.php',
            '<?php namespace Project\\Kits\\' . $name . '; use App\\Plugins\\Kit; '
            . 'final class ' . $name . ' extends Kit {}');
        $source->write($name . '/kit.json', json_encode([
            'format' => 1, 'name' => $name, 'version' => '1.0.0', 'files' => $files,
        ], JSON_THROW_ON_ERROR));
    }
}
