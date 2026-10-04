<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\Storage\StorageException;
use App\Storage\StorageManager;
use App\Storage\StorageServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class S3StorageIntegrationTest extends TestCase
{
    public function testOptionalS3DriveDoesNotAffectLocalBootAndSelectionFailsClearlyWithoutSdk(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/Storage.php', '<?php return ["default" => "local", "drives" => ['
                . '"local" => ["driver" => "array"], "remote" => ["driver" => "s3", '
                . '"bucket" => "test-bucket", "region" => "us-east-1", "prefix" => "private/", '
                . '"access_key" => "PRIVATE_ACCESS_KEY", "secret_key" => "PRIVATE_SECRET"]]];');
            $app = new Application($project->path());
            $app->register(StorageServiceProvider::class);
            $app->bootstrap();
            $manager = $app->container()->make(StorageManager::class);
            $manager->write('ordinary', 'works');
            self::assertSame('works', $manager->read('ordinary'));
            if (!class_exists(\Aws\S3\S3Client::class)) {
                try {
                    $manager->drive('remote');
                    self::fail('Selecting S3 without its optional SDK must fail.');
                } catch (StorageException $error) {
                    self::assertStringContainsString('aws/aws-sdk-php', $error->getMessage());
                    self::assertStringNotContainsString('PRIVATE_ACCESS_KEY', $error->getMessage());
                    self::assertStringNotContainsString('PRIVATE_SECRET', $error->getMessage());
                    self::assertNull($error->getPrevious());
                }
            } else {
                // Client construction is still offline; no request is made.
                self::assertSame($manager->drive('remote'), $manager->drive('remote'));
            }
        } finally { $project->remove(); }
    }
}
