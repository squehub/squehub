<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Auth\PasswordHasher;
use App\Authorization\AuthorizationConfigurationException;
use App\Authorization\AuthorizationManager;
use App\Authorization\Rbac\RbacException;
use App\Authorization\Rbac\RbacIdentity;
use App\Authorization\Rbac\RbacManager;
use App\Authorization\Rbac\Repositories\ArrayRbacRepository;
use App\Config\Repository;
use App\Container\Container;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/** Array-backed RBAC and its single Authorization decision path. */
final class RbacTest extends TestCase
{
    private RbacManager $rbac;

    protected function setUp(): void
    {
        $this->rbac = new RbacManager(new ArrayRbacRepository(), true);
    }

    public function testRolesPermissionsMutationsAndSharedGrants(): void
    {
        $ada = new RbacIdentityFixture(7);
        $bea = new RbacIdentityFixture(8);
        $this->rbac->createRole('editor');
        $this->rbac->createRole('reporter');
        $this->rbac->createPermission('reports.view');
        $this->rbac->createRole('editor'); // Explicit definitions are idempotent.
        $this->rbac->grantPermission('editor', 'reports.view');
        $this->rbac->grantPermission('reporter', 'reports.view');
        $this->rbac->assignRole($ada, 'editor');
        $this->rbac->assignRole($ada, 'reporter');
        $this->rbac->assignRole($bea, 'reporter');
        self::assertTrue($this->rbac->hasPermission($ada, 'reports.view'));
        self::assertTrue($this->rbac->hasPermission($bea, 'reports.view'));
        self::assertTrue($this->rbac->hasRole($ada, 'editor'));
        $this->rbac->removeRole($ada, 'editor');
        self::assertFalse($this->rbac->hasRole($ada, 'editor'));
        self::assertTrue($this->rbac->hasPermission($ada, 'reports.view'));
        $this->rbac->revokePermission('reporter', 'reports.view');
        self::assertFalse($this->rbac->hasPermission($ada, 'reports.view'));
        self::assertFalse($this->rbac->hasPermission($bea, 'reports.view'));
        $this->rbac->grantPermission('editor', 'reports.view');
        $this->rbac->assignRole($ada, 'editor');
        $this->rbac->deleteRole('editor');
        self::assertFalse($this->rbac->hasRole($ada, 'editor'));
        $this->rbac->deletePermission('reports.view');
        self::assertFalse($this->rbac->permissionExists('reports.view'));
    }

    public function testTypedIdentityAndClassArePartOfAssignmentScope(): void
    {
        $this->rbac->createRole('reviewer');
        $this->rbac->assignRole(new RbacIdentityFixture(7), 'reviewer');
        self::assertTrue($this->rbac->hasRole(new RbacIdentityFixture('7'), 'reviewer'));
        self::assertFalse($this->rbac->hasRole(new RbacIdentityFixture('007'), 'reviewer'));
        self::assertFalse($this->rbac->hasRole(new OtherRbacIdentityFixture(7), 'reviewer'));
        self::assertSame(RbacIdentity::from(new RbacIdentityFixture(7))->digest,
            RbacIdentity::from(new RbacIdentityFixture('7'))->digest);
        $this->rbac->removeIdentity(new RbacIdentityFixture(7));
        self::assertFalse($this->rbac->hasRole(new RbacIdentityFixture(7), 'reviewer'));
    }

    public function testUnboundPermissionComposesWithExplicitAbilitiesAndPolicies(): void
    {
        $config = new Repository(['auth' => ['default' => null, 'guards' => [], 'identities' => []]]);
        $container = new Container();
        $container->instance(RbacManager::class, $this->rbac);
        $authorization = new AuthorizationManager(
            new AuthManager($config, new SessionManager($config), new PasswordHasher($config)),
            $container, null, $this->rbac
        );
        $ada = new RbacIdentityFixture(7);
        $this->rbac->createRole('editor');
        $this->rbac->createPermission('reports.view');
        $this->rbac->grantPermission('editor', 'reports.view');
        self::assertFalse($authorization->forIdentity($ada)->allows('reports.view'));
        $this->rbac->assignRole($ada, 'editor');
        self::assertTrue($authorization->forIdentity($ada)->allows('reports.view'));
        $authorization->define('reports.view', static fn (RbacIdentityFixture $identity): bool => false);
        self::assertFalse($authorization->forIdentity($ada)->allows('reports.view'),
            'An explicit denial must not be overridden by a role.');
        $authorization->policy(RbacDocument::class, RbacDocumentPolicy::class);
        self::assertFalse($authorization->forIdentity($ada)->allows('update', new RbacDocument(false)));
        self::assertTrue($authorization->forIdentity($ada)->allows('update', new RbacDocument(true)));
        self::assertFalse($authorization->forIdentity(null)->allows('reports.view'));
        $this->expectException(AuthorizationConfigurationException::class);
        $authorization->forIdentity($ada)->allows('unknown.permission');
    }

    public function testDisabledRbacAndUnregisteredMutationsFailClosed(): void
    {
        $disabled = new RbacManager(new ArrayRbacRepository(), false);
        self::assertFalse($disabled->permissionExists('reports.view'));
        self::assertFalse($disabled->hasRole(new RbacIdentityFixture(7), 'editor'));
        try {
            $disabled->createRole('editor');
            self::fail('Disabled RBAC must not mutate state.');
        } catch (RbacException) {
        }
        $this->rbac->createRole('editor');
        $this->expectException(RbacException::class);
        $this->rbac->grantPermission('editor', 'unknown.permission');
    }
}

final readonly class RbacIdentityFixture implements Authenticatable
{
    public function __construct(private int|string $id) {}
    public function authIdentifier(): int|string { return $this->id; }
    public function authPasswordHash(): string { return 'unused'; }
}

final readonly class OtherRbacIdentityFixture implements Authenticatable
{
    public function __construct(private int|string $id) {}
    public function authIdentifier(): int|string { return $this->id; }
    public function authPasswordHash(): string { return 'unused'; }
}

final readonly class RbacDocument
{
    public function __construct(public bool $editable) {}
}

final class RbacDocumentPolicy
{
    public function __construct(private RbacManager $rbac) {}

    public function update(RbacIdentityFixture $identity, RbacDocument $document): bool
    {
        return $document->editable && $this->rbac->hasPermission($identity, 'reports.view');
    }
}
