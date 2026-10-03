<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Phase6F;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Exception\ScopeException;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\ModelQuery;
use App\Database\Pagination\Page;
use App\Database\Schema\Table;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Phase 6F behavior is verified with disposable SQLite tables and a controlled UTC clock. */
final class ScopesSoftDeletesTest extends TestCase
{
    private DatabaseManager $manager;
    private PhaseClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->clock = new PhaseClock('2024-01-02T03:04:05+00:00');
        $config = new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]);
        $this->manager = new DatabaseManager($config, null, $this->clock);
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->schema()->create('phase_users', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->string('status');
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();
            $table->datetime('deleted_at')->nullable();
        });
        $this->manager->schema()->create('phase_posts', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('status');
            $table->datetime('deleted_at')->nullable();
        });
        $this->manager->schema()->create('phase_profiles', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('bio');
            $table->datetime('deleted_at')->nullable();
        });
        $this->manager->schema()->create('phase_roles', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->datetime('deleted_at')->nullable();
        });
        $this->manager->schema()->create('phase_user_roles', static function (Table $table): void {
            $table->integer('user_id');
            $table->integer('role_id');
        });
        $this->manager->schema()->create('phase_codes', static function (Table $table): void {
            $table->string('code');
            $table->primary('code');
            $table->string('name');
            $table->datetime('removed_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    private function user(string $name, string $status = 'active'): PhaseUser
    {
        return PhaseUser::create(['name' => $name, 'status' => $status]);
    }

    public function testNamedScopesComposeStayLazyAndIsolated(): void
    {
        $this->user('Ada');
        $this->user('Bob', 'inactive');
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PhaseCountingStatement::class]);
        $before = PhaseCountingStatement::$executions;
        $query = PhaseUser::query()->scope('status', 'active')->scope('named', 'Ada');
        self::assertSame($before, PhaseCountingStatement::$executions);
        self::assertSame(2, $this->manager->table('phase_users')->count());
        self::assertCount(1, $query->all());
        self::assertSame('Ada', $query->first()->name);
        self::assertCount(2, PhaseUser::query()->all());
        self::assertCount(1, PhaseUser::query()->scope('active')->with('posts')->all());
    }

    public function testScopesArgumentsReusePaginationAndDeletedMode(): void
    {
        $ada = $this->user('Ada');
        $this->user('Amy');
        $this->user('Bob', 'inactive');
        $ada->delete();
        self::assertSame(1, PhaseUser::query()->scope('status', 'active')->count());
        self::assertSame(2, PhaseUser::query()->withDeleted()->scope('status', 'active')->count());
        self::assertSame(1, PhaseUser::query()->onlyDeleted()->scope('named', 'Ada')->count());
        $page = PhaseUser::query()->withDeleted()->scope('status', 'active')->sort('name')->page(1, 1);
        self::assertSame(2, $page->total());
        self::assertInstanceOf(ModelCollection::class, $page->items());
        self::assertCount(1, $page->items());
        self::assertSame(1, PhaseUser::query()->scope('status', 'active')->scope('status', 'active')->count());
    }

    public function testOrFiltersCannotBypassRequiredDeletionMode(): void
    {
        $deleted = $this->user('Ada');
        $deleted->delete();
        $this->user('Bob', 'inactive');
        self::assertSame(1, PhaseUser::query()->filter('name', 'Ada')->orFilter('name', 'Bob')->count());
        self::assertSame(2, PhaseUser::query()->withDeleted()->filter('name', 'Ada')->orFilter('name', 'Bob')->count());
        self::assertSame(1, PhaseUser::query()->onlyDeleted()->filter('name', 'Ada')->orFilter('name', 'Bob')->count());
    }

    public function testScopeFailuresIdentifyModelAndScopeWithoutValues(): void
    {
        foreach (['missing', 'badReturn', 'badModel', 'badCollection', 'badPage', 'badBuilder', 'otherQuery', 'throws'] as $name) {
            try {
                PhaseUser::query()->scope($name, 'sensitive-value');
                self::fail('Expected scope failure.');
            } catch (ScopeException $exception) {
                self::assertStringContainsString(PhaseUser::class, $exception->getMessage());
                self::assertStringContainsString($name, $exception->getMessage());
                self::assertStringNotContainsString('sensitive-value', $exception->getMessage());
            }
        }
        $this->expectException(ScopeException::class);
        PhaseUser::query()->scope('not-valid!');
    }

    public function testScopeArgumentTypeFailurePreservesCause(): void
    {
        try {
            PhaseUser::query()->scope('status');
            self::fail('Expected missing argument failure.');
        } catch (ScopeException $exception) {
            self::assertStringContainsString('status', $exception->getMessage());
            self::assertInstanceOf(\ArgumentCountError::class, $exception->getPrevious());
        }
    }

    public function testSoftDeletionRestorationAndPermanentDeletion(): void
    {
        $user = $this->user('Ada');
        self::assertFalse($user->isDeleted());
        $created = $user->created_at;
        self::assertTrue($user->delete());
        self::assertTrue($user->exists());
        self::assertTrue($user->isDeleted());
        self::assertInstanceOf(DateTimeImmutable::class, $user->deleted_at);
        self::assertSame('2024-01-02 03:04:05', $user->deleted_at->format('Y-m-d H:i:s'));
        self::assertSame('2024-01-02 03:04:05', $user->updated_at);
        self::assertSame($created, $user->created_at);
        self::assertNull(PhaseUser::find($user->id));
        self::assertSame(1, $this->manager->table('phase_users')->count());
        self::assertSame(0, PhaseUser::query()->count());
        self::assertSame(1, PhaseUser::query()->withDeleted()->count());
        self::assertSame(1, PhaseUser::query()->onlyDeleted()->count());
        self::assertFalse($user->delete());
        $user->refresh();
        self::assertTrue($user->isDeleted());
        $user->name = 'Grace';
        $this->clock->set('2024-01-03T04:05:06+00:00');
        self::assertTrue($user->restore());
        self::assertFalse($user->isDeleted());
        self::assertTrue($user->changed('name'));
        self::assertSame('Ada', $this->manager->table('phase_users')->first()['name']);
        self::assertFalse($user->restore());
        self::assertSame('2024-01-03 04:05:06', $user->updated_at);
        self::assertTrue($user->save());
        self::assertSame('Grace', PhaseUser::find($user->id)->name);
        self::assertTrue($user->forceDelete());
        self::assertFalse($user->isDeleted());
        self::assertSame(0, $this->manager->table('phase_users')->count());
        $this->expectException(LogicException::class);
        $user->save();
    }

    public function testSaveDeletedModelDoesNotRestoreItAndQueryModeLastCallWins(): void
    {
        $user = $this->user('Ada');
        $user->delete();
        $user->name = 'Changed';
        self::assertTrue($user->save());
        self::assertTrue($user->isDeleted());
        self::assertNull(PhaseUser::find($user->id));
        self::assertSame('Changed', PhaseUser::query()->withDeleted()->first()->name);
        self::assertSame(1, PhaseUser::query()->withDeleted()->onlyDeleted()->count());
        self::assertSame(1, PhaseUser::query()->onlyDeleted()->withDeleted()->count());
    }

    public function testCustomKeyColumnAndPartialModels(): void
    {
        $code = PhaseCode::create(['code' => 'alpha-001', 'name' => 'Alpha']);
        $partial = PhaseCode::query()->select(['code'])->first();
        self::assertTrue($partial->delete());
        self::assertSame('Alpha', $this->manager->table('phase_codes')->first()['name']);
        self::assertSame('2024-01-02 03:04:05', $this->manager->table('phase_codes')->first()['removed_at']);
        self::assertSame(1, PhaseCode::query()->onlyDeleted()->page(1, 10)->total());
        self::assertTrue($partial->restore());
        self::assertTrue($code->forceDelete());
        self::assertSame(0, $this->manager->table('phase_codes')->count());
    }

    public function testRelationsExcludeDeletedRelatedRowsAndAllowExplicitModes(): void
    {
        $user = $this->user('Ada');
        $post = PhasePost::create(['user_id' => $user->id, 'status' => 'published']);
        PhasePost::create(['user_id' => $user->id, 'status' => 'draft']);
        $profile = PhaseProfile::create(['user_id' => $user->id, 'bio' => 'A']);
        $role = PhaseRole::create(['name' => 'editor']);
        $this->manager->table('phase_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        $post->delete();
        $profile->delete();
        $role->delete();
        self::assertCount(1, $user->posts);
        self::assertCount(1, $user->posts()->scope('published')->withDeleted()->get());
        self::assertCount(1, $user->posts()->onlyDeleted()->get());
        self::assertNull($user->profile);
        self::assertNotNull($user->profile()->withDeleted()->first());
        self::assertCount(0, $user->roles);
        self::assertCount(1, $user->roles()->withDeleted()->get());
        self::assertCount(1, $user->roles()->onlyDeleted()->get());
        self::assertSame(1, $this->manager->table('phase_user_roles')->count());
        $user->delete();
        self::assertNull(PhasePost::query()->withDeleted()->find($post->id)->user);
        self::assertNotNull(PhasePost::query()->withDeleted()->find($post->id)->user()->withDeleted()->first());
        $eager = PhaseUser::query()->withDeleted()->with(['posts', 'profile', 'roles'])->all();
        self::assertCount(1, $eager[0]->posts);
        self::assertNull($eager[0]->profile);
        self::assertCount(0, $eager[0]->roles);
    }

    public function testOptInAndMetadataAreExplicit(): void
    {
        $this->manager->table('phase_users')->insert(['name' => 'Ada', 'status' => 'active', 'deleted_at' => '2024-01-01 00:00:00']);
        self::assertSame(1, PhasePlainUser::query()->count());
        self::assertFalse(PhasePlainUser::find(1)->isDeleted());
        self::assertSame(1, $this->manager->table('phase_users')->count());
        foreach ([PhaseBadCast::class, PhaseBadColumn::class] as $class) {
            try {
                new $class();
                self::fail('Expected invalid metadata.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(LogicException::class);
        PhasePlainUser::query()->onlyDeleted();
    }

    public function testFailedWritesDoNotPublishLifecycleStateOrClearRelations(): void
    {
        $user = $this->user('Ada');
        $user->setRelation('posts', new ModelCollection([]));
        $this->manager->raw("CREATE TRIGGER block_soft_delete BEFORE UPDATE OF deleted_at ON phase_users BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        try {
            $user->delete();
            self::fail('Expected database failure.');
        } catch (\Throwable) {
            self::assertFalse($user->isDeleted());
            self::assertTrue($user->relationLoaded('posts'));
            self::assertFalse($user->changed());
        }
        $this->manager->raw('DROP TRIGGER block_soft_delete');
        self::assertTrue($user->delete());
        self::assertFalse($user->relationLoaded('posts'));
        $user->setRelation('posts', new ModelCollection([]));
        $this->manager->raw("CREATE TRIGGER block_restore BEFORE UPDATE OF deleted_at ON phase_users BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        try {
            $user->restore();
            self::fail('Expected database failure.');
        } catch (\Throwable) {
            self::assertTrue($user->isDeleted());
            self::assertTrue($user->relationLoaded('posts'));
        }
        $this->manager->raw('DROP TRIGGER block_restore');
        $this->manager->raw("CREATE TRIGGER block_force_delete BEFORE DELETE ON phase_users BEGIN SELECT RAISE(ABORT, 'blocked'); END");
        try {
            $user->forceDelete();
            self::fail('Expected database failure.');
        } catch (\Throwable) {
            self::assertTrue($user->exists());
            self::assertTrue($user->relationLoaded('posts'));
        }
    }

    public function testMissingRowsAndPartialIdentityDoNotInventState(): void
    {
        $user = $this->user('Ada');
        $withoutKey = PhaseUser::query()->select(['name', 'deleted_at'])->first();
        try {
            $withoutKey->delete();
            self::fail('Expected missing identity failure.');
        } catch (LogicException) {
            self::assertFalse($withoutKey->isDeleted());
        }
        $this->manager->table('phase_users')->filter('id', $user->id)->delete();
        self::assertFalse($user->delete());
        self::assertFalse($user->isDeleted());
        self::assertTrue($user->exists());
        try {
            $user->refresh();
            self::fail('Expected missing row failure.');
        } catch (LogicException) {
            self::assertFalse($user->isDeleted());
        }
    }

    public function testMassAssignmentCannotChangeDeletionState(): void
    {
        $user = $this->user('Ada');
        foreach ([fn () => $user->fill(['deleted_at' => '2024-01-01']),
                  fn () => $user->setAttribute('deleted_at', '2024-01-01')] as $attempt) {
            try {
                $attempt();
                self::fail('Expected framework-owned state rejection.');
            } catch (InvalidArgumentException) {
                self::assertFalse($user->changed());
            }
        }
    }

    public function testEagerLoadingRemainsBoundedWithSoftDeleteFilter(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $user = $this->user('User' . $i);
            $post = PhasePost::create(['user_id' => $user->id, 'status' => 'published']);
            if ($i % 2 === 0) {
                $post->delete();
            }
        }
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PhaseCountingStatement::class]);
        $before = PhaseCountingStatement::$executions;
        $users = PhaseUser::query()->with('posts')->all();
        self::assertCount(8, $users);
        self::assertSame(2, PhaseCountingStatement::$executions - $before);
        self::assertCount(0, $users[0]->posts);
        self::assertCount(1, $users[1]->posts);
    }

    public function testMySqlQueryCompilationDoesNotOpenConnection(): void
    {
        $config = new Repository(['database' => [
            'default' => 'mysql_test',
            'connections' => ['mysql_test' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
                'database' => 'unused', 'username' => 'unused', 'password' => 'unused',
            ]],
        ]]);
        $manager = new DatabaseManager($config);
        Database::setResolver(fn (): DatabaseManager => $manager);
        $default = PhaseUser::query()->filter('status', 'active')->orFilter('name', 'Ada');
        $only = PhaseUser::query()->onlyDeleted()->filter('status', 'active');
        self::assertStringContainsString('`deleted_at` IS NULL', $default->toSql());
        self::assertStringContainsString('`deleted_at` IS NOT NULL', $only->toSql());
        self::assertSame(['active', 'Ada'], $default->bindings());
        self::assertFalse($manager->connection()->isConnected());
    }
}

