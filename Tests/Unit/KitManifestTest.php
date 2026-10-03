<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Kits\KitException;
use App\Kits\KitManifest;
use App\Kits\KitName;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Invalid Kit metadata must be rejected before an entry class can execute. */
final class KitManifestTest extends TestCase
{
    public function testManifestClassifiesSupportedPublicationTargets(): void
    {
        self::assertSame('generated', KitManifest::targetKind('Store', 'Project/Routes/Store.php'));
        self::assertSame('migration', KitManifest::targetKind('Store',
            'Database/Migrations/CreateStore.php'));
        self::assertSame('seeder', KitManifest::targetKind('Store', 'Database/Seeders/StoreSeeder.php'));
        self::assertSame('config', KitManifest::targetKind('Store', 'Config/Store.php'));
        self::assertSame('asset', KitManifest::targetKind('Store',
            'public/assets/Kits/Store/store.css'));
        self::assertNull(KitManifest::targetKind('Store', 'public/assets/Kits/Other/store.css'));
        self::assertNull(KitManifest::targetKind('Store', '.env'));
    }

    public function testUnsafePathsAndNamesAreRejected(): void
    {
        foreach (['../Project/Routes/x.php', '/Project/Routes/x.php',
            'Project\\Routes\\x.php', 'Project/Routes/../x.php', 'Project/Routes/CON.php'] as $path) {
            try {
                KitManifest::requireRelative($path);
                self::fail('Unsafe path was accepted: ' . $path);
            } catch (KitException) {
                self::assertTrue(true);
            }
        }
        self::assertTrue(KitName::valid('Storefront'));
        self::assertFalse(KitName::valid('storefront'));
        self::assertFalse(KitName::valid('State'));
        self::assertFalse(KitName::valid('../Storefront'));
    }

    public function testStrictManifestRejectsUnknownFieldsDuplicateTargetsAndUnsupportedSources(): void
    {
        $project = new TemporaryProject();
        try {
            $invalid = [
                ['format' => 1, 'name' => 'Store', 'version' => '1.0.0', 'secret' => 'never accept'],
                ['format' => 2, 'name' => 'Store', 'version' => '1.0.0'],
                ['format' => 1, 'name' => 'store', 'version' => '1.0.0'],
                ['format' => 1, 'name' => 'Store', 'version' => 'latest'],
                ['format' => 1, 'name' => 'Store', 'version' => '1.0.0', 'requires' => ['Payments', 'payments']],
                ['format' => 1, 'name' => 'Store', 'version' => '1.0.0', 'hooks' => ['onRequest']],
                ['format' => 1, 'name' => 'Store', 'version' => '1.0.0', 'files' => [[
                    'source' => 'Templates/Route.php', 'target' => '.env',
                ]]],
                ['format' => 1, 'name' => 'Store', 'version' => '1.0.0', 'files' => [[
                    'source' => 'Templates/Route.php', 'target' => 'Config/Store.php',
                ]]],
                ['format' => 1, 'name' => 'Store', 'version' => '1.0.0', 'files' => [
                    ['source' => 'Templates/A.php', 'target' => 'Project/Routes/A.php'],
                    ['source' => 'Templates/B.php', 'target' => 'Project/Routes/a.php'],
                ]],
            ];
            foreach ($invalid as $index => $manifest) {
                $project->write('Store/kit.json', json_encode($manifest, JSON_THROW_ON_ERROR));
                try {
                    KitManifest::read($project->path('Store/kit.json'), 'Store');
                    self::fail('Invalid manifest was accepted at case ' . $index);
                } catch (KitException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            $project->remove();
        }
    }
}
