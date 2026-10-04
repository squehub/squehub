<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\ThroughRelations;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Exception\InvalidIdentifierException;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\HasManyThrough;
use App\Database\Relations\HasOneThrough;
use App\Database\Relations\RelationException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Through relations use bounded Model reads and preserve both Models' policies. */
final class ThroughRelationsTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for through relation tests.');
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
        $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, code TEXT, name TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, country_id INTEGER, country_code TEXT, '
            . 'ref TEXT, name TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, user_ref TEXT, '
            . 'status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT, deleted_at TEXT)');
        $this->manager->connection('archive')->pdo()->exec(
            'CREATE TABLE archive_posts (id INTEGER PRIMARY KEY, user_id INTEGER)'
        );
        $this->seed();
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testLazyReadsUseModelPoliciesScopesAndReadOnlyCollectionResults(): void
    {
        $country = Country::find(1);
        self::assertInstanceOf(Country::class, $country);
        self::assertInstanceOf(ModelCollection::class, $country->posts);
        self::assertSame([1, 2, 3], $this->ids($country->posts));
        self::assertSame([1, 3], $this->ids($country->posts()->scope('published')->get()));
        self::assertSame([1, 2, 3, 4], $this->ids($country->posts()->withDeleted()->get()));
        self::assertSame([4], $this->ids($country->posts()->onlyDeleted()->get()));
        self::assertSame([1, 2, 3], $this->ids($country->customPosts()->get()));
        self::assertSame([1, 2, 3], $this->ids($country->postsByRef()->get()));
        $profile = $country->profile;
        self::assertInstanceOf(Profile::class, $profile);
        self::assertSame(1, (int) $profile->getAttribute('id'));
        self::assertNull(Country::find(2)?->profile);
        self::assertNull(Country::find(3)?->profile);
        self::assertTrue($country->relationLoaded('posts'));
        self::assertTrue($country->relationLoaded('profile'));
        self::assertFalse($country->changed());
        self::assertArrayNotHasKey('posts', $country->toArray());
    }

    public function testEagerAndNestedLoadingHaveBoundedStatementCountsAndRefreshClearsCache(): void
    {
        CountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingStatement::class]);
        $countries = Country::query()->with('posts')->sort('id')->get();
        self::assertSame(3, CountingStatement::$executions); // root + intermediate + final
        $firstPosts = $countries[0]->getRelation('posts');
        $secondPosts = $countries[1]->getRelation('posts');
        $thirdPosts = $countries[2]->getRelation('posts');
        self::assertInstanceOf(ModelCollection::class, $firstPosts);
        self::assertInstanceOf(ModelCollection::class, $secondPosts);
        self::assertInstanceOf(ModelCollection::class, $thirdPosts);
        self::assertSame([1, 2, 3], $this->ids($firstPosts));
        self::assertSame([5], $this->ids($secondPosts));
        self::assertTrue($thirdPosts->isEmpty());
        self::assertSame(3, CountingStatement::$executions);

        $countries[0]->refresh();
        self::assertFalse($countries[0]->relationLoaded('posts'));
        self::assertSame($countries[0], $countries[0]->load('posts.user'));
        $loadedPosts = $countries[0]->getRelation('posts');
        self::assertInstanceOf(ModelCollection::class, $loadedPosts);
        self::assertTrue($loadedPosts[0]->relationLoaded('user'));
        self::assertSame(7, CountingStatement::$executions); // refresh + two through reads + nested users
        self::assertSame('Ada', $loadedPosts[0]->getRelation('user')->getAttribute('name'));
        self::assertSame(7, CountingStatement::$executions);
    }

    public function testSingularEagerLoadingAndDuplicateIntermediateLookupKeys(): void
    {
        CountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingStatement::class]);
        $countries = Country::query()->with(['profile', 'postsByRef'])->sort('id')->get();
        self::assertSame(5, CountingStatement::$executions); // root + two reads per through relation
        self::assertSame(1, (int) $countries[0]->getRelation('profile')->getAttribute('id'));
        self::assertNull($countries[1]->getRelation('profile'));
        // Users 1 and 2 share a lookup ref; a final Post appears once per parent.
        $firstPosts = $countries[0]->getRelation('postsByRef');
        $secondPosts = $countries[1]->getRelation('postsByRef');
        $thirdPosts = $countries[2]->getRelation('postsByRef');
        self::assertInstanceOf(ModelCollection::class, $firstPosts);
        self::assertInstanceOf(ModelCollection::class, $secondPosts);
        self::assertInstanceOf(ModelCollection::class, $thirdPosts);
        self::assertSame([1, 2, 3], $this->ids($firstPosts));
        self::assertSame([5], $this->ids($secondPosts));
        self::assertTrue($thirdPosts->isEmpty());
    }

    public function testThroughExistenceUsesOneRootStatementAndFinalRelatedScopes(): void
    {
        CountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingStatement::class]);
        self::assertSame([1, 2], $this->ids(Country::query()->has('posts')->sort('id')->get()));
        self::assertSame(1, CountingStatement::$executions);
        self::assertSame([3], $this->ids(Country::query()->doesntHave('posts')->sort('id')->get()));
        self::assertSame(2, CountingStatement::$executions);
        self::assertSame([1], $this->ids(Country::query()->whereHas('posts',
            static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'draft'))->get()));
        self::assertSame(3, CountingStatement::$executions);
        self::assertSame([1], $this->ids(Country::query()->has('profile')->sort('id')->get()));
        self::assertSame(4, CountingStatement::$executions);
    }

    public function testNestedExistenceContinuesFromTheFinalModel(): void
    {
        CountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingStatement::class]);
        self::assertSame([1, 2], $this->ids(Country::query()->has('posts.user')->sort('id')->get()));
        self::assertSame(1, CountingStatement::$executions);
        self::assertSame([1], $this->ids(Country::query()->whereHas('posts.user',
            static fn (ModelQuery $query): ModelQuery => $query->filter('name', 'Ada'))->get()));
        self::assertSame(2, CountingStatement::$executions);
    }

    public function testWithCountCountsFinalRowsNotIntermediateRowsInOneStatement(): void
    {
        CountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingStatement::class]);
        $countries = Country::query()->withCount(['posts', 'profile'])->sort('id')->get();
        self::assertSame(1, CountingStatement::$executions);
        self::assertSame([3, 1, 0], array_map(
            static fn (Model $country): int => (int) $country->getAttribute('posts_count'),
            iterator_to_array($countries)
        ));
        self::assertSame([1, 0, 0], array_map(
            static fn (Model $country): int => (int) $country->getAttribute('profile_count'),
            iterator_to_array($countries)
        ));
        $withDeleted = Country::query()->withCount([
            'posts' => static fn (ModelQuery $query): ModelQuery => $query->withDeleted(),
        ])->filter('id', 1)->first();
        self::assertInstanceOf(Country::class, $withDeleted);
        self::assertSame(4, $withDeleted->getAttribute('posts_count'));
        self::assertSame(2, CountingStatement::$executions);
    }

    public function testDeclaredFinalScopeComposesWithEagerExistenceAndCount(): void
    {
        $countries = Country::query()->with('publishedPosts')->sort('id')->get();
        $first = $countries[0]->getRelation('publishedPosts');
        self::assertInstanceOf(ModelCollection::class, $first);
        self::assertSame([1, 3], $this->ids($first));
        self::assertSame([1, 2], $this->ids(Country::query()->has('publishedPosts')->sort('id')->get()));
        $counts = Country::query()->withCount('publishedPosts')->sort('id')->get();
        self::assertSame([2, 1, 0], array_map(
            static fn (Model $country): int => (int) $country->getAttribute('publishedPosts_count'),
            iterator_to_array($counts)
        ));
    }

    public function testSoftDeletedIntermediateAndFinalRowsStayExcluded(): void
    {
        $this->manager->table('users')->filter('id', 2)->update(['deleted_at' => '2024-01-01 00:00:00']);
        $this->manager->table('posts')->filter('id', 1)->update(['deleted_at' => '2024-01-01 00:00:00']);
        $this->manager->table('profiles')->filter('id', 1)->update(['deleted_at' => '2024-01-01 00:00:00']);
        self::assertSame([2], $this->ids(Country::find(1)?->posts()->get()));
        self::assertSame([1, 2], $this->ids(Country::find(1)?->posts()->withDeleted()->get()));
        self::assertSame([1], $this->ids(Country::find(1)?->posts()->onlyDeleted()->get()));
        self::assertNull(Country::find(1)?->profile);
        self::assertSame([1, 2], $this->ids(Country::query()->has('posts')->sort('id')->get()));
    }

    public function testCrossConnectionAndInvalidKeyOrClassFailBeforeReading(): void
    {
        $country = Country::find(1);
        self::assertInstanceOf(Country::class, $country);
        foreach (['archivePosts', 'archiveThroughPosts'] as $name) {
            try {
                $country->relation($name);
                self::fail('Cross-connection through relation was accepted.');
            } catch (RelationException $exception) {
                self::assertStringContainsString('one connection', $exception->getMessage());
            }
        }
        try {
            $country->invalidKey();
            self::fail('Unsafe through key was accepted.');
        } catch (InvalidIdentifierException) {
            self::assertTrue(true);
        }
        $this->expectException(RelationException::class);
        $country->invalidThroughClass();
    }

    public function testParentsWithoutLoadedKeysCauseNoRelationStatement(): void
    {
        CountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [CountingStatement::class]);
        $partial = Country::query()->select(['name'])->first();
        self::assertInstanceOf(Country::class, $partial);
        self::assertSame(1, CountingStatement::$executions);
        self::assertTrue($partial->posts()->get()->isEmpty());
        self::assertSame(1, CountingStatement::$executions);
    }

    public function testHydratedParentRetainsItsOriginatingManagerAfterResolverSwitch(): void
    {
        $country = Country::find(1);
        self::assertInstanceOf(Country::class, $country);
        $other = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $other);
        try {
            self::assertSame([1, 2, 3], $this->ids($country->posts()->get()));
        } finally {
            Database::setResolver(fn (): DatabaseManager => $this->manager);
        }
    }

    private function seed(): void
    {
        $this->manager->table('countries')->insertMany([
            ['id' => 1, 'code' => 'A', 'name' => 'Aland'],
            ['id' => 2, 'code' => 'B', 'name' => 'Bland'],
            ['id' => 3, 'code' => 'C', 'name' => 'Cland'],
        ]);
        $this->manager->table('users')->insertMany([
            ['id' => 1, 'country_id' => 1, 'country_code' => 'A', 'ref' => 'shared', 'name' => 'Ada', 'deleted_at' => null],
            ['id' => 2, 'country_id' => 1, 'country_code' => 'A', 'ref' => 'shared', 'name' => 'Bea', 'deleted_at' => null],
            ['id' => 3, 'country_id' => 2, 'country_code' => 'B', 'ref' => 'third', 'name' => 'Cy', 'deleted_at' => null],
            ['id' => 4, 'country_id' => 1, 'country_code' => 'A', 'ref' => 'fourth', 'name' => 'Dee',
                'deleted_at' => '2024-01-01 00:00:00'],
            ['id' => 5, 'country_id' => null, 'country_code' => null, 'ref' => 'fifth', 'name' => 'Eve',
                'deleted_at' => null],
        ]);
        $this->manager->table('posts')->insertMany([
            ['id' => 1, 'user_id' => 1, 'user_ref' => 'shared', 'status' => 'published', 'deleted_at' => null],
            ['id' => 2, 'user_id' => 1, 'user_ref' => 'shared', 'status' => 'draft', 'deleted_at' => null],
            ['id' => 3, 'user_id' => 2, 'user_ref' => 'shared', 'status' => 'published', 'deleted_at' => null],
            ['id' => 4, 'user_id' => 2, 'user_ref' => 'shared', 'status' => 'published',
                'deleted_at' => '2024-01-01 00:00:00'],
            ['id' => 5, 'user_id' => 3, 'user_ref' => 'third', 'status' => 'published', 'deleted_at' => null],
            ['id' => 6, 'user_id' => 4, 'user_ref' => 'fourth', 'status' => 'published', 'deleted_at' => null],
            ['id' => 7, 'user_id' => 5, 'user_ref' => 'fifth', 'status' => 'published', 'deleted_at' => null],
        ]);
        $this->manager->table('profiles')->insertMany([
            ['id' => 1, 'user_id' => 1, 'bio' => 'One', 'deleted_at' => null],
            ['id' => 2, 'user_id' => 3, 'bio' => 'Deleted', 'deleted_at' => '2024-01-01 00:00:00'],
        ]);
    }

    /** @param iterable<Model> $models @return list<int> */
    private function ids(iterable $models): array
    {
        $ids = [];
        foreach ($models as $model) {
            $ids[] = (int) $model->getAttribute('id');
        }
        return $ids;
    }
}