final class PhaseClock implements ModelClock
{
    private DateTimeImmutable $now;
    public function __construct(string $instant) { $this->set($instant); }
    public function set(string $instant): void { $this->now = new DateTimeImmutable($instant); }
    public function now(): DateTimeImmutable { return $this->now; }
}

class PhaseUser extends Model
{
    protected string $table = 'phase_users';
    protected array $fillable = ['name', 'status'];
    protected bool $timestamps = true;
    protected bool $softDeletes = true;
    protected static function scopes(): array
    {
        return [
            'active' => static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'active'),
            'status' => static fn (ModelQuery $query, string $status): ModelQuery => $query->filter('status', $status),
            'named' => static fn (ModelQuery $query, string $name): ModelQuery => $query->filter('name', $name),
            'badReturn' => static fn (): array => [],
            'badModel' => static fn (): self => new self(),
            'badCollection' => static fn (): ModelCollection => new ModelCollection([]),
            'badPage' => static fn (): Page => new Page(new ModelCollection([]), 1, 1, 0),
            'badBuilder' => static fn () => Database::manager()->table('phase_users'),
            'otherQuery' => static fn (): ModelQuery => self::query(),
            'throws' => static fn (): never => throw new LogicException('private detail'),
        ];
    }
    public function posts() { return $this->hasMany(PhasePost::class, 'user_id'); }
    public function profile() { return $this->hasOne(PhaseProfile::class, 'user_id'); }
    public function roles() { return $this->belongsToMany(PhaseRole::class, 'phase_user_roles', 'user_id', 'role_id'); }
}

