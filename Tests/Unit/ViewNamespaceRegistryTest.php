<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Contributions\ContributionOwner;
use App\Contributions\ContributionRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ViewNamespaceRegistryTest extends TestCase
{
    public function testClaimRetainsPackageOwnerAndIsExactlyAddressed(): void
    {
        $registry = new ContributionRegistry();
        $registry->record('view_namespace', 'Commerce',
            'Project/Packages/Commerce/Commerce.php', [],
            new ContributionOwner('package', 'Commerce'));

        self::assertSame('package:Commerce',
            $registry->ownerOf('view_namespace', 'Commerce')?->key());
        self::assertNull($registry->ownerOf('view_namespace', 'commerce'));
        self::assertCount(1, $registry->byType('view_namespace'));
    }

    public function testExactDuplicateClaimFailsDeterministically(): void
    {
        $registry = new ContributionRegistry();
        $owner = new ContributionOwner('package', 'Commerce');
        $registry->record('view_namespace', 'Commerce', null, [], $owner);
        $this->expectException(LogicException::class);
        $registry->record('view_namespace', 'Commerce', null, [], $owner);
    }

    public function testCaseFoldClaimFailsOnEveryPlatform(): void
    {
        $registry = new ContributionRegistry();
        $registry->record('view_namespace', 'Commerce', null, [],
            new ContributionOwner('package', 'Commerce'));
        $this->expectException(LogicException::class);
        $registry->record('view_namespace', 'COMMERCE', null, [],
            new ContributionOwner('package', 'COMMERCE'));
    }

    public function testClaimCannotBeAttributedToAnotherPackageOrApplication(): void
    {
        $registry = new ContributionRegistry();
        foreach ([
            new ContributionOwner('package', 'Accounting'),
            new ContributionOwner('application', 'Project'),
        ] as $owner) {
            try {
                $registry->record('view_namespace', 'Commerce', null, [], $owner);
                self::fail('A View namespace claim must match its active Package owner.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $registry->byType('view_namespace'));
            }
        }
    }
}
