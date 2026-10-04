<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Storage\Drivers\S3StorageDriver;
use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Storage\StorageDrive;
use App\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\FakeS3ObjectClient;

require_once dirname(__DIR__) . '/Fixtures/FakeS3ObjectClient.php';

final class S3StorageDriverTest extends TestCase
{
    public function testFilesDirectoriesMetadataAndScopedRecursiveDeletion(): void
    {
        $client = new FakeS3ObjectClient();
        $client->write('other/private.txt', 'untouched');
        $drive = new S3StorageDriver($client, 'applications/example/');
        self::assertFalse($drive->exists('docs'));
        $drive->write('docs/z.txt', 'z');
        $drive->write('docs/a.txt', 'alpha');
        $drive->write('docs/nested/file.bin', "\0\xff");
        $drive->makeDirectory('docs/empty/sub');
        self::assertSame('alpha', $drive->read('docs/a.txt'));
        self::assertSame(5, $drive->size('docs/a.txt'));
        self::assertSame('application/octet-stream', $drive->mimeType('docs/a.txt'));
        self::assertSame('UTC', $drive->modifiedAt('docs/a.txt')->getTimezone()->getName());
        self::assertTrue($drive->exists('docs'));
        self::assertTrue($drive->exists('docs/empty'));
        self::assertSame(['docs/a.txt', 'docs/z.txt'], $drive->files('docs'));
        self::assertSame(['docs/a.txt', 'docs/nested/file.bin', 'docs/z.txt'], $drive->files('docs', true));
        self::assertSame(['docs/empty', 'docs/nested'], $drive->directories('docs'));
        self::assertSame(['docs/empty', 'docs/empty/sub', 'docs/nested'], $drive->directories('docs', true));
        self::assertSame(['docs'], $drive->directories());
        try {
            $drive->removeDirectory('docs');
            self::fail('Nonempty virtual directory must be rejected.');
        } catch (StorageException) {}
        $drive->removeDirectory('docs', true);
        self::assertFalse($drive->exists('docs'));
        self::assertSame('untouched', $client->read('other/private.txt'));
        foreach ($client->listedPrefixes as $prefix) {
            self::assertStringStartsWith('applications/example/', $prefix);
        }
    }

    public function testCopyMoveOverwriteAndMissingResults(): void
    {
        $client = new FakeS3ObjectClient();
        $drive = new S3StorageDriver($client, 'root');
        $drive->write('a', 'one');
        $drive->copy('a', 'b/c');
        self::assertSame('one', $drive->read('b/c'));
        try {
            $drive->copy('a', 'b/c');
            self::fail('Copy must reject an existing destination.');
        } catch (StorageException) {}
        $drive->write('a', 'two');
        $drive->copy('a', 'b/c', true);
        self::assertSame('two', $drive->read('b/c'));
        $drive->move('a', 'b/d');
        self::assertFalse($drive->exists('a'));
        self::assertSame('two', $drive->read('b/d'));
        self::assertFalse($drive->remove('absent'));
        self::assertTrue($drive->remove('b/c'));
        $drive->makeDirectory('empty');
        $drive->removeDirectory('empty');
        self::assertFalse($drive->exists('empty'));
        $this->expectException(StorageException::class);
        $drive->copy('missing', 'new');
    }

    public function testStreamsStartAtCurrentCursorAndRemainCallerOwned(): void
    {
        $client = new FakeS3ObjectClient();
        $drive = new S3StorageDriver($client, 'run/');
        $input = fopen('php://temp', 'w+b');
        fwrite($input, 'skip' . str_repeat("\0A", 100000));
        fseek($input, 4);
        try {
            $drive->writeStream('media/large.bin', $input);
            self::assertTrue(is_resource($input));
            self::assertSame(200000, $drive->streamWriteSize());
            self::assertSame(200000, $drive->size('media/large.bin'));
        } finally { fclose($input); }
        $output = $drive->readStream('media/large.bin');
        try {
            self::assertSame(0, ftell($output));
            self::assertSame(str_repeat("\0A", 100000), stream_get_contents($output));
        } finally { fclose($output); }
    }

