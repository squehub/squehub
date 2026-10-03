<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Clis\PackageRemoval;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Package removal must preflight the entire subtree before deleting files. */
final class PackageRemovalTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = BASE_DIR . '/package-removal-' . bin2hex(random_bytes(5));
        mkdir($this->base . '/Project/Packages', 0777, true);
    }

    protected function tearDown(): void
    {
        $walk = static function (string $path) use (&$walk): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (new \DirectoryIterator($path) as $entry) if (!$entry->isDot()) $walk($entry->getPathname());
                rmdir($path);
            } elseif (file_exists($path) || is_link($path)) unlink($path);
        };
        $walk($this->base);
    }

    public function testRemovesOnlyNamedPackageTree(): void
    {
        $target = $this->base . '/Project/Packages/Example';
        mkdir($target . '/Routes', 0777, true);
        file_put_contents($target . '/Routes/Web.php', '<?php');
        $other = $this->base . '/Project/Packages/Other';
        mkdir($other);
        file_put_contents($other . '/keep.txt', 'keep');

        PackageRemoval::remove($target, $this->base);

        self::assertDirectoryDoesNotExist($target);
        self::assertSame('keep', file_get_contents($other . '/keep.txt'));
    }

    public function testRejectsDirectoryOutsidePackageRootWithoutDeletion(): void
    {
        $outside = $this->base . '/Outside';
        mkdir($outside);
        file_put_contents($outside . '/keep.txt', 'keep');
        try {
            PackageRemoval::remove($outside, $this->base);
            self::fail('Outside target must be rejected.');
        } catch (RuntimeException) {
            self::assertSame('keep', file_get_contents($outside . '/keep.txt'));
        }
    }

    public function testRejectsLinkedChildWithoutDeletingOutsideFile(): void
    {
        $target = $this->base . '/Project/Packages/Linked';
        mkdir($target);
        $outside = $this->base . '/keep.txt';
        file_put_contents($outside, 'keep');
        if (!@symlink($outside, $target . '/escape.txt')) {
            self::markTestSkipped('This host cannot create a filesystem symlink.');
        }

        try {
            PackageRemoval::remove($target, $this->base);
            self::fail('A linked Package child must block removal.');
        } catch (RuntimeException) {
            self::assertSame('keep', file_get_contents($outside));
            self::assertDirectoryExists($target);
        }
    }
}