final class PhasePlainUser extends Model
{
    protected string $table = 'phase_users';
}

final class PhasePost extends Model
{
    protected string $table = 'phase_posts';
    protected array $fillable = ['user_id', 'status'];
    protected bool $softDeletes = true;
    protected static function scopes(): array
    {
        return ['published' => static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'published')];
    }
    public function user() { return $this->belongsTo(PhaseUser::class, 'user_id'); }
}

final class PhaseProfile extends Model
{
    protected string $table = 'phase_profiles';
    protected array $fillable = ['user_id', 'bio'];
    protected bool $softDeletes = true;
}

final class PhaseRole extends Model
{
    protected string $table = 'phase_roles';
    protected array $fillable = ['name'];
    protected bool $softDeletes = true;
}

final class PhaseCode extends Model
{
    protected string $table = 'phase_codes';
    protected string $primaryKey = 'code';
    protected array $fillable = ['code', 'name'];
    protected bool $softDeletes = true;
    protected string $deletedAtColumn = 'removed_at';
}

final class PhaseBadCast extends Model
{
    protected bool $softDeletes = true;
    protected array $casts = ['deleted_at' => 'integer'];
}

final class PhaseBadColumn extends Model
{
    protected bool $softDeletes = true;
    protected string $deletedAtColumn = 'bad-column';
}

final class PhaseCountingStatement extends PDOStatement
{
    public static int $executions = 0;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
