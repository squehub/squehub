<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Polymorphic;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\MorphMany;
use App\Database\Relations\MorphOne;
use App\Database\Relations\MorphTo;
use App\Database\Relations\RelationException;
use App\Database\Schema\Table;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Exercises registered type aliases and bounded polymorphic reads on SQLite. */
final class PolymorphicRelationsTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for polymorphic relation tests.');
        }
        $this->manager = $this->manager();
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->morphMap()->define('post', PolyPost::class)->define('video', PolyVideo::class);
        $schema = $this->manager->schema();
        $schema->create('poly_users', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('poly_posts', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
            $table->datetime('deleted_at')->nullable();
        });
        $schema->create('poly_videos', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
        });
        $schema->create('poly_comments', static function (Table $table): void {
            $table->id();
            $table->string('commentable_type');
            $table->integer('commentable_id');
            $table->string('body');
        });
        $schema->create('poly_images', static function (Table $table): void {
            $table->id();
            $table->string('imageable_type');
            $table->integer('imageable_id');
            $table->string('url');
        });
        $this->seed();
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testMorphToGroupsKeysByRegisteredTypeAndCachesResults(): void
    {
        PolyCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [PolyCountingStatement::class]);
        $comments = PolyComment::query()->with('commentable')->sort('id')->get();
        self::assertInstanceOf(ModelCollection::class, $comments);
        self::assertCount(4, $comments);
        self::assertSame(3, PolyCountingStatement::$executions);
        self::assertInstanceOf(PolyPost::class, $comments[0]->getRelation('commentable'));
        self::assertSame('Post 1', $comments[0]->getRelation('commentable')->getAttribute('title'));
        self::assertInstanceOf(PolyVideo::class, $comments[1]->getRelation('commentable'));
        self::assertSame('Video 1', $comments[1]->getRelation('commentable')->getAttribute('title'));
        self::assertInstanceOf(PolyPost::class, $comments[2]->getRelation('commentable'));
        self::assertInstanceOf(PolyVideo::class, $comments[3]->getRelation('commentable'));
        self::assertSame(3, PolyCountingStatement::$executions);
    }

    public function testMorphToQueryCountDependsOnTypesRatherThanParentCount(): void
    {
        for ($index = 0; $index < 40; $index++) {
            $this->manager->table('poly_comments')->insert([
                'commentable_type' => $index % 2 === 0 ? 'post' : 'video',
                'commentable_id' => ($index % 4) < 2 ? 1 : 2,
                'body' => 'extra ' . $index,
            ]);
        }
        PolyCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [PolyCountingStatement::class]);
        $comments = PolyComment::query()->with('commentable')->get();
        self::assertCount(44, $comments);
        self::assertSame(3, PolyCountingStatement::$executions);
        foreach ($comments as $comment) {
            self::assertTrue($comment->relationLoaded('commentable'));
        }
        self::assertSame(3, PolyCountingStatement::$executions);
    }

    public function testMorphOneAndMorphManyConstrainTypeEvenWhenNumericIdsMatch(): void
    {
        $post = PolyPost::find(1);
        $video = PolyVideo::find(1);
        $postComments = $post->__get('comments');
        $videoComments = $video->__get('comments');
        self::assertInstanceOf(ModelCollection::class, $postComments);
        self::assertInstanceOf(ModelCollection::class, $videoComments);
        self::assertCount(1, $postComments);
        self::assertSame('on post 1', $postComments[0]->getAttribute('body'));
        self::assertCount(1, $videoComments);
        self::assertSame('on video 1', $videoComments[0]->getAttribute('body'));
        self::assertSame('/post.png', $post->__get('image')->getAttribute('url'));
        self::assertSame('/video.png', $video->__get('image')->getAttribute('url'));

        $posts = PolyPost::query()->with(['comments', 'image'])->get();
        self::assertCount(2, $posts);
        self::assertInstanceOf(ModelCollection::class, $posts[0]->getRelation('comments'));
        self::assertTrue($posts[1]->relationLoaded('image'));
        self::assertNull($posts[1]->getRelation('image'));
    }

    public function testMorphToNestedPathBatchesByTargetClass(): void
    {
        PolyCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [PolyCountingStatement::class]);
        $comments = PolyComment::query()->with('commentable.author')->get();
        self::assertSame(5, PolyCountingStatement::$executions);
        self::assertSame('User 1', $comments[0]->getRelation('commentable')
            ->getRelation('author')->getAttribute('name'));
        self::assertSame('User 2', $comments[1]->getRelation('commentable')
            ->getRelation('author')->getAttribute('name'));
        self::assertSame(5, PolyCountingStatement::$executions);
    }

    public function testMixedModelCollectionLoadsEachTypeWithoutPerItemQueries(): void
    {
        $post = PolyPost::find(1);
        $video = PolyVideo::find(1);
        PolyCountingStatement::$executions = 0;
        $this->manager->connection()->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,
            [PolyCountingStatement::class]);
        $models = new ModelCollection([$post, $video]);
        self::assertSame($models, $models->load('author'));
        self::assertSame(2, PolyCountingStatement::$executions);
        self::assertSame('User 1', $post->getRelation('author')->getAttribute('name'));
        self::assertSame('User 2', $video->getRelation('author')->getAttribute('name'));
    }

    public function testUnknownStoredAliasFailsWithoutInstantiatingStoredClass(): void
    {
        $this->manager->table('poly_comments')->insert([
            'commentable_type' => '\\stdClass', 'commentable_id' => 1, 'body' => 'untrusted',
        ]);
        $this->expectException(RelationException::class);
        PolyComment::query()->with('commentable')->get();
    }

    public function testMissingAndSoftDeletedTargetsResolveNull(): void
    {
        $this->manager->table('poly_comments')->insert([
            'commentable_type' => 'post', 'commentable_id' => 999, 'body' => 'missing',
        ]);
        $post = PolyPost::find(1);
        self::assertTrue($post->delete());
        self::assertNull(PolyComment::find(1)->__get('commentable'));
        $comments = PolyComment::query()->with('commentable')->sort('id')->get();
        self::assertNull($comments[0]->getRelation('commentable'));
        self::assertNull($comments[4]->getRelation('commentable'));
        self::assertInstanceOf(PolyVideo::class, $comments[1]->getRelation('commentable'));
    }

    public function testRetainedModelUsesOriginManagerAfterAnotherApplicationIsSelected(): void
    {
        $comment = PolyComment::find(1);
        $post = PolyPost::find(1);
        $second = $this->manager();
        Database::setResolver(static fn (): DatabaseManager => $second);
        self::assertSame([], $second->morphMap()->classes());
        self::assertSame('Post 1', $comment->__get('commentable')->getAttribute('title'));
        self::assertCount(1, $post->__get('comments'));
        self::assertSame('post', $this->manager->morphMap()->aliasFor(PolyPost::class));
    }

    public function testMixedCollectionOfSameClassKeepsApplicationOriginsSeparate(): void
    {
        $firstComment = PolyComment::find(1);
        $second = $this->manager();
        $second->morphMap()->define('post', PolyPost::class);
        $second->schema()->create('poly_posts', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('title');
            $table->datetime('deleted_at')->nullable();
        });
        $second->table('poly_posts')->insert(['user_id' => 1, 'title' => 'Other App']);
        $secondComment = PolyComment::hydrate([
            'id' => 1,
            'commentable_type' => 'post',
            'commentable_id' => 1,
            'body' => 'other origin',
        ], $second->connection());

        Database::setResolver(static fn (): DatabaseManager => $second);
        (new ModelCollection([$firstComment, $secondComment]))->load('commentable');

        self::assertSame('Post 1', $firstComment->getRelation('commentable')->getAttribute('title'));
        self::assertSame('Other App', $secondComment->getRelation('commentable')->getAttribute('title'));
    }

    private function manager(): DatabaseManager
    {
        return new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
    }

    private function seed(): void
    {
        $this->manager->table('poly_users')->insert(['name' => 'User 1']);
        $this->manager->table('poly_users')->insert(['name' => 'User 2']);
        for ($id = 1; $id <= 2; $id++) {
            $this->manager->table('poly_posts')->insert(['user_id' => $id, 'title' => 'Post ' . $id]);
            $this->manager->table('poly_videos')->insert(['user_id' => $id === 1 ? 2 : 1,
                'title' => 'Video ' . $id]);
        }
        foreach ([
            ['post', 1, 'on post 1'], ['video', 1, 'on video 1'],
            ['post', 2, 'on post 2'], ['video', 2, 'on video 2'],
        ] as [$type, $id, $body]) {
            $this->manager->table('poly_comments')->insert([
                'commentable_type' => $type, 'commentable_id' => $id, 'body' => $body,
            ]);
        }
        $this->manager->table('poly_images')->insert([
            'imageable_type' => 'post', 'imageable_id' => 1, 'url' => '/post.png',
        ]);
        $this->manager->table('poly_images')->insert([
            'imageable_type' => 'video', 'imageable_id' => 1, 'url' => '/video.png',
        ]);
    }
}

final class PolyUser extends Model
{
    protected string $table = 'poly_users';
}

final class PolyPost extends Model
{
    protected string $table = 'poly_posts';
    protected bool $softDeletes = true;

    public function author(): BelongsTo
    {
        return $this->belongsTo(PolyUser::class, 'user_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(PolyComment::class, 'commentable');
    }

    public function image(): MorphOne
    {
        return $this->morphOne(PolyImage::class, 'imageable');
    }
}

final class PolyVideo extends Model
{
    protected string $table = 'poly_videos';

    public function author(): BelongsTo
    {
        return $this->belongsTo(PolyUser::class, 'user_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(PolyComment::class, 'commentable');
    }

    public function image(): MorphOne
    {
        return $this->morphOne(PolyImage::class, 'imageable');
    }
}

final class PolyComment extends Model
{
    protected string $table = 'poly_comments';

    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable');
    }
}

final class PolyImage extends Model
{
    protected string $table = 'poly_images';

    public function imageable(): MorphTo
    {
        return $this->morphTo('imageable');
    }
}

final class PolyCountingStatement extends PDOStatement
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
