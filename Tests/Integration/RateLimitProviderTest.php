<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\RateLimit\RateLimit;
use App\RateLimit\RateLimiter;
use App\RateLimit\RateLimitServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Provider boot remains independent of HTTP and all application pillars. */
final class RateLimitProviderTest extends TestCase
{
    public function testFileStoreIsLazyAndWorksWithoutOtherProviders(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            $app->register(RateLimitServiceProvider::class);
            $app->bootstrap();
            $root = $project->path('Storage/RateLimits');
            self::assertDirectoryDoesNotExist($root);
            $limiter = $app->container()->make(RateLimiter::class);
            self::assertDirectoryDoesNotExist($root);
            self::assertTrue($limiter->consume('job', 'one', 1, 60)->allowed());
            self::assertDirectoryExists($root);
        } finally {
            RateLimit::setResolver(null);
            $project->remove();
        }
    }
}
