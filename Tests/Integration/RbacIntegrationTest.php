<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Authorization\AuthorizationManager;
use App\Authorization\Rbac\RbacManager;
use App\Authorization\Rbac\Repositories\DatabaseRbacRepository;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Plugins\Rbac;
use App\Plugins\TestCase;
use App\Support\RuntimeContext;
use App\Testing\TestApplication;
use PDO;
use RuntimeException;

/** SQLite persistence and the real route/View authorization path. */
final class RbacIntegrationTest extends TestCase
{
    private RbacHttpUser $ada;
    private RbacManager $rbac;
    private DatabaseManager $database;

    protected function testingConfig(): array
    {
        return [
            'rbac' => ['enabled' => true, 'driver' => 'database', 'connection' => null],
            'auth' => [
                'default' => 'web',
                'guards' => [
                    'web' => ['driver' => 'session', 'identity' => 'users'],
                    'api' => ['driver' => 'token', 'identity' => 'users', 'repository' => 'array'],
                ],
                'identities' => ['users' => ['driver' => 'model', 'model' => RbacHttpUser::class,
                    'identifier' => 'id', 'password' => 'password', 'credentials' => ['email']]],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                    'rehash_on_login' => false, 'max_bytes' => 4096],
                'browser' => ['login_path' => null, 'authenticated_path' => null],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for RBAC integration.');
        }
        $this->testApplication()->write('Project/Views/Rbac/Permissions.squehub.php', <<<'VIEW'
@can('reports.view')<report>yes</report>@else<report>no</report>@endcan
@cannot('reports.view')<cannot>yes</cannot>@else<cannot>no</cannot>@endcannot
@can('update', $article)<article>yes</article>@else<article>no</article>@endcan
VIEW);
        $this->testApplication()->write('Project/Routes/Web.php', <<<'PHP'
<?php
\App\Routing\Route::path('/reports')->get(static fn (): string => 'report')
    ->through(['auth', \App\Plugins\RequireAbility::named('reports.view')]);
\App\Routing\Route::path('/token-reports')->get(static fn (): string => 'token report')
    ->through([\App\Plugins\RequireToken::guard('api'),
        \App\Plugins\RequireTokenAbility::named('reports.view'),
        \App\Plugins\RequireAbility::named('reports.view')]);
\App\Routing\Route::path('/unknown')->get(static fn (): string => 'never')
    ->through(\App\Plugins\RequireAbility::named('unknown.permission'));
\App\Routing\Route::path('/permissions')->get(static function (): void {
    \App\Core\View::render('Rbac.Permissions', [
        'article' => new \SqueHub\Tests\Integration\RbacHttpArticle('Ada'),
    ]);
});
\App\Routing\Route::path('/article')->get(static function (): string {
    \authorize()->require('update', new \SqueHub\Tests\Integration\RbacHttpArticle('Ada'));
    return 'updated';
});
PHP);
        $this->database = $this->app()->container()->make(DatabaseManager::class);
        $this->database->schema()->create('rbac_http_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->string('password');
        });
        require_once dirname(__DIR__, 2) . '/Database/Migrations/2026_09_30_create_rbac_tables.php';
        (new \CreateRbacTables())->up($this->database->connection()->pdo(), $this->database->schema());
        $this->rbac = $this->app()->container()->make(RbacManager::class);
        $this->app()->container()->make(AuthorizationManager::class)
            ->policy(RbacHttpArticle::class, RbacHttpArticlePolicy::class);
        $this->ada = RbacHttpUser::create(['email' => 'ada@example.test', 'name' => 'Ada',
            'password' => password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    public function testDatabaseRolesComposeWithMiddlewareViewsAndPolicy(): void
    {
        $this->rbac->createRole('editor');
        $this->rbac->createPermission('reports.view');
        $this->rbac->createPermission('articles.update');
        $this->rbac->grantPermission('editor', 'reports.view');
        $this->rbac->grantPermission('editor', 'articles.update');
        $this->actingAs($this->ada);
        $this->getJson('/reports')->assertStatus(403);
        $this->get('/permissions')->assertOk()->assertContains('<report>no</report>')
            ->assertContains('<cannot>yes</cannot>')->assertContains('<article>no</article>');

        $this->rbac->assignRole($this->ada, 'editor');
        $this->get('/reports')->assertOk()->assertContains('report');
        $this->get('/permissions')->assertOk()->assertContains('<report>yes</report>')
            ->assertContains('<cannot>no</cannot>')->assertContains('<article>yes</article>');
        $this->get('/article')->assertOk()->assertContains('updated');

        $this->app()->container()->make(AuthorizationManager::class)->define('reports.view',
            static fn (RbacHttpUser $identity): bool => false);
        $this->getJson('/reports')->assertStatus(403);
        $this->get('/permissions')->assertOk()->assertContains('<report>no</report>')
            ->assertContains('<article>yes</article>');
        $this->rbac->removeRole($this->ada, 'editor');
        $this->getJson('/article')->assertStatus(403);
    }

    public function testDatabaseDeletionAndTransactionRollbackKeepAssignmentsConsistent(): void
    {
        $this->rbac->createRole('editor');
        $this->rbac->createPermission('reports.view');
        $this->rbac->grantPermission('editor', 'reports.view');
        try {
            $this->database->transaction(function (): void {
                $this->rbac->assignRole($this->ada, 'editor');
                throw new RuntimeException('rollback');
            });
            self::fail('The caller transaction must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }
        self::assertFalse($this->rbac->hasRole($this->ada, 'editor'));
        $this->rbac->assignRole($this->ada, 'editor');
        self::assertTrue($this->rbac->hasPermission($this->ada, 'reports.view'));
        $fresh = new RbacManager(new DatabaseRbacRepository($this->database), true);
        self::assertTrue($fresh->hasPermission($this->ada, 'reports.view'));
        $this->rbac->deletePermission('reports.view');
        self::assertFalse($this->rbac->hasPermission($this->ada, 'reports.view'));
        self::assertSame(0, $this->database->table('rbac_role_permissions')->count());
        $this->rbac->deleteRole('editor');
        self::assertFalse($this->rbac->hasRole($this->ada, 'editor'));
        self::assertSame(0, $this->database->table('rbac_identity_roles')->count());
    }

    public function testExactCaseKeysAndIdentityIdentifiersStayDistinct(): void
    {
        $this->rbac->createRole('Editor');
        $this->rbac->createRole('editor');
        $this->rbac->createPermission('Reports.View');
        $this->rbac->createPermission('reports.view');
        $this->rbac->grantPermission('Editor', 'Reports.View');
        $this->rbac->grantPermission('editor', 'reports.view');
        $upper = new RbacHttpIdentity('Ada');
        $lower = new RbacHttpIdentity('ada');
        $this->rbac->assignRole($upper, 'Editor');
        $this->rbac->assignRole($lower, 'editor');
        self::assertTrue($this->rbac->hasRole($upper, 'Editor'));
        self::assertFalse($this->rbac->hasRole($upper, 'editor'));
        self::assertFalse($this->rbac->hasPermission($upper, 'reports.view'));
        self::assertTrue($this->rbac->hasPermission($lower, 'reports.view'));
        self::assertFalse($this->rbac->hasPermission($lower, 'Reports.View'));
        self::assertSame(2, $this->database->table('rbac_roles')->count());
        self::assertSame(2, $this->database->table('rbac_permissions')->count());
    }

    public function testUnknownPermissionAndTokenAbilityRemainSeparate(): void
    {
        $this->actingAs($this->ada);
        $this->getJson('/unknown')->assertStatus(500);
        $this->rbac->createPermission('reports.view');
        $this->rbac->createRole('reporter');
        $this->rbac->grantPermission('reporter', 'reports.view');
        $auth = $this->app()->container()->make(AuthManager::class);
        $tokenWithAbility = $auth->tokens('api')->issue($this->ada, 'reports', ['reports.view'])->token();
        $this->client()->withHeader('Authorization', 'Bearer ' . $tokenWithAbility);
        $this->getJson('/token-reports')->assertStatus(403);
        $this->rbac->assignRole($this->ada, 'reporter');
        $this->getJson('/token-reports')->assertOk()->assertContains('token report');
        $tokenWithoutAbility = $auth->tokens('api')->issue($this->ada, 'other', ['other.read'])->token();
        $this->client()->withHeader('Authorization', 'Bearer ' . $tokenWithoutAbility);
        $this->getJson('/token-reports')->assertStatus(403);
    }

    public function testPluginManagerFollowsApplicationSelection(): void
    {
        $other = TestApplication::temporary(['rbac' => ['enabled' => true, 'driver' => 'array']]);
        try {
            $otherManager = $other->application()->container()->make(RbacManager::class);
            $identity = new RbacHttpIdentity(42);
            $otherManager->createRole('reviewer');
            $otherManager->assignRole($identity, 'reviewer');
            RuntimeContext::select($this->app());
            self::assertSame($this->rbac, Rbac::manager());
            self::assertFalse(Rbac::hasRole($identity, 'reviewer'));
            RuntimeContext::select($other->application());
            self::assertSame($otherManager, Rbac::manager());
            self::assertTrue(Rbac::hasRole($identity, 'reviewer'));
            RuntimeContext::select($this->app());
            self::assertFalse(Rbac::hasRole($identity, 'reviewer'));
        } finally {
            RuntimeContext::select($this->app());
            $other->cleanup();
        }
    }
}

final class RbacHttpUser extends Model implements Authenticatable
{
    protected string $table = 'rbac_http_users';
    protected array $fillable = ['email', 'name', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

final readonly class RbacHttpIdentity implements Authenticatable
{
    public function __construct(private int|string $id) {}
    public function authIdentifier(): int|string { return $this->id; }
    public function authPasswordHash(): string { return 'unused'; }
}

final readonly class RbacHttpArticle
{
    public function __construct(public string $owner) {}
}

final class RbacHttpArticlePolicy
{
    public function __construct(private RbacManager $rbac) {}

    public function update(RbacHttpUser $identity, RbacHttpArticle $article): bool
    {
        return $this->rbac->hasPermission($identity, 'articles.update')
            && $article->owner === $identity->getAttribute('name');
    }
}
