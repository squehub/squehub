<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\RelationExistence;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\BelongsToMany;
use App\Database\Relations\HasMany;
use App\Database\Relations\HasOne;
use App\Database\Relations\MorphMany;
use App\Database\Relations\MorphTo;
use App\Database\Relations\RelationException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Correlated relation predicates execute in SQL without hydrating every child. */
final class RelationExistenceTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for relation existence tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->morphMap()->define('user', RelExistsUser::class);
        $pdo = $this->manager->connection()->pdo();
        $pdo->exec('CREATE TABLE rel_users (id INTEGER PRIMARY KEY, name TEXT, manager_id INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE rel_profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE rel_posts (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE rel_roles (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE rel_user_roles (user_id INTEGER, role_id INTEGER, enabled INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE rel_comments (id INTEGER PRIMARY KEY, commentable_type TEXT, commentable_id INTEGER, body TEXT, deleted_at TEXT)');
        $this->manager->connection('archive')->pdo()->exec(
            'CREATE TABLE rel_archive_posts (id INTEGER PRIMARY KEY, user_id INTEGER)'
        );
        $this->manager->table('rel_users')->insertMany([
            ['id' => 1, 'name' => 'Ada', 'manager_id' => null],
            ['id' => 2, 'name' => 'Bea', 'manager_id' => 1],
            ['id' => 3, 'name' => 'Cy', 'manager_id' => 1],
            ['id' => 4, 'name' => 'Dee', 'manager_id' => null],
        ]);
        $this->manager->table('rel_profiles')->insertMany([
            ['id' => 1, 'user_id' => 1, 'bio' => 'active', 'deleted_at' => null],
            ['id' => 2, 'user_id' => 2, 'bio' => 'deleted', 'deleted_at' => '2024-01-01'],
        ]);
        $this->manager->table('rel_posts')->insertMany([
            ['id' => 1, 'user_id' => 1, 'status' => 'published', 'deleted_at' => null],
            ['id' => 2, 'user_id' => 1, 'status' => 'deleted', 'deleted_at' => '2024-01-01'],
            ['id' => 3, 'user_id' => 2, 'status' => 'draft', 'deleted_at' => null],
            ['id' => 4, 'user_id' => null, 'status' => 'orphan', 'deleted_at' => null],
        ]);
        $this->manager->table('rel_roles')->insertMany([
            ['id' => 1, 'name' => 'reader', 'deleted_at' => null],
            ['id' => 2, 'name' => 'archived', 'deleted_at' => '2024-01-01'],
        ]);
        $this->manager->table('rel_user_roles')->insertMany([
            ['user_id' => 1, 'role_id' => 1, 'enabled' => 1],
            ['user_id' => 2, 'role_id' => 2, 'enabled' => 1],
            ['user_id' => 3, 'role_id' => 1, 'enabled' => 0],
        ]);
        $this->manager->table('rel_comments')->insertMany([
            ['id' => 1, 'commentable_type' => 'user', 'commentable_id' => 1, 'body' => 'active',
                'deleted_at' => null],
            ['id' => 2, 'commentable_type' => 'user', 'commentable_id' => 2, 'body' => 'deleted',
                'deleted_at' => '2024-01-01'],
            ['id' => 3, 'commentable_type' => 'other', 'commentable_id' => 3, 'body' => 'foreign',
                'deleted_at' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testHasManyHasOneBelongsToAndSelfRelations(): void
    {
        self::assertSame([1, 2], $this->ids(RelExistsUser::query()->has('posts')));
        self::assertSame([3, 4], $this->ids(RelExistsUser::query()->doesntHave('posts')));
        self::assertSame([1], $this->ids(RelExistsUser::query()->has('profile')));
        self::assertSame([2, 3, 4], $this->ids(RelExistsUser::query()->doesntHave('profile')));
        self::assertSame([1, 3], $this->ids(RelExistsPost::query()->has('user')));
        self::assertSame([2, 3], $this->ids(RelExistsUser::query()->has('manager')));
        self::assertSame([1, 4], $this->ids(RelExistsUser::query()->doesntHave('manager')));
        self::assertSame([1], $this->ids(RelExistsUser::query()->whereHas('posts',
            static function (ModelQuery $posts): void {
                $posts->filter('status', 'published');
            })));
        self::assertSame([1, 2], $this->ids(RelExistsUser::query()->whereHas('posts',
            static function (ModelQuery $posts): ModelQuery {
                return $posts->filter('status', 'published')->orFilter('status', 'draft');
            })));
        self::assertSame([1, 2], $this->ids(RelExistsUser::query()->whereHas('posts',
            static fn (ModelQuery $posts): ModelQuery => $posts->withDeleted())));
        self::assertSame([1], $this->ids(RelExistsUser::query()->filter('id', 4)
            ->orFilter('id', 1)->has('posts')));
        self::assertSame([1], $this->ids(RelExistsUser::query()->whereHas('posts',
            static fn (ModelQuery $posts): ModelQuery => $posts->scope('published'))));
    }

    public function testManyToManyUsesPivotFiltersAndRelatedSoftDeletePolicy(): void
    {
        self::assertSame([1], $this->ids(RelExistsUser::query()->has('roles')));
        self::assertSame([2, 3, 4], $this->ids(RelExistsUser::query()->doesntHave('roles')));
        self::assertSame([1, 2], $this->ids(RelExistsUser::query()->whereHas('roles',
            static fn (ModelQuery $roles): ModelQuery => $roles->withDeleted())));
        self::assertSame([1], $this->ids(RelExistsUser::query()->whereHas('roles',
            static fn (ModelQuery $roles): ModelQuery => $roles->filter('name', 'reader'))));
    }

    public function testMorphChildrenRequireBothAliasAndRelatedRow(): void
    {
        self::assertSame([1], $this->ids(RelExistsUser::query()->has('comments')));
        self::assertSame([1], $this->ids(RelExistsUser::query()->has('comments.commentable')));
        self::assertSame([2, 3, 4], $this->ids(RelExistsUser::query()->doesntHave('comments.commentable')));
        self::assertSame([2, 3, 4], $this->ids(RelExistsUser::query()->doesntHave('comments')));
        self::assertSame([1], $this->ids(RelExistsUser::query()->whereHas('comments',
            static fn (ModelQuery $comments): ModelQuery => $comments->filter('body', 'active')
                ->orFilter('body', 'foreign'))));
        self::assertSame([1, 2], $this->ids(RelExistsUser::query()->whereHas('comments',
            static fn (ModelQuery $comments): ModelQuery => $comments->withDeleted())));
    }

    public function testRelatedSoftDeletedParentIsExcludedUnlessExplicitlyIncluded(): void
    {
        $this->manager->table('rel_users')->filter('id', 1)->update(['deleted_at' => '2024-01-01']);
        self::assertSame([3], $this->ids(RelExistsPost::query()->has('user')));
        self::assertSame([1, 3], $this->ids(RelExistsPost::query()->whereHas('user',
            static fn (ModelQuery $users): ModelQuery => $users->withDeleted())));
    }

    public function testPredicatesStayLazyAndWorkWithCountOffsetAndCursorPages(): void
    {
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RelationExistsStatement::class]);
        RelationExistsStatement::$executions = 0;
        $query = RelExistsUser::query()->has('posts')->sort('id');
        self::assertSame(0, RelationExistsStatement::$executions);
        self::assertSame(2, $query->count());
        self::assertSame(1, RelationExistsStatement::$executions);
        $offset = $query->page(1, 1);
        self::assertSame(2, $offset->total());
        self::assertSame(1, $offset->items()->first()->getAttribute('id'));
        self::assertSame(3, RelationExistsStatement::$executions);
        $cursor = $query->cursorPage(1);
        self::assertSame(1, $cursor->items()->first()->getAttribute('id'));
        self::assertTrue($cursor->hasMore());
        self::assertSame(4, RelationExistsStatement::$executions);
        self::assertSame(2, $query->cursorPage(1, $cursor->nextCursor())
            ->items()->first()->getAttribute('id'));
    }

    public function testUnsupportedAndInvalidRelationsFailWithoutMutatingQuery(): void
    {
        $query = RelExistsUser::query()->filter('id', 1);
        $sql = $query->toSql();
        foreach (['missing', 'comments.nope'] as $name) {
            try {
                $query->has($name);
                self::fail('Unknown relation must fail.');
            } catch (RelationException) {
                self::assertSame($sql, $query->toSql());
            }
        }
        // Morph-to existence is bounded to registered aliases. Unknown stored
        // types remain unmatched rather than becoming PHP class names.
        self::assertSame([1], $this->ids(RelExistsComment::query()->has('commentable')));
        self::assertSame([3], $this->ids(RelExistsComment::query()->doesntHave('commentable')));
        try {
            RelExistsUser::query()->whereHas('posts',
                static fn (ModelQuery $posts): array => []);
            self::fail('An unrelated return value must fail.');
        } catch (RelationException $failure) {
            self::assertStringContainsString('active ModelQuery', $failure->getMessage());
        }
        try {
            RelExistsUser::query()->whereHas('posts',
                static fn (ModelQuery $posts): ModelQuery => $posts->has('user'));
            self::fail('Nested relation predicate needs a separate alias plan.');
        } catch (RelationException $failure) {
            self::assertStringContainsString('Nested', $failure->getMessage());
        }
        $crossConnection = RelExistsUser::query()->filter('id', 1)->orFilter('id', 2);
        $beforeCrossConnection = [$crossConnection->toSql(), $crossConnection->bindings()];
        try {
            $crossConnection->has('archivePosts');
            self::fail('EXISTS must not combine two separate database connections.');
        } catch (\LogicException $failure) {
            self::assertStringContainsString('same database connection', $failure->getMessage());
            self::assertSame($beforeCrossConnection,
                [$crossConnection->toSql(), $crossConnection->bindings()]);
        }
        self::assertFalse(method_exists($this->manager->table('rel_users'), 'has'));
    }

    /** @return list<int> */
    private function ids(ModelQuery $query): array
    {
        return array_map('intval', $query->sort('id')->all()->pluck('id'));
    }
}

