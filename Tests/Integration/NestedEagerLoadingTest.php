<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\NestedRelations;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\HasMany;
use App\Database\Relations\RelationException;
use App\Database\Schema\Table;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Runs nested relation trees against real SQLite and counts executed statements. */
final class NestedEagerLoadingTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for nested relation tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $schema = $this->manager->schema();
        $schema->create('nested_users', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('nested_posts', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
        });
        $schema->create('nested_comments', static function (Table $table): void {
            $table->id();
            $table->integer('post_id');
            $table->integer('user_id');
            $table->string('body');
            $table->datetime('deleted_at')->nullable();
        });
        $this->seed();
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testSharedPrefixesBatchEveryLevelAndCacheNestedModels(): void
    {
        NestedCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [NestedCountingStatement::class]);
        $users = NestedUser::query()->with([
            'posts.comments.author', 'posts.author', 'posts.comments.author',
        ])->sort('id')->get();
        self::assertInstanceOf(ModelCollection::class, $users);
        self::assertCount(3, $users);
        self::assertSame(5, NestedCountingStatement::$executions);
        $posts = $users[0]->getRelation('posts');
        self::assertInstanceOf(ModelCollection::class, $posts);
        self::assertCount(2, $posts);
        $comments = $posts[0]->getRelation('comments');
        self::assertInstanceOf(ModelCollection::class, $comments);
        self::assertCount(2, $comments);
        self::assertSame('User 2', $comments[0]->getRelation('author')->getAttribute('name'));
        self::assertSame('User 1', $posts[0]->getRelation('author')->getAttribute('name'));
        self::assertSame(5, NestedCountingStatement::$executions);
        self::assertTrue($users[0]->relationLoaded('posts'));
        self::assertTrue($posts[0]->relationLoaded('comments'));
        self::assertTrue($comments[0]->relationLoaded('author'));
    }

    public function testModelAndCollectionLoadUseExistingInstancesAndRefreshClearsRootCache(): void
    {
        $user = NestedUser::find(1);
        self::assertSame($user, $user->load('posts.comments.author'));
        $posts = $user->getRelation('posts');
        self::assertInstanceOf(ModelCollection::class, $posts);
        self::assertCount(2, $posts);
        $comments = $posts[0]->getRelation('comments');
        self::assertInstanceOf(ModelCollection::class, $comments);
        self::assertTrue($comments[0]->relationLoaded('author'));
        $user->refresh();
        self::assertFalse($user->relationLoaded('posts'));

        $users = NestedUser::query()->sort('id')->get();
        self::assertSame($users, $users->load(['posts.comments', 'posts.author']));
        $secondPosts = $users[1]->getRelation('posts');
        self::assertInstanceOf(ModelCollection::class, $secondPosts);
        self::assertTrue($secondPosts[0]->relationLoaded('comments'));
        self::assertTrue($secondPosts[0]->relationLoaded('author'));
    }

    public function testEmptyParentSetDoesNotQueryRelationsButStillValidatesPath(): void
    {
        NestedCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [NestedCountingStatement::class]);
        self::assertTrue(NestedUser::query()->with('posts.comments.author')
            ->filter('id', -1)->get()->isEmpty());
        self::assertSame(1, NestedCountingStatement::$executions);

        $this->expectException(RelationException::class);
        NestedUser::query()->with('posts.unknown')->filter('id', -1)->get();
    }

    public function testPathValidationRejectsRunawayDepthAndEmptySegments(): void
    {
        foreach (['posts..comments', '.posts', 'posts.',
            'posts.comments.author.posts.comments.author.posts.comments.author'] as $path) {
            try {
                NestedUser::query()->with($path);
                self::fail('Invalid nested relation path was accepted.');
            } catch (RelationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSoftDeletedNestedRowsStayExcluded(): void
    {
        $comment = NestedComment::find(1);
        self::assertTrue($comment->delete());
        $user = NestedUser::query()->with('posts.comments.author')->find(1);
        $posts = $user->getRelation('posts');
        self::assertInstanceOf(ModelCollection::class, $posts);
        $comments = $posts[0]->getRelation('comments');
        self::assertInstanceOf(ModelCollection::class, $comments);
        self::assertCount(1, $comments);
        self::assertTrue($comments[0]->relationLoaded('author'));
    }

    private function seed(): void
    {
        for ($id = 1; $id <= 3; $id++) {
            $this->manager->table('nested_users')->insert(['name' => 'User ' . $id]);
        }
        for ($post = 1; $post <= 6; $post++) {
            $owner = (int) ceil($post / 2);
            $this->manager->table('nested_posts')->insert([
                'user_id' => $owner, 'title' => 'Post ' . $post,
            ]);
            for ($comment = 1; $comment <= 2; $comment++) {
                $this->manager->table('nested_comments')->insert([
                    'post_id' => $post, 'user_id' => $owner === 1 ? 2 : 1,
                    'body' => 'Comment ' . $post . '.' . $comment,
                ]);
            }
        }
    }
}

final class NestedUser extends Model
{
    protected string $table = 'nested_users';

    public function posts(): HasMany
    {
        return $this->hasMany(NestedPost::class, 'user_id');
    }
}

final class NestedPost extends Model
{
    protected string $table = 'nested_posts';

    public function comments(): HasMany
    {
        return $this->hasMany(NestedComment::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(NestedUser::class, 'user_id');
    }
}

final class NestedComment extends Model
{
    protected string $table = 'nested_comments';
    protected bool $softDeletes = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(NestedUser::class, 'user_id');
    }
}

final class NestedCountingStatement extends PDOStatement
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