/**
 * @property-read ModelCollection $posts
 * @property-read Profile|null $profile
 */
final class Country extends Model
{
    protected string $table = 'countries';

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class);
    }

    public function profile(): HasOneThrough
    {
        return $this->hasOneThrough(Profile::class, User::class);
    }

    public function publishedPosts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class)->scope('published');
    }

    public function customPosts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class,
            'country_code', 'user_id', 'code', 'id');
    }

    public function postsByRef(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class,
            'country_id', 'user_ref', 'id', 'ref');
    }

    public function archivePosts(): HasManyThrough
    {
        return $this->hasManyThrough(ArchivePost::class, User::class);
    }

    public function archiveThroughPosts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, ArchiveUser::class);
    }

    public function invalidKey(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class, 'id; DROP TABLE users');
    }

    public function invalidThroughClass(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, \stdClass::class);
    }
}

final class User extends Model
{
    protected string $table = 'users';
    protected bool $softDeletes = true;
}

final class ArchiveUser extends Model
{
    protected string $table = 'users';
    protected ?string $connection = 'archive';
}

final class Post extends Model
{
    protected string $table = 'posts';
    protected bool $softDeletes = true;

    protected static function scopes(): array
    {
        return ['published' => static fn (ModelQuery $query): ModelQuery =>
            $query->filter('status', 'published')];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

final class Profile extends Model
{
    protected string $table = 'profiles';
    protected bool $softDeletes = true;
}

final class ArchivePost extends Model
{
    protected string $table = 'archive_posts';
    protected ?string $connection = 'archive';
}

/** Counts real SQL statements so eager-loading assertions detect per-parent reads. */
final class CountingStatement extends PDOStatement
{
    public static int $executions = 0;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