/** @property int $id */
final class RelExistsUser extends Model
{
    protected string $table = 'rel_users';
    protected bool $softDeletes = true;

    public function posts(): HasMany { return $this->hasMany(RelExistsPost::class, 'user_id'); }
    public function archivePosts(): HasMany { return $this->hasMany(RelExistsArchivePost::class, 'user_id'); }
    public function profile(): HasOne { return $this->hasOne(RelExistsProfile::class, 'user_id'); }
    public function manager(): BelongsTo { return $this->belongsTo(self::class, 'manager_id'); }
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RelExistsRole::class, 'rel_user_roles', 'user_id', 'role_id')
            ->pivotFilter('enabled', 1);
    }
    public function comments(): MorphMany { return $this->morphMany(RelExistsComment::class, 'commentable'); }
}

final class RelExistsPost extends Model
{
    protected string $table = 'rel_posts';
    protected bool $softDeletes = true;

    protected static function scopes(): array
    {
        return ['published' => static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'published')];
    }

    public function user(): BelongsTo { return $this->belongsTo(RelExistsUser::class, 'user_id'); }
}

final class RelExistsArchivePost extends Model
{
    protected string $table = 'rel_archive_posts';
    protected ?string $connection = 'archive';
}

final class RelExistsProfile extends Model
{
    protected string $table = 'rel_profiles';
    protected bool $softDeletes = true;
}

final class RelExistsRole extends Model
{
    protected string $table = 'rel_roles';
    protected bool $softDeletes = true;
}

final class RelExistsComment extends Model
{
    protected string $table = 'rel_comments';
    protected bool $softDeletes = true;

    public function commentable(): MorphTo { return $this->morphTo('commentable'); }
}

/** Counts only statements actually sent to the database. */
final class RelationExistsStatement extends PDOStatement
{
    public static int $executions = 0;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
