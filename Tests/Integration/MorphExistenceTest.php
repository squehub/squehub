<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\MorphExistence;

use App\Config\Repository;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\Relations\MorphMany;
use App\Database\Relations\MorphTo;
use App\Database\Relations\RelationException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Registered morph targets participate in one bounded correlated read. */
final class MorphExistenceTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for MorphTo existence tests.');
        }
        $this->manager = $this->manager();
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->installSchema($this->manager);
        $this->manager->morphMap()
            ->define('post', MorphExistPost::class)
            ->define('video', MorphExistVideo::class);
        $this->seed($this->manager);
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testGenericHasAndDoesntHaveMatchOnlyRegisteredExistingActiveTargets(): void
    {
        self::assertSame([1, 2, 4, 5], $this->ids(MorphExistComment::query()->has('commentable')));
        self::assertSame([3, 6, 7, 8, 9, 10], $this->ids(
            MorphExistComment::query()->doesntHave('commentable')
        ));
        $this->manager->table('morph_exist_comments')->insert([
            'id' => 11, 'commentable_type' => 'post', 'commentable_id' => 1, 'body' => 'extra',
        ]);
        self::assertSame([1, 2, 4, 5, 11], $this->ids(MorphExistComment::query()->has('commentable')));
    }

    public function testAliasValuesAreBoundAndStoredClassNamesNeverBecomeSqlIdentifiers(): void
    {
        $query = MorphExistComment::query()->has('commentable');
        $sql = $query->toSql();
        self::assertStringContainsString('EXISTS', $sql);
        self::assertStringNotContainsString("'post'", $sql);
        self::assertStringNotContainsString("'video'", $sql);
        self::assertStringNotContainsString('Project\\Models\\Something', $sql);
        self::assertContains('post', $query->bindings());
        self::assertContains('video', $query->bindings());
        self::assertSame([1, 2, 4, 5], $this->ids($query));
    }

    public function testDirectMorphToCountProjectsZeroOrOneWithoutLoadingTargets(): void
    {
        $comments = MorphExistComment::query()->withCount('commentable')->sort('id')->get();
        self::assertSame([1, 1, 0, 1, 1, 0, 0, 0, 0, 0],
            $comments->pluck('commentable_count'));
        self::assertFalse($comments[0]->relationLoaded('commentable'));
        self::assertSame(1, $comments[0]->toArray()['commentable_count']);
        self::assertArrayNotHasKey('commentable_count', $comments[0]->attributes());
    }

    public function testConstrainedMorphToUsesEachTargetSchemaAndPreservesSoftDeletePolicy(): void
    {
        self::assertSame([1, 4], $this->ids(MorphExistComment::query()->whereHasMorph('commentable', [
            'post' => static fn (ModelQuery $query): ModelQuery => $query->scope('published'),
            'video' => static fn (ModelQuery $query): ModelQuery => $query->filter('processed', 1),
        ])));
        self::assertSame([1, 2], $this->ids(MorphExistComment::query()->whereHasMorph('commentable', [
            'post' => null,
        ])));
        self::assertSame([1, 2, 3], $this->ids(MorphExistComment::query()->whereHasMorph('commentable', [
            'post' => static fn (ModelQuery $query): ModelQuery => $query->withDeleted(),
        ])));
        self::assertSame([1, 2], $this->ids(MorphExistComment::query()->whereHasMorph('commentable', [
            'post' => static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'draft')
                ->orFilter('status', 'published'),
        ])));
    }

    public function testExistenceAndConstrainedMorphReadsRemainOneRootSqlQuery(): void
    {
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [MorphExistStatement::class]);
        MorphExistStatement::$executions = 0;
        $query = MorphExistComment::query()->has('commentable');
        self::assertSame(0, MorphExistStatement::$executions);
        self::assertSame([1, 2, 4, 5], $this->ids($query));
        self::assertSame(1, MorphExistStatement::$executions);
        self::assertSame([1, 4], $this->ids(MorphExistComment::query()->whereHasMorph('commentable', [
            'post' => static fn (ModelQuery $query): ModelQuery => $query->scope('published'),
            'video' => static fn (ModelQuery $query): ModelQuery => $query->filter('processed', 1),
        ])));
        self::assertSame(2, MorphExistStatement::$executions);
    }

    public function testEmptyMapMatchesNothingWithoutHydratingUntrustedTypes(): void
    {
        $other = $this->manager();
        $this->installSchema($other);
        $other->table('morph_exist_comments')->insert([
            'id' => 1, 'commentable_type' => 'post', 'commentable_id' => 1, 'body' => 'unmapped',
        ]);
        self::assertSame([], $this->ids(MorphExistComment::queryOn($other)->has('commentable')));
        self::assertSame([1], $this->ids(MorphExistComment::queryOn($other)->doesntHave('commentable')));
    }

    public function testInvalidAliasesCallbacksAndGenericConstraintsFailWithoutChangingCallerQuery(): void
    {
        foreach ([[], ['missing' => null], ['../../outside' => null], ['post' => 5],
            array_fill_keys(array_map(static fn (int $index): string => 'alias' . $index,
                range(1, 33)), null),
        ] as $constraints) {
            $query = MorphExistComment::query()->filter('id', 1);
            $before = [$query->toSql(), $query->bindings()];
            try {
                $query->whereHasMorph('commentable', $constraints);
                self::fail('Invalid MorphTo constraints must fail.');
            } catch (RelationException) {
                self::assertSame($before, [$query->toSql(), $query->bindings()]);
            }
        }
        $query = MorphExistComment::query();
        $before = $query->toSql();
        try {
            $query->whereHas('commentable', static fn (ModelQuery $target): ModelQuery => $target);
            self::fail('One generic callback cannot safely constrain heterogeneous targets.');
        } catch (RelationException) {
            self::assertSame($before, $query->toSql());
        }
        try {
            MorphExistComment::query()->whereHasMorph('commentable', [
                'post' => static fn (ModelQuery $target): array => [],
            ]);
            self::fail('The callback cannot switch query instances or return another type.');
        } catch (RelationException $failure) {
            self::assertStringContainsString('active ModelQuery', $failure->getMessage());
        }
        foreach (['missing', 'commentable.author'] as $name) {
            try {
                MorphExistComment::query()->whereHasMorph($name, ['post' => null]);
                self::fail('Only a declared direct MorphTo relation is accepted.');
            } catch (RelationException) {
                self::assertTrue(true);
            }
        }
        try {
            MorphExistPost::query()->whereHasMorph('comments', ['post' => null]);
            self::fail('A morph child relation is not MorphTo.');
        } catch (RelationException) {
            self::assertTrue(true);
        }
    }

    public function testCrossConnectionTargetsAreRejectedAndSelectedAliasCanRemainLocal(): void
    {
        $this->manager->morphMap()->define('remote', MorphExistRemotePost::class);
        $query = MorphExistComment::query()->filter('id', 1);
        $before = [$query->toSql(), $query->bindings()];
        try {
            $query->has('commentable');
            self::fail('An EXISTS predicate cannot mix two connections.');
        } catch (RelationException $failure) {
            self::assertStringContainsString('same database connection', $failure->getMessage());
            self::assertSame($before, [$query->toSql(), $query->bindings()]);
        }
        self::assertSame([1, 2], $this->ids(MorphExistComment::query()->whereHasMorph('commentable', [
            'post' => null,
        ])));
    }

    public function testOriginatingManagerSuppliesMorphMapEvenAfterResolverChanges(): void
    {
        $query = MorphExistComment::query()->has('commentable');
        $other = $this->manager();
        $this->installSchema($other);
        Database::setResolver(static fn (): DatabaseManager => $other);
        self::assertSame([1, 2, 4, 5], $this->ids($query));
        self::assertSame([1, 2, 4, 5], $this->ids(MorphExistComment::queryOn($this->manager)
            ->has('commentable')));
    }

    public function testNestedPathCanEndAtMorphToWithoutHydratingIntermediateRows(): void
    {
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [MorphExistStatement::class]);
        MorphExistStatement::$executions = 0;
        self::assertSame([1, 2], $this->ids(MorphExistPost::query()->has('comments.commentable')));
        self::assertSame(1, MorphExistStatement::$executions);
        self::assertSame([1, 2], $this->ids(MorphExistVideo::query()->has('comments.commentable')));
        self::assertSame(2, MorphExistStatement::$executions);
    }

    public function testNestedPathCannotTraverseBeyondMorphToEvenWithOneRegisteredType(): void
    {
        $single = $this->manager();
        $this->installSchema($single);
        $single->morphMap()->define('post', MorphExistPost::class);
        $this->seed($single);
        $single->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [MorphExistStatement::class]);
        MorphExistStatement::$executions = 0;
        $query = MorphExistComment::queryOn($single)->filter('id', 1);
        $before = [$query->toSql(), $query->bindings()];
        try {
            $query->has('commentable.comments');
            self::fail('The target branch has no uniform SQL alias for deeper traversal.');
        } catch (RelationException $failure) {
            self::assertStringContainsString('beyond a MorphTo', $failure->getMessage());
            self::assertSame($before, [$query->toSql(), $query->bindings()]);
            self::assertSame(0, MorphExistStatement::$executions);
        }
        self::assertSame([1, 2], $this->ids(MorphExistComment::queryOn($single)->has('commentable')));
        self::assertSame(1, MorphExistStatement::$executions);
    }

    private function manager(): DatabaseManager
    {
        return new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]));
    }

    private function installSchema(DatabaseManager $manager): void
    {
        $pdo = $manager->connection()->pdo();
        $pdo->exec('CREATE TABLE morph_exist_posts (id INTEGER PRIMARY KEY, status TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE morph_exist_videos (id INTEGER PRIMARY KEY, processed INTEGER)');
        $pdo->exec('CREATE TABLE morph_exist_comments (id INTEGER PRIMARY KEY, commentable_type TEXT, '
            . 'commentable_id INTEGER, body TEXT)');
    }

    private function seed(DatabaseManager $manager): void
    {
        $manager->table('morph_exist_posts')->insertMany([
            ['id' => 1, 'status' => 'published', 'deleted_at' => null],
            ['id' => 2, 'status' => 'draft', 'deleted_at' => null],
            ['id' => 3, 'status' => 'published', 'deleted_at' => '2024-01-01 00:00:00'],
        ]);
        $manager->table('morph_exist_videos')->insertMany([
            ['id' => 1, 'processed' => 1], ['id' => 2, 'processed' => 0],
        ]);
        $manager->table('morph_exist_comments')->insertMany([
            ['id' => 1, 'commentable_type' => 'post', 'commentable_id' => 1, 'body' => 'post one'],
            ['id' => 2, 'commentable_type' => 'post', 'commentable_id' => 2, 'body' => 'post two'],
            ['id' => 3, 'commentable_type' => 'post', 'commentable_id' => 3, 'body' => 'deleted target'],
            ['id' => 4, 'commentable_type' => 'video', 'commentable_id' => 1, 'body' => 'video one'],
            ['id' => 5, 'commentable_type' => 'video', 'commentable_id' => 2, 'body' => 'video two'],
            ['id' => 6, 'commentable_type' => 'post', 'commentable_id' => 999, 'body' => 'missing target'],
            ['id' => 7, 'commentable_type' => 'unknown', 'commentable_id' => 1, 'body' => 'unknown alias'],
            ['id' => 8, 'commentable_type' => 'Project\\Models\\Something', 'commentable_id' => 1,
                'body' => 'stored class name'],
            ['id' => 9, 'commentable_type' => null, 'commentable_id' => null, 'body' => 'null type'],
            ['id' => 10, 'commentable_type' => 'video', 'commentable_id' => null, 'body' => 'null key'],
        ]);
    }

    /** @return list<int> */
    private function ids(ModelQuery $query): array
    {
        return array_map('intval', $query->sort('id')->all()->pluck('id'));
    }
}

final class MorphExistPost extends Model
{
    protected string $table = 'morph_exist_posts';
    protected bool $softDeletes = true;

    protected static function scopes(): array
    {
        return ['published' => static fn (ModelQuery $query): ModelQuery => $query->filter('status', 'published')];
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(MorphExistComment::class, 'commentable');
    }
}

final class MorphExistVideo extends Model
{
    protected string $table = 'morph_exist_videos';

    public function comments(): MorphMany
    {
        return $this->morphMany(MorphExistComment::class, 'commentable');
    }
}

final class MorphExistRemotePost extends Model
{
    protected string $table = 'morph_exist_posts';
    protected ?string $connection = 'archive';
}

final class MorphExistComment extends Model
{
    protected string $table = 'morph_exist_comments';

    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable');
    }
}

/** Counts executed statements, excluding SQL generated but never sent to PDO. */
final class MorphExistStatement extends PDOStatement
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
