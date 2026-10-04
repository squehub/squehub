<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Activation\ActivationStore;
use App\Bundles\BundleException;
use App\Bundles\BundleManifest;
use App\Bundles\BundlePath;
use App\Bundles\ProjectBundle;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Portable bundles are reviewed as inert source before any target write. */
final class ProjectBundleTest extends TestCase
{
    public function testRoundTripIncludesCanonicalSourceAndExcludesRuntimeSecrets(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $source->write('Project/Routes/Web.php', '<?php file_put_contents(' . var_export(
                $holder->path('executed.txt'), true) . ', "unsafe");');
            $source->write('Config/App.php', '<?php return [];');
            $source->write('Database/Migrations/CreateThings.php', '<?php // Migration.');
            $source->write('Database/Seeders/ThingSeeder.php', '<?php // Seeder.');
            $source->write('Database/Factories/ThingFactory.php', '<?php // Factory.');
            $source->write('Assets/logo.svg', '<svg></svg>');
            $source->write('public/assets/app.css', 'body {}');
            $source->write('composer.lock', '{}');
            $source->write('.env', 'BUNDLE_SECRET_DO_NOT_LEAK');
            $source->write('Project/.env.local', 'BUNDLE_SECRET_DO_NOT_LEAK');
            $source->write('Project/Uploads/private.bin', 'BUNDLE_SECRET_DO_NOT_LEAK');
            $source->write('Storage/Logs/private.log', 'BUNDLE_SECRET_DO_NOT_LEAK');
            $source->write('vendor/Private.php', 'BUNDLE_SECRET_DO_NOT_LEAK');
            $source->write('Project/Packages/Demo/State.json', 'BUNDLE_SECRET_DO_NOT_LEAK');

            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $exported = $bundle->export($archive);
            $inspected = $bundle->inspect($archive);
            self::assertSame($exported->toArray(), $inspected->toArray());
            self::assertSame('squehub/bundle-test', $inspected->toArray()['project']);
            self::assertSame(ProjectBundle::SOURCE_ROOTS, $inspected->toArray()['source_roots']);
            $paths = array_column($inspected->files(), 'path');
            foreach (['Project/Routes/Web.php', 'Config/App.php',
                'Database/Migrations/CreateThings.php', 'Database/Seeders/ThingSeeder.php',
                'Database/Factories/ThingFactory.php', 'Assets/logo.svg',
                'public/assets/app.css', 'composer.json', 'composer.lock'] as $expected) {
                self::assertContains($expected, $paths);
            }
            self::assertNotContains('.env', $paths);
            self::assertNotContains('Project/.env.local', $paths);
            self::assertNotContains('Project/Uploads/private.bin', $paths);
            self::assertNotContains('Project/Packages/Demo/State.json', $paths);
            self::assertStringNotContainsString('BUNDLE_SECRET_DO_NOT_LEAK',
                (string) file_get_contents($archive));

            $target = $holder->path('Restored');
            $plan = $bundle->planImport($archive, $target);
            self::assertFalse($plan->hasConflicts());
            self::assertFileDoesNotExist($target);
            self::assertTrue($bundle->apply($plan)->complete());
            self::assertSame('body {}', file_get_contents($target . '/public/assets/app.css'));
            self::assertFileDoesNotExist($target . '/.env');
            self::assertFileDoesNotExist($target . '/Project/Uploads/private.bin');
            self::assertFileDoesNotExist($holder->path('executed.txt'));
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testExistingTargetChangesAreReviewedAndRecheckedBeforeApply(): void
    {
        $source = new TemporaryProject();
        $target = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $this->prepareSource($target);
            $source->write('Project/Changed.txt', 'incoming');
            $target->write('Project/Changed.txt', 'previous');
            $target->write('Project/Unrelated.txt', 'keep');
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);

            $plan = $bundle->planImport($archive, $target->path());
            self::assertFalse($plan->hasConflicts());
            $modified = array_values(array_filter($plan->actions,
                static fn ($action): bool => $action->subject === 'Project/Changed.txt'));
            self::assertCount(1, $modified);
            self::assertSame('modify', $modified[0]->kind);
            self::assertSame('review', $modified[0]->risk);
            self::assertSame(hash('sha256', 'previous'), $modified[0]->before);
            self::assertSame('previous', file_get_contents($target->path('Project/Changed.txt')));

            $target->write('Project/Changed.txt', 'changed after review');
            try {
                $bundle->apply($plan);
                self::fail('A changed review precondition must block import.');
            } catch (BundleException $exception) {
                self::assertStringContainsString('changed after review', $exception->getMessage());
            }
            self::assertSame('changed after review', file_get_contents($target->path('Project/Changed.txt')));
            $fresh = $bundle->planImport($archive, $target->path());
            self::assertTrue($bundle->apply($fresh)->complete());
            self::assertSame('incoming', file_get_contents($target->path('Project/Changed.txt')));
            self::assertSame('keep', file_get_contents($target->path('Project/Unrelated.txt')));

            $identical = $bundle->planImport($archive, $target->path());
            self::assertSame([], $identical->actions);
            $target->write('Project/Changed.txt', 'drift despite a no-op plan');
            $this->expectException(BundleException::class);
            $bundle->apply($identical);
        } finally {
            $holder->remove();
            $target->remove();
            $source->remove();
        }
    }

    public function testInspectionRejectsChecksumAndManifestPathAttacksWithoutWriting(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $source->write('Project/Example.txt', 'trusted content');
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            $original = (string) file_get_contents($archive);

            $corrupt = substr_replace($original, 'X', -1, 1);
            file_put_contents($archive, $corrupt);
            $this->assertRejected($bundle, $archive);

            $parts = $this->archiveParts($original);
            foreach (['../escape.txt', 'Project/../escape.txt', 'Project\\Escape.txt',
                'Project/CON.txt', 'Project/.env'] as $attack) {
                $manifest = $parts['manifest'];
                $manifest['files'][1]['path'] = $attack;
                file_put_contents($archive, $this->repack($manifest, $parts['payload']));
                $this->assertRejected($bundle, $archive);
            }
            self::assertFileDoesNotExist($holder->path('escape.txt'));
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testArchiveMutationAfterReviewCannotBeApplied(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $source->write('Project/Example.txt', 'original');
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            $target = $holder->path('Restored');
            $plan = $bundle->planImport($archive, $target);
            file_put_contents($archive, (string) file_get_contents($archive) . 'extra');
            try {
                $bundle->apply($plan);
                self::fail('Archive mutation must invalidate a reviewed plan.');
            } catch (BundleException) {
                self::assertFileDoesNotExist($target);
            }
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testPackageAndKitActivationMetadataIsPreservedWithoutRunningTheirCode(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $marker = $holder->path('hook-executed.txt');
            $source->write('Project/Packages/Orders/Orders.php', '<?php file_put_contents('
                . var_export($marker, true) . ', "package");');
            $source->write('Project/Kits/Shop/Shop.php', '<?php file_put_contents('
                . var_export($marker, true) . ', "kit");');
            (new ActivationStore($source->path()))->writeCombined([
                'Orders' => ['enabled' => true, 'source_kind' => 'manual',
                    'source' => 'manual', 'files' => []],
            ], [
                'Shop' => ['enabled' => false, 'source_kind' => 'manual',
                    'source' => 'manual', 'definition' => [], 'published' => [], 'requires' => []],
            ]);
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $manifest = $bundle->export($archive)->toArray();
            self::assertSame([['name' => 'Orders', 'enabled' => true]],
                $manifest['activation']['packages']);
            self::assertSame([['name' => 'Shop', 'enabled' => false]],
                $manifest['activation']['kits']);
            self::assertContains('Project/Activation.json', array_column($manifest['files'], 'path'));
            self::assertNotContains('Project/Activation.lock', array_column($manifest['files'], 'path'));
            $target = $holder->path('Restored');
            $plan = $bundle->planImport($archive, $target);
            self::assertFalse($plan->hasConflicts());
            self::assertTrue($bundle->apply($plan)->complete());
            self::assertSame((new ActivationStore($source->path()))->read(),
                (new ActivationStore($target))->read());
            self::assertFileDoesNotExist($marker);
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testInspectionRejectsActivationSummaryTamperingWithValidChecksums(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            (new ActivationStore($source->path()))->writeCombined([
                'Orders' => ['enabled' => true, 'source_kind' => 'manual',
                    'source' => 'manual', 'files' => []],
            ], []);
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            $parts = $this->archiveParts((string) file_get_contents($archive));

            $manifest = $parts['manifest'];
            $manifest['activation']['packages'][0]['enabled'] = false;
            file_put_contents($archive, $this->repack($manifest, $parts['payload']));
            $this->assertRejected($bundle, $archive);

            $activation = $this->payloadFile($parts, 'Project/Activation.json');
            $changed = str_replace('"enabled": true', '"enabled": false', $activation);
            self::assertNotSame($activation, $changed);
            $altered = $this->replacePayloadFile($parts, 'Project/Activation.json', $changed);
            file_put_contents($archive, $this->repack($altered['manifest'], $altered['payload']));
            $this->assertRejected($bundle, $archive);
            self::assertFileDoesNotExist($holder->path('Restored'));
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testInspectionRejectsAnActivationSummaryWithoutRegistryPayload(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            $parts = $this->archiveParts((string) file_get_contents($archive));
            self::assertNotContains('Project/Activation.json',
                array_column($parts['manifest']['files'], 'path'));
            $parts['manifest']['activation']['packages'][] = ['name' => 'Orders', 'enabled' => true];
            file_put_contents($archive, $this->repack($parts['manifest'], $parts['payload']));
            $this->assertRejected($bundle, $archive);
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testManifestRejectsCaseCollisionAndUnsupportedVersion(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            $manifest = $bundle->inspect($archive)->toArray();
            $wrongVersion = $manifest;
            $wrongVersion['format'] = 2;
            try {
                BundleManifest::fromArray($wrongVersion);
                self::fail('An unsupported bundle version must be rejected.');
            } catch (BundleException) {
                self::assertSame(2, $wrongVersion['format']);
            }
            $caseCollision = $manifest;
            $caseCollision['files'][] = [
                'path' => 'Project/readme.md', 'size' => 0,
                'sha256' => hash('sha256', ''),
            ];
            $this->expectException(BundleException::class);
            BundleManifest::fromArray($caseCollision);
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testDistinctSourceFilenamesDifferingOnlyByCaseCannotBeExported(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $source->write('Project/Foo.php', 'upper');
            $source->write('Project/foo.php', 'lower');
            if (file_get_contents($source->path('Project/Foo.php')) !== 'upper') {
                self::markTestSkipped('The filesystem cannot retain distinct Foo.php and foo.php files.');
            }
            self::assertSame('lower', file_get_contents($source->path('Project/foo.php')));
            $this->expectException(BundleException::class);
            (new ProjectBundle($source->path()))->export($holder->path('collision.sqhb'));
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testTargetCaseCollisionAndDifferentProjectIdentityBlockImport(): void
    {
        $source = new TemporaryProject();
        $target = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $this->prepareSource($target);
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            unlink($target->path('Project/README.md'));
            $target->write('Project/readme.md', 'wrong case');
            $plan = $bundle->planImport($archive, $target->path());
            self::assertTrue($plan->hasConflicts());
            self::assertStringContainsString('case-conflicting', implode(' ', $plan->conflicts));
            self::assertSame('wrong case', file_get_contents($target->path('Project/readme.md')));

            $target->write('composer.json', json_encode([
                'name' => 'another/application', 'require' => ['php' => '^8.2'],
            ], JSON_THROW_ON_ERROR));
            $this->expectException(BundleException::class);
            $bundle->planImport($archive, $target->path());
        } finally {
            $holder->remove();
            $target->remove();
            $source->remove();
        }
    }

    public function testCallerPathsMayBeRelativeWithoutAllowingArchiveTraversal(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        $original = getcwd();
        try {
            $this->prepareSource($source);
            self::assertTrue(chdir($holder->path()));
            self::assertSame(str_replace('\\', '/', realpath('..')),
                BundlePath::existingInput('..'));
            $bundle = new ProjectBundle($source->path());
            $bundle->export('project.sqhb');
            self::assertSame('squehub/bundle-test', $bundle->inspect('./project.sqhb')->project());
            $plan = $bundle->planImport('project.sqhb', 'Restored');
            self::assertTrue($bundle->apply($plan)->complete());
            self::assertFileExists($holder->path('Restored/composer.json'));
        } finally {
            if ($original !== false) { chdir($original); }
            $holder->remove();
            $source->remove();
        }
    }

    public function testLinkedSourceIsNeverCopiedIntoArchive(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $holder->write('outside.txt', 'private outside bytes');
            $link = $source->path('Project/Outside.txt');
            if (!@symlink($holder->path('outside.txt'), $link)) {
                self::markTestSkipped('Creating a filesystem link requires host support.');
            }
            try {
                (new ProjectBundle($source->path()))->export($holder->path('project.sqhb'));
                self::fail('Linked source entries must not be exported.');
            } catch (BundleException) {
                self::assertFileDoesNotExist($holder->path('project.sqhb'));
            }
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    public function testModifiedKitOwnedFileBlocksReplacement(): void
    {
        $source = new TemporaryProject();
        $target = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $this->prepareSource($target);
            $target->write('Project/README.md', 'local edit');
            (new ActivationStore($target->path()))->writeKits([
                'Shop' => [
                    'enabled' => true, 'source_kind' => 'manual', 'source' => 'manual',
                    'definition' => [], 'published' => [
                        'Project/README.md' => [
                            'hash' => hash('sha256', 'application'), 'kind' => 'generated',
                        ],
                    ], 'requires' => [],
                ],
            ]);
            $bundle = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $bundle->export($archive);
            $plan = $bundle->planImport($archive, $target->path());
            self::assertTrue($plan->hasConflicts());
            self::assertStringContainsString('Modified owned file', implode(' ', $plan->conflicts));
            self::assertSame('local edit', file_get_contents($target->path('Project/README.md')));
        } finally {
            $holder->remove();
            $target->remove();
            $source->remove();
        }
    }

    public function testReviewedPlanBelongsOnlyToTheManagerThatCreatedIt(): void
    {
        $source = new TemporaryProject();
        $holder = new TemporaryProject();
        try {
            $this->prepareSource($source);
            $first = new ProjectBundle($source->path());
            $second = new ProjectBundle($source->path());
            $archive = $holder->path('project.sqhb');
            $first->export($archive);
            $target = $holder->path('Restored');
            $plan = $first->planImport($archive, $target);
            try {
                $second->apply($plan);
                self::fail('A plan must not be transferable between application managers.');
            } catch (BundleException) {
                self::assertFileDoesNotExist($target);
            }
            self::assertTrue($first->apply($plan)->complete());
            self::assertFileExists($target . '/Project/README.md');
        } finally {
            $holder->remove();
            $source->remove();
        }
    }

    private function prepareSource(TemporaryProject $project): void
    {
        self::assertTrue(@rmdir($project->path('config')));
        $project->write('Project/README.md', 'application');
        $project->write('composer.json', json_encode([
            'name' => 'squehub/bundle-test', 'require' => ['php' => '^8.2'],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertRejected(ProjectBundle $bundle, string $archive): void
    {
        try {
            $bundle->inspect($archive);
            self::fail('Invalid bundle was accepted.');
        } catch (BundleException $exception) {
            self::assertStringNotContainsString('trusted content', $exception->getMessage());
        }
    }

    /** @return array{manifest:array<string,mixed>,payload:string} */
    private function archiveParts(string $archive): array
    {
        $prefix = "SQUEHUB-BUNDLE/1\n";
        self::assertStringStartsWith($prefix, $archive);
        $length = hexdec(substr($archive, strlen($prefix), 8));
        $offset = strlen($prefix) + 9;
        return [
            'manifest' => json_decode(substr($archive, $offset, $length), true, 64, JSON_THROW_ON_ERROR),
            'payload' => substr($archive, $offset + $length),
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function repack(array $manifest, string $payload): string
    {
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return "SQUEHUB-BUNDLE/1\n" . sprintf('%08x', strlen($json)) . "\n" . $json . $payload;
    }

    /** @param array{manifest:array<string,mixed>,payload:string} $parts */
    private function payloadFile(array $parts, string $path): string
    {
        $offset = 0;
        foreach ($parts['manifest']['files'] as $file) {
            $bytes = substr($parts['payload'], $offset, $file['size']);
            if ($file['path'] === $path) { return $bytes; }
            $offset += $file['size'];
        }
        self::fail('Expected bundle payload file was missing.');
    }

    /**
     * Rebuild a crafted archive with internally consistent file metadata, so
     * inspection must catch a semantic registry/summary mismatch rather than
     * only a checksum failure.
     *
     * @param array{manifest:array<string,mixed>,payload:string} $parts
     * @return array{manifest:array<string,mixed>,payload:string}
     */
    private function replacePayloadFile(array $parts, string $path, string $replacement): array
    {
        $offset = 0;
        $payload = '';
        foreach ($parts['manifest']['files'] as &$file) {
            $bytes = substr($parts['payload'], $offset, $file['size']);
            $offset += $file['size'];
            if ($file['path'] === $path) {
                $bytes = $replacement;
                $file['size'] = strlen($bytes);
                $file['sha256'] = hash('sha256', $bytes);
            }
            $payload .= $bytes;
        }
        unset($file);
        return ['manifest' => $parts['manifest'], 'payload' => $payload];
    }
}
