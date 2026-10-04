<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Clis\FileLookup;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class CliFileLookupTest extends TestCase
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

    public function testSeederLookupRequiresCanonicalFileAndRejectsTraversal(): void
    {
        $this->project->write('Database/Seeders/SampleSeeder.php', '<?php // fixture');

        self::assertSame(
            realpath($this->project->path('Database/Seeders/SampleSeeder.php')),
            FileLookup::seeder($this->project->path(), 'SampleSeeder')
        );
        self::assertNull(FileLookup::seeder($this->project->path(), 'sampleSeeder'));
        self::assertNull(FileLookup::seeder($this->project->path(), '../SampleSeeder'));
        self::assertNull(FileLookup::seeder($this->project->path(), 'MissingSeeder'));
    }

    public function testLinkedSeederDirectoryCannotEscapeApplicationRoot(): void
    {
        $outside = new TemporaryProject();
        $outside->write('Database/Seeders/EscapeSeeder.php', '<?php // external sentinel');
        $this->project->write('Database/Index.php', '<?php // canonical parent');
        $link = $this->project->path('Database/Seeders');
        if (!@symlink($outside->path('Database/Seeders'), $link)) {
            $outside->remove();
            self::markTestSkipped('Directory symlink creation is unavailable in this environment.');
        }

        try {
            self::assertNull(FileLookup::seederDirectory($this->project->path()));
            self::assertNull(FileLookup::seeder($this->project->path(), 'EscapeSeeder'));
            self::assertFileExists($outside->path('Database/Seeders/EscapeSeeder.php'));
        } finally {
            // Remove the link before either temporary tree is recursively removed.
            if (is_link($link) && !@unlink($link)) {
                @rmdir($link); // Windows may require rmdir() for a directory link.
            }
            $outside->remove();
        }
        self::assertFalse(is_link($link));
    }

    public function testMigrationLookupAcceptsInitialLetterVariantAndRejectsTraversal(): void
    {
        $this->project->write('Database/Migrations/create_users.php', '<?php // fixture');

        self::assertSame(
            realpath($this->project->path('Database/Migrations/create_users.php')),
            FileLookup::migration($this->project->path(), 'Create_users.php')
        );
        self::assertSame(
            realpath($this->project->path('Database/Migrations')),
            FileLookup::migrationDirectory($this->project->path())
        );
        self::assertTrue(FileLookup::sameFirstLetter('create_users.php', 'Create_users.php'));
        self::assertFalse(FileLookup::sameFirstLetter('create_users.php', 'Create_posts.php'));
        self::assertTrue(FileLookup::sameFirstLetter(
            '2025_07_12_create_users.php', '2025_07_12_Create_users.php'
        ));
        self::assertNull(FileLookup::migration($this->project->path(), '../create_users.php'));
        self::assertNull(FileLookup::migration($this->project->path(), "create_users.php\0"));
    }

    public function testTimestampedMigrationLookupAcceptsDescriptiveInitialVariant(): void
    {
        $this->project->write('Database/Migrations/2025_07_12_Create_users.php', '<?php // fixture');
        self::assertSame(
            realpath($this->project->path('Database/Migrations/2025_07_12_Create_users.php')),
            FileLookup::migration($this->project->path(), '2025_07_12_create_users.php')
        );
    }
}
