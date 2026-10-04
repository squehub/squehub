<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Contributions\ContributionOwner;
use App\Contributions\ContributionRegistry;
use App\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Contribution ownership is scoped to one Application and restored after nested work. */
final class ContributionRegistryTest extends TestCase
{
    public function testNestedOwnerAndExceptionRestoreThePreviousContext(): void
    {
        $registry = new ContributionRegistry();
        $application = new ContributionOwner('application', 'Project');
        $weather = new ContributionOwner('package', 'Weather');

        $registry->withOwner($application, function () use ($registry, $weather): void {
            $registry->record('route', 'GET /', metadata: ['method' => 'GET', 'path' => '/']);

            try {
                $registry->withOwner($weather, function () use ($registry): void {
                    $registry->record('route', 'GET /forecast');
                    throw new RuntimeException('fixture failure');
                }, 'Project/Packages/Weather/Routes/web.php');
                self::fail('The fixture exception should propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame('fixture failure', $exception->getMessage());
            }

            self::assertSame('application:Project', $registry->currentOwner()?->key());
            $registry->record('route', 'GET /about');
        }, 'Project/Routes/web.php');

        self::assertNull($registry->currentOwner());
        self::assertSame('package:Weather', $registry->ownerOf('route', 'GET /forecast')?->key());
        self::assertSame('application:Project', $registry->ownerOf('route', 'GET /about')?->key());
        self::assertSame('Project/Packages/Weather/Routes/web.php',
            $registry->byOwner($weather)[0]->source);
        self::assertSame('Project/Routes/web.php',
            $registry->byOwner($application)[0]->source);
    }

    public function testRecordsAreDeterministicAndContainOnlyAllowlistedMetadata(): void
    {
        $registry = new ContributionRegistry();
        $owner = new ContributionOwner('kit', 'FutureKit');
        $registry->withOwner($owner, function () use ($registry): void {
            $registry->record('service', 'Weather.Service', metadata: ['binding' => 'singleton']);
            $registry->record('route', 'GET /weather', metadata: ['path' => '/weather', 'method' => 'GET']);
        });

        $items = array_map(static fn ($item): array => $item->toArray(), $registry->all());
        self::assertSame(['route', 'service'], array_column($items, 'type'));
        self::assertSame(['method' => 'GET', 'path' => '/weather'], $items[0]['metadata']);
        self::assertSame(['type' => 'kit', 'name' => 'FutureKit'], $items[0]['owner']);
        self::assertSame($items, array_map(static fn ($item): array => $item->toArray(), $registry->all()));
        self::assertCount(1, $registry->byType('service'));

        try {
            $registry->withOwner($owner, static fn () => $registry->record('config',
                'packages.Weather.token', metadata: ['value' => 'PLANTED_SECRET_DO_NOT_PERSIST']));
            self::fail('Configuration values must not enter provenance metadata.');
        } catch (InvalidArgumentException) {
            self::assertStringNotContainsString('PLANTED_SECRET_DO_NOT_PERSIST',
                json_encode($items, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnsafeSourcePathsAreRejected(): void
    {
        $registry = new ContributionRegistry();
        $this->expectException(InvalidArgumentException::class);
        $registry->beginOwner(new ContributionOwner('package', 'Weather'),
            'D:/private/project/Weather.php');
    }

    public function testRouteHostAndFallbackMetadataRemainStructural(): void
    {
        $registry = new ContributionRegistry();
        $registry->withOwner(new ContributionOwner('application', 'Project'),
            static function () use ($registry): void {
                $registry->record('route', 'GET admin.example.test /admin', metadata: [
                    'method' => 'GET', 'path' => '/admin',
                    'host' => 'admin.example.test', 'fallback' => true,
                ]);
            });

        self::assertSame([
            'fallback' => true, 'host' => 'admin.example.test',
            'method' => 'GET', 'path' => '/admin',
        ], $registry->all()[0]->metadata);
    }

    public function testSeparateApplicationsDoNotShareOwnersOrRecords(): void
    {
        $project = new TemporaryProject();
        try {
            $first = new Application($project->path());
            $second = new Application($project->path());
            $first->contributions()->withOwner(new ContributionOwner('package', 'Weather'),
                static function () use ($first): void {
                    $first->contributions()->record('service', 'Weather.Forecast');
                });

            self::assertCount(1, $first->contributions()->all());
            self::assertSame([], $second->contributions()->all());
            self::assertNull($second->contributions()->currentOwner());
        } finally {
            $project->remove();
        }
    }
}
