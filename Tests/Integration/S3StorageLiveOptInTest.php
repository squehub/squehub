<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Storage\Drivers\S3StorageDriver;
use App\Storage\Providers\AwsS3ObjectClient;
use PHPUnit\Framework\TestCase;

/** @group s3 */
final class S3StorageLiveOptInTest extends TestCase
{
    public function testS3CompatibleContractWithinUniqueOwnedPrefix(): void
    {
        if (getenv('SQUEHUB_TEST_S3_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_S3_ENABLED=1 and explicit disposable S3 settings.');
        }
        self::assertTrue(class_exists(\Aws\S3\S3Client::class), 'Install optional aws/aws-sdk-php for live S3 qualification.');
        $base = rtrim($this->required('SQUEHUB_TEST_S3_PREFIX'), '/') . '/';
        $run = 'squehub-phase20-' . bin2hex(random_bytes(12));
        $prefixA = $base . $run . '/a/';
        $prefixB = $base . $run . '/b/';
        $settings = [
            'bucket' => $this->required('SQUEHUB_TEST_S3_BUCKET'),
            'region' => $this->required('SQUEHUB_TEST_S3_REGION'),
            'endpoint' => $this->required('SQUEHUB_TEST_S3_ENDPOINT'),
            'access_key' => $this->required('SQUEHUB_TEST_S3_ACCESS_KEY'),
            'secret_key' => $this->required('SQUEHUB_TEST_S3_SECRET_KEY'),
            'session_token' => getenv('SQUEHUB_TEST_S3_SESSION_TOKEN') ?: null,
            'path_style' => getenv('SQUEHUB_TEST_S3_PATH_STYLE') ?: false,
        ];
        $a = new S3StorageDriver(new AwsS3ObjectClient($settings), $prefixA);
        $b = new S3StorageDriver(new AwsS3ObjectClient($settings), $prefixB);
        try {
            $a->write('case/a.txt', 'first');
            $a->write('case/large.bin', str_repeat('z', 8192));
            self::assertSame('first', $a->read('case/a.txt'));
            self::assertTrue($a->exists('case'));
            self::assertFalse($b->exists('case'));
            self::assertSame(5, $a->size('case/a.txt'));
            self::assertSame('UTC', $a->modifiedAt('case/a.txt')->getTimezone()->getName());
            self::assertSame(['case/a.txt', 'case/large.bin'], $a->files('case'));
            $input = fopen('php://temp', 'w+b');
            fwrite($input, 'skip-stream-payload');
            fseek($input, 5);
            try {
                $a->writeStream('case/stream.txt', $input);
                self::assertTrue(is_resource($input));
            } finally { fclose($input); }
            $output = $a->readStream('case/stream.txt');
            try { self::assertSame('stream-payload', stream_get_contents($output)); }
            finally { fclose($output); }
            $a->copy('case/a.txt', 'case/copy.txt');
            $a->move('case/copy.txt', 'case/moved.txt');
            self::assertFalse($a->exists('case/copy.txt'));
            self::assertSame('first', $a->read('case/moved.txt'));
            self::assertTrue($a->remove('case/moved.txt'));
            $b->write('case/sentinel.txt', 'sibling');
            self::assertSame('sibling', $b->read('case/sentinel.txt'));
            $a->removeDirectory('case', true);
            self::assertFalse($a->exists('case'));
            self::assertSame('sibling', $b->read('case/sentinel.txt'));
        } finally {
            // Each drive can delete only its unique run subtree; no bucket-wide
            // listing, emptying, or cleanup command is ever used.
            if ($a->exists('case')) $a->removeDirectory('case', true);
            if ($b->exists('case')) $b->removeDirectory('case', true);
        }
    }

    private function required(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, 'Set ' . $name . ' explicitly.');
        self::assertNotSame('', $value, 'Set ' . $name . ' explicitly.');
        return $value;
    }
}
