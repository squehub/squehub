<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Storage\Drivers\ArrayStorageDriver;
use App\Storage\Drivers\LocalStorageDriver;
use App\Storage\StorageDriver;
use App\Storage\StorageException;
use App\Storage\StoragePath;
use App\Database\ModelClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Shared contract checks for byte, directory, and path behavior. */
final class StorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = BASE_DIR . '/storage-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) return;
        $walk = static function (string $path) use (&$walk): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (new \DirectoryIterator($path) as $entry) if (!$entry->isDot()) $walk($entry->getPathname());
                rmdir($path);
            } else unlink($path);
        };
        $walk($this->root);
    }

    /** @dataProvider drivers */
    public function testBinaryBytesDirectoriesAndLists(string $kind): void
    {
        $drive = $this->driver($kind);
        self::assertFalse($drive->exists('nested'));
        $drive->write('nested/é.txt', "\0\xff\n");
        $drive->write('nested/.hidden', 'hidden');
        $drive->write('nested/a.txt', 'alpha');
        self::assertSame("\0\xff\n", $drive->read('nested/é.txt'));
        self::assertSame(3, $drive->size('nested/é.txt'));
        self::assertTrue($drive->exists('nested'));
        self::assertSame(['nested/.hidden', 'nested/a.txt', 'nested/é.txt'], $drive->files('nested'));
        self::assertSame(['nested'], $drive->directories());
        $drive->write('empty', '');
        self::assertSame('', $drive->read('empty'));
        self::assertSame(0, $drive->size('empty'));
        $drive->makeDirectory('nested/empty/sub');
        self::assertSame(['nested/empty', 'nested/empty/sub'], $drive->directories('nested', true));
        self::assertSame(['empty', 'nested/.hidden', 'nested/a.txt', 'nested/é.txt'], $drive->files('', true));
        self::assertFalse($drive->remove('missing'));
        self::assertTrue($drive->remove('empty'));
        $drive->removeDirectory('nested', true);
        self::assertFalse($drive->exists('nested'));
    }

    /** @dataProvider drivers */
    public function testOverwriteCopyMoveAndCollision(string $kind): void
    {
        $drive = $this->driver($kind);
        $drive->write('a', 'one');
        $drive->write('a', 'two');
        self::assertSame('two', $drive->read('a'));
        $drive->write('a', 'longer value');
        self::assertSame(12, $drive->size('a'));
        $drive->write('a', 'two');
        self::assertSame(3, $drive->size('a'));
        $drive->copy('a', 'b/c');
        self::assertSame('two', $drive->read('b/c'));
        try {
            $drive->copy('a', 'b/c');
            self::fail('Existing destination must be rejected.');
        } catch (StorageException) {}
        $drive->copy('a', 'b/c', true);
        $drive->move('a', 'b/d');
        self::assertFalse($drive->exists('a'));
        self::assertSame('two', $drive->read('b/d'));
        $drive->write('b/e', 'old');
        $drive->move('b/d', 'b/e', true);
        self::assertSame('two', $drive->read('b/e'));
        self::assertFalse($drive->exists('b/d'));
    }

    /** @dataProvider drivers */
    public function testStreamsMetadataAndDirectoryMisuse(string $kind): void
    {
        $drive = $this->driver($kind);
        $input = fopen('php://temp', 'w+b');
        fwrite($input, 'ABCDEF');
        fseek($input, 3);
        $drive->writeStream('data/file', $input);
        self::assertTrue(is_resource($input));
        fclose($input);
        self::assertSame('DEF', $drive->read('data/file'));
        $output = $drive->readStream('data/file');
        self::assertSame(0, ftell($output));
        self::assertSame('DEF', stream_get_contents($output));
        fclose($output);
        self::assertSame(3, $drive->size('data/file'));
        self::assertSame('UTC', $drive->modifiedAt('data/file')->getTimezone()->getName());
        self::assertTrue($drive->mimeType('data/file') === null || is_string($drive->mimeType('data/file')));
        $this->expectException(StorageException::class);
        $drive->read('data');
    }

    /** @dataProvider drivers */
    public function testNonemptyDirectoryAndRootDeletionRejected(string $kind): void
    {
        $drive = $this->driver($kind);
        $drive->write('dir/file', 'x');
        try {
            $drive->removeDirectory('dir');
            self::fail('Nonempty directory must be rejected.');
        } catch (StorageException) {}
        self::assertSame('x', $drive->read('dir/file'));
        $this->expectException(StorageException::class);
        $drive->removeDirectory('', true);
    }

    public function testPathPortabilityValidation(): void
    {
        foreach (['/absolute', 'a\\b', 'a/../b', 'a/./b', 'a//b', "a\0b", "a\nb",
            'C:/windows', 'http://host', 'CON', 'con.txt', 'a/LPT1.txt', 'a. ', 'a.',
            '.squehub/locks', 'a/.squehub/file', str_repeat('x', 256),
            implode('/', array_fill(0, 18, str_repeat('x', 250)))] as $path) {
            try {
                StoragePath::validate($path);
                self::fail('Unsafe logical path accepted.');
            } catch (StorageException) {}
        }
        self::assertSame('é/文件.txt', StoragePath::validate('é/文件.txt'));
    }

    /** @dataProvider drivers */
    public function testMissingSourceAndPrivatePathFailures(string $kind): void
    {
        $drive = $this->driver($kind);
        foreach (['read', 'size', 'modifiedAt', 'mimeType', 'readStream'] as $method) {
            try {
                $drive->{$method}('customer-secret-name');
                self::fail('Missing file must fail: ' . $method);
            } catch (StorageException $error) {
                self::assertStringNotContainsString('customer-secret-name', $error->getMessage());
                self::assertStringNotContainsString($this->root, $error->getMessage());
            }
        }
        foreach (['copy', 'move'] as $method) {
            try {
                $drive->{$method}('missing', 'destination');
                self::fail('Missing source must fail: ' . $method);
            } catch (StorageException) {
                self::assertFalse($drive->exists('destination'));
            }
        }
    }

    public function testArrayModifiedTimeUsesInjectedClock(): void
    {
        $clock = new class implements ModelClock {
            public DateTimeImmutable $time;
            public function now(): DateTimeImmutable { return $this->time; }
        };
        $clock->time = new DateTimeImmutable('2026-01-01T00:00:00+02:00');
        $drive = new ArrayStorageDriver($clock);
        $drive->write('a', 'x');
        self::assertSame('2025-12-31 22:00:00', $drive->modifiedAt('a')->format('Y-m-d H:i:s'));
        $clock->time = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $drive->copy('a', 'b');
        self::assertSame('2026-01-02 00:00:00', $drive->modifiedAt('b')->format('Y-m-d H:i:s'));
        self::assertSame('2025-12-31 22:00:00', $drive->modifiedAt('a')->format('Y-m-d H:i:s'));
    }

    public function testLocalRootIsLazyAndMetadataHidden(): void
    {
        $drive = $this->driver('local');
        self::assertDirectoryDoesNotExist($this->root);
        $drive->write('a', 'x');
        self::assertSame(['a'], $drive->files());
        self::assertSame([], $drive->directories());
        self::assertDirectoryExists($this->root . '/.squehub/locks');
    }

    public function testTraversalIsRejectedBeforeCreatingLocalRoot(): void
    {
        $drive = $this->driver('local');
        $outside = dirname($this->root) . '/escape-' . bin2hex(random_bytes(6));
        try {
            $drive->write('../' . basename($outside), 'forbidden');
            self::fail('Traversal must be rejected.');
        } catch (StorageException) {
            self::assertDirectoryDoesNotExist($this->root);
            self::assertFileDoesNotExist($outside);
        }
    }

    public function testLocalStreamCopiesLargeInputFromCurrentCursor(): void
    {
        $drive = $this->driver('local');
        $input = fopen('php://temp', 'w+b');
        $payload = str_repeat("\0\xffABC\n", 200000);
        fwrite($input, 'skip' . $payload);
        fseek($input, 4);
        try {
            $drive->writeStream('large/file.bin', $input);
            self::assertTrue(is_resource($input));
        } finally {
            fclose($input);
        }
        self::assertSame(strlen($payload), $drive->size('large/file.bin'));
        self::assertSame(hash('sha256', $payload), hash('sha256', $drive->read('large/file.bin')));
    }

    /** @dataProvider drivers */
    public function testInvalidStreamDoesNotReplaceExistingData(string $kind): void
    {
        $drive = $this->driver($kind);
        $drive->write('file', 'original');
        $closed = fopen('php://temp', 'w+b');
        fclose($closed);
        try {
            $drive->writeStream('file', $closed);
            self::fail('Closed stream must fail.');
        } catch (StorageException) {}
        self::assertSame('original', $drive->read('file'));
        $inputPath = BASE_DIR . '/write-only-' . bin2hex(random_bytes(6));
        $writeOnly = fopen($inputPath, 'wb');
        try {
            $drive->writeStream('file', $writeOnly);
            self::fail('Write-only stream must fail.');
        } catch (StorageException) {
            self::assertSame('original', $drive->read('file'));
        } finally {
            fclose($writeOnly);
            unlink($inputPath);
        }
    }

    public function testLocalWriteWaitsForCooperatingNamespaceLock(): void
    {
        $drive = $this->driver('local');
        $drive->write('file', 'old');
        $lock = fopen($this->root . '/.squehub/locks/global.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX));
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'echo "ready\\n"; fflush(STDOUT);'
            . '(new \\App\\Storage\\Drivers\\LocalStorageDriver(' . var_export($this->root, true) . '))->write("file", "new-complete-value");'
            . 'echo "done\\n";';
        $process = new Process([PHP_BINARY, '-r', $code]);
        try {
            $process->start();
            $deadline = microtime(true) + 5;
            while (!str_contains($process->getOutput(), 'ready') && microtime(true) < $deadline) usleep(10000);
            self::assertStringContainsString('ready', $process->getOutput());
            self::assertTrue($process->isRunning());
            self::assertSame('old', file_get_contents($this->root . '/file'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $process->wait();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('new-complete-value', $drive->read('file'));
        self::assertSame(['file'], $drive->files());
    }

    public function testLocalRootFileFails(): void
    {
        file_put_contents($this->root, 'file');
        try {
            $this->expectException(StorageException::class);
            (new LocalStorageDriver($this->root))->exists('a');
        } finally {
            unlink($this->root);
        }
    }

    public function testLocalSymlinkIsRejectedWhenHostPermitsIt(): void
    {
        mkdir($this->root);
        $outside = BASE_DIR . '/outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'secret');
        $linked = @symlink($outside, $this->root . '/link');
        try {
            if (!$linked) $this->markTestSkipped('Host cannot create file symlinks.');
            $drive = new LocalStorageDriver($this->root);
            $readRejected = false;
            try {
                $drive->read('link');
            } catch (StorageException) {
                $readRejected = true;
            }
            self::assertTrue($readRejected, 'Reading a symlink must be rejected.');
            $listingRejected = false;
            try {
                $drive->files();
            } catch (StorageException) {
                $listingRejected = true;
            }
            self::assertTrue($listingRejected, 'Listing a symlink must be rejected.');
        } finally {
            if ($linked) unlink($this->root . '/link');
            unlink($outside);
        }
    }

    public static function drivers(): array
    {
        return [['array'], ['local']];
    }

    private function driver(string $kind): StorageDriver
    {
        return $kind === 'array' ? new ArrayStorageDriver() : new LocalStorageDriver($this->root);
    }
}
