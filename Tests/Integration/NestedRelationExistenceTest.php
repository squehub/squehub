<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\NestedRelationExistence;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\BelongsToMany;
use App\Database\Relations\HasMany;
use App\Database\Relations\RelationException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Nested predicates are correlated SQL queries, including pivot and self-relation hops. */
final class NestedRelationExistenceTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for nested relation existence tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $pdo = $this->manager->connection()->pdo();
        $pdo->exec('CREATE TABLE nest_members (id INTEGER PRIMARY KEY, name TEXT, manager_id INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE nest_posts (id INTEGER PRIMARY KEY, member_id INTEGER, status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE nest_comments (id INTEGER PRIMARY KEY, post_id INTEGER, author_id INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE nest_roles (id INTEGER PRIMARY KEY, title TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE nest_member_roles (member_id INTEGER, role_id INTEGER, enabled INTEGER)');
        $pdo->exec('CREATE TABLE nest_permissions (id INTEGER PRIMARY KEY, role_id INTEGER, granted INTEGER, deleted_at TEXT)');
        $this->manager->connection('archive')->pdo()->exec(
            'CREATE TABLE nest_archive_comments (id INTEGER PRIMARY KEY, post_id INTEGER)'
        );
        $this->manager->table('nest_members')->insertMany([
            ['id' => 1, 'name' => 'Ada', 'manager_id' => null, 'deleted_at' => null],
            ['id' => 2, 'name' => 'Bea', 'manager_id' => 1, 'deleted_at' => null],
            ['id' => 3, 'name' => 'Cy', 'manager_id' => 2, 'deleted_at' => null],
            ['id' => 4, 'name' => 'Dee', 'manager_id' => null, 'deleted_at' => null],
        ]);
        $this->manager->table('nest_posts')->insertMany([
            ['id' => 1, 'member_id' => 1, 'status' => 'published', 'deleted_at' => null],
            ['id' => 2, 'member_id' => 2, 'status' => 'draft', 'deleted_at' => null],
            ['id' => 3, 'member_id' => 3, 'status' => 'published', 'deleted_at' => '2024-01-01'],
            ['id' => 4, 'member_id' => 4, 'status' => 'published', 'deleted_at' => null],
        ]);
        $this->manager->table('nest_comments')->insertMany([
            ['id' => 1, 'post_id' => 1, 'author_id' => 2, 'deleted_at' => null],
            ['id' => 2, 'post_id' => 1, 'author_id' => 3, 'deleted_at' => '2024-01-01'],
            ['id' => 3, 'post_id' => 2, 'author_id' => 3, 'deleted_at' => null],
            ['id' => 4, 'post_id' => 3, 'author_id' => 2, 'deleted_at' => null],
            ['id' => 5, 'post_id' => 4, 'author_id' => 3, 'deleted_at' => null],
        ]);
        $this->manager->table('nest_roles')->insertMany([
            ['id' => 1, 'title' => 'editor', 'deleted_at' => null],
            ['id' => 2, 'title' => 'old', 'deleted_at' => '2024-01-01'],
        ]);
        $this->manager->table('nest_member_roles')->insertMany([
            ['member_id' => 1, 'role_id' => 1, 'enabled' => 1],
            ['member_id' => 2, 'role_id' => 2, 'enabled' => 1],
            ['member_id' => 3, 'role_id' => 1, 'enabled' => 0],
        ]);
        $this->manager->table('nest_permissions')->insertMany([
            ['id' => 1, 'role_id' => 1, 'granted' => 1, 'deleted_at' => null],
            ['id' => 2, 'role_id' => 2, 'granted' => 1, 'deleted_at' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testNestedHasDoesntHaveAndTerminalConstraint(): void
    {
        self::assertSame([1, 2, 4], $this->ids(NestedMember::query()->has('posts.comments.author')));
        self::assertSame([3], $this->ids(NestedMember::query()->doesntHave('posts.comments.author')));
        self::assertSame([1], $this->ids(NestedMember::query()->whereHas('posts.comments.author',
            static fn (ModelQuery $authors): ModelQuery => $authors->scope('named', 'Bea'))));
        self::assertSame([1, 2, 4], $this->ids(NestedMember::query()->whereHas('posts.comments.author',
            static fn (ModelQuery $authors): ModelQuery => $authors->filter('name', 'Bea')
                ->orFilter('name', 'Cy'))));
        self::assertSame([3], $this->ids(NestedMember::query()->has('manager.manager')));
        self::assertSame([1], $this->ids(NestedMember::query()->filter('id', 3)
            ->orFilter('id', 1)->has('posts.comments.author')));

        // Eight hops are bounded, and each self-reference receives a new alias.
        $eightHops = implode('.', array_fill(0, 8, 'manager'));
        self::assertSame([], $this->ids(NestedMember::query()->has($eightHops)));

        $sql = NestedMember::query()->has('posts.comments.author')->toSql();
        preg_match_all('/\\bAS `?(squehub_related_[a-f0-9]{12})`?/', $sql, $aliases);
        self::assertCount(3, array_unique($aliases[1]));
    }

    public function testSoftDeletesAndPivotConstraintsApplyAtEveryHop(): void
    {
        self::assertSame([1], $this->ids(NestedMember::query()->has('roles.permissions')));
        self::assertSame([1], $this->ids(NestedMember::query()->whereHas('roles.permissions',
            static fn (ModelQuery $permissions): ModelQuery => $permissions->filter('granted', 1))));
        $this->manager->table('nest_members')->filter('id', 3)
            ->update(['deleted_at' => '2024-01-01']);
        self::assertSame([1], $this->ids(NestedMember::query()->has('posts.comments.author')));
        self::assertSame([1, 2, 4], $this->ids(NestedMember::query()->whereHas('posts.comments.author',
            static fn (ModelQuery $authors): ModelQuery => $authors->withDeleted())));
        // The callback is terminal: it does not include soft-deleted posts.
        self::assertSame([], $this->ids(NestedMember::query()->filter('id', 3)
            ->whereHas('posts.comments.author',
                static fn (ModelQuery $authors): ModelQuery => $authors->withDeleted())));
    }

    public function testOneRootQueryAndPaginationShareTheSamePredicate(): void
    {
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [NestedStatement::class]);
        NestedStatement::$executions = 0;
        $query = NestedMember::query()->has('posts.comments.author')->sort('id');
        self::assertSame(0, NestedStatement::$executions);
        self::assertSame([1, 2, 4], $this->ids($query));
        self::assertSame(1, NestedStatement::$executions);
        self::assertSame(3, $query->count());
        self::assertSame(2, NestedStatement::$executions);
        $page = $query->page(1, 2);
        self::assertSame(3, $page->total());
        self::assertSame([1, 2], array_map('intval', $page->items()->pluck('id')));
        self::assertSame(4, NestedStatement::$executions);
        $cursor = $query->cursorPage(2);
        self::assertSame([1, 2], array_map('intval', $cursor->items()->pluck('id')));
        self::assertTrue($cursor->hasMore());
        self::assertSame(5, NestedStatement::$executions);
        self::assertSame([4], array_map('intval', $query->cursorPage(2, $cursor->nextCursor())
            ->items()->pluck('id')));
    }

    public function testInvalidAndCrossConnectionPathsDoNotMutateCallerQuery(): void
    {
        $query = NestedMember::query()->filter('id', 1);
        $before = [$query->toSql(), $query->bindings()];
        foreach (['posts..author', 'posts.comments.unknown', 'posts.unknown.author',
            'posts.archiveComments', 'roles.unknown',
            implode('.', array_fill(0, 9, 'manager'))] as $path) {
            try {
                $query->has($path);
                self::fail('Invalid or cross-connection path must fail: ' . $path);
            } catch (RelationException|\LogicException) {
                self::assertSame($before, [$query->toSql(), $query->bindings()]);
            }
        }
        try {
            $query->whereHas('posts.comments.author', static fn (ModelQuery $author): array => []);
            self::fail('An invalid terminal callback result must fail.');
        } catch (RelationException $failure) {
            self::assertStringContainsString('active ModelQuery', $failure->getMessage());
            self::assertSame($before, [$query->toSql(), $query->bindings()]);
        }
    }

    /** @return list<int> */
    private function ids(ModelQuery $query): array
    {
        return array_map('intval', (clone $query)->sort('id')->all()->pluck('id'));
    }
}

final class NestedMember extends Model
{
    protected string $table = 'nest_members';
    protected bool $softDeletes = true;

    protected static function scopes(): array
    {
        return ['named' => static fn (ModelQuery $query, string $name): ModelQuery => $query->filter('name', $name)];
    }

    public function posts(): HasMany { return $this->hasMany(NestedPost::class, 'member_id'); }
    public function manager(): BelongsTo { return $this->belongsTo(self::class, 'manager_id'); }
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(NestedRole::class, 'nest_member_roles', 'member_id', 'role_id')
            ->pivotFilter('enabled', 1);
    }
}

final class NestedPost extends Model
{
    protected string $table = 'nest_posts';
    protected bool $softDeletes = true;

    public function comments(): HasMany { return $this->hasMany(NestedComment::class, 'post_id'); }
    public function archiveComments(): HasMany { return $this->hasMany(NestedArchiveComment::class, 'post_id'); }
}

final class NestedComment extends Model
{
    protected string $table = 'nest_comments';
    protected bool $softDeletes = true;

    public function author(): BelongsTo { return $this->belongsTo(NestedMember::class, 'author_id'); }
}

final class NestedRole extends Model
{
    protected string $table = 'nest_roles';
    protected bool $softDeletes = true;

    public function permissions(): HasMany { return $this->hasMany(NestedPermission::class, 'role_id'); }
}

final class NestedPermission extends Model
{
    protected string $table = 'nest_permissions';
    protected bool $softDeletes = true;
}

final class NestedArchiveComment extends Model
{
    protected string $table = 'nest_archive_comments';
    protected ?string $connection = 'archive';
}

/** Counts only actual PDO executions after fixture setup. */
final class NestedStatement extends PDOStatement
{
    public static int $executions = 0;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