    public function testUnsafeKeysAndProviderFailuresStayPrivate(): void
    {
        $client = new FakeS3ObjectClient();
        $drive = new S3StorageDriver($client, 'private/');
        foreach (['../escape', '/absolute', 'a//b', "a\nb", 'C:/drive', "invalid-\xff"] as $path) {
            try {
                $drive->write($path, 'secret-content');
                self::fail('Unsafe logical path accepted.');
            } catch (StorageException) {}
        }
        self::assertSame([], $client->objects);
        $client->failOperation = 'head';
        $client->failKey = 'private/customer-secret-name';
        $client->failReason = 'permission_denied';
        try {
            $drive->size('customer-secret-name');
            self::fail('Provider denial must throw.');
        } catch (StorageException $error) {
            self::assertStringContainsString('permission_denied', $error->getMessage());
            self::assertStringNotContainsString('customer-secret-name', $error->getMessage());
            self::assertStringNotContainsString('private/', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        $this->expectException(StorageException::class);
        new S3StorageDriver($client, '../escape/');
    }

    public function testForeignKeysAndFileDirectoryConflicts(): void
    {
        $client = new FakeS3ObjectClient();
        $drive = new S3StorageDriver($client, 'scope/');
        $client->write('scope/unsafe/../name', 'foreign');
        $client->write("scope/invalid-\xff", 'foreign');
        $drive->write('safe/file', 'value');
        self::assertSame(['safe/file'], $drive->files('', true));
        $drive->write('parent', 'file');
        try {
            $drive->write('parent/child', 'bad');
            self::fail('A file cannot be a logical parent.');
        } catch (StorageException) {}
        try {
            $drive->write('safe', 'bad');
            self::fail('A virtual directory cannot be replaced with a file.');
        } catch (StorageException) {}
        try {
            $drive->remove('safe');
            self::fail('File removal cannot delete a directory.');
        } catch (StorageException) {}
        self::assertSame('value', $drive->read('safe/file'));
    }

    public function testPaginatedListingAndExplicitDirectoryMarker(): void
    {
        $client = new FakeS3ObjectClient();
        for ($i = 0; $i < 1003; ++$i) {
            $client->write('batch/folder/' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'x');
        }
        $drive = new S3StorageDriver($client, 'batch/');
        self::assertCount(1003, $drive->files('folder'));
        self::assertSame(['folder'], $drive->directories());
        $drive->makeDirectory('folder');
        foreach (array_keys($client->objects) as $key) {
            if (str_starts_with($key, 'batch/folder/') && $key !== 'batch/folder/') unset($client->objects[$key]);
        }
        self::assertTrue($drive->exists('folder'));
        self::assertSame([], $drive->files('folder'));
        $drive->removeDirectory('folder');
        self::assertFalse($drive->exists('folder'));
    }

    public function testStreamDiagnosticsDoNotIssuePostCommitHeadOrRetainPath(): void
    {
        $client = new FakeS3ObjectClient();
        $client->failOperation = 'head';
        $client->failKey = 'private/customer-secret-name';
        $diagnostics = new Diagnostics(new Repository([]));
        $diagnostics->begin(new Request('GET', '/'));
        $drive = new StorageDrive(new S3StorageDriver($client, 'private/'), $diagnostics);
        $input = fopen('php://temp', 'w+b');
        fwrite($input, 'skipsecret');
        fseek($input, 4);
        try { $drive->writeStream('customer-secret-name', $input); }
        finally { fclose($input); }
        self::assertSame('secret', $client->read('private/customer-secret-name'));
        $snapshot = $diagnostics->snapshot();
        self::assertSame(6, $snapshot['storage']['bytes_written']);
        self::assertStringNotContainsString('customer-secret-name', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private/', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
