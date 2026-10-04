<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Relations;

use App\Config\Repository;
use App\Database\Database;
use App\Database\Collections\ModelCollection;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Relations\RelationException;
use App\Database\Schema\Table;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Exercises one-level relations against isolated SQLite connections and real model hydration. */
final class ModelRelationsTest extends TestCase
{
    private DatabaseManager $manager;
    private Repository $config;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for isolated relationship tests.');
        }
        $this->config = new Repository(['database' => [
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]);
        $this->manager = new DatabaseManager($this->config);
        Database::setResolver(fn (): DatabaseManager => $this->manager);

        $this->manager->schema()->create('users', static function (Table $table): void {
            $table->id();
            $table->string('uuid');
            $table->string('name');
            $table->integer('score')->nullable();
        });
        $this->manager->schema()->create('profiles', static function (Table $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('account_uuid')->nullable();
            $table->string('bio');
        });
        $this->manager->schema()->create('content_entries', static function (Table $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('owner_uuid')->nullable();
            $table->string('status');
        });
        $this->manager->schema('archive')->create('archive_notes', static function (Table $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('body');
        });
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testLazyDefinitionsCustomKeysAndConstrainedQueries(): void
    {
        $this->seed();
        $user = User::find(1);
        self::assertInstanceOf(User::class, $user);
        self::assertCount(2, $user->posts()->get());
        self::assertSame('published', $user->posts()->filter('status', 'published')->first()->status);
        self::assertCount(1, $user->posts()->where('status', 'published')->get());
        self::assertInstanceOf(Profile::class, $user->profile()->first());
        self::assertSame('custom', $user->customProfile()->first()->bio);
        self::assertCount(1, $user->customPosts()->get());
        self::assertSame('published', $user->customPosts()->first()->status);

        $post = Post::find(1);
        self::assertSame('Ada', $post->author()->first()->name);
        self::assertSame('Ada', $post->user()->first()->name);
        self::assertSame('Ada', $post->owner()->first()->name);
        self::assertSame('Ada', $post->author()->get()->name);
        self::assertSame('Bob', Post::find(3)->author()->first()->name);
        self::assertNull(Post::find(4)->author()->first());
        self::assertNull(Post::find(5)->author()->first());
        self::assertTrue(User::find(3)->posts()->get()->isEmpty());
        self::assertNull(User::find(3)->profile()->first());

        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RelationCountingStatement::class]);
        $orphan = Post::find(4);
        $before = RelationCountingStatement::$executions;
        self::assertNull($orphan->author()->first());
        self::assertSame($orphan, $orphan->load('author'));
        self::assertTrue($orphan->relationLoaded('author'));
        self::assertNull($orphan->author);
        self::assertSame($before, RelationCountingStatement::$executions);
    }

    public function testLazyPropertyCachesAndNeverChangesAttributes(): void
    {
        $this->seed();
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RelationCountingStatement::class]);
        $user = User::find(1);
        $before = RelationCountingStatement::$executions;
        self::assertFalse($user->relationLoaded('posts'));
        self::assertInstanceOf(ModelCollection::class, $user->posts);
        $cachedPosts = $user->posts;
        self::assertCount(2, $cachedPosts);
        self::assertSame($before + 1, RelationCountingStatement::$executions);
        self::assertTrue($user->relationLoaded('posts'));
        self::assertCount(2, $user->posts);
        self::assertSame($cachedPosts, $user->posts);
        self::assertSame($before + 1, RelationCountingStatement::$executions);
        self::assertFalse($user->changed());
        self::assertSame([], $user->changes());
        self::assertArrayNotHasKey('posts', $user->attributes());
        self::assertArrayNotHasKey('posts', $user->toArray());
        self::assertTrue($user->save());
        self::assertSame($before + 1, RelationCountingStatement::$executions);

        self::assertSame($user, $user->setRelation('posts', []));
        self::assertSame([], $user->posts);
        self::assertSame($user, $user->unsetRelation('posts'));
        self::assertFalse($user->relationLoaded('posts'));
        self::assertCount(2, $user->posts);
        self::assertSame($before + 2, RelationCountingStatement::$executions);

        $withoutProfile = User::find(3);
        $before = RelationCountingStatement::$executions;
        self::assertNull($withoutProfile->profile);
        self::assertTrue($withoutProfile->relationLoaded('profile'));
        self::assertNull($withoutProfile->profile);
        self::assertSame($before + 1, RelationCountingStatement::$executions);

        $shadow = User::hydrate(['id' => 1, 'posts' => 'attribute']);
        $shadow->setRelation('posts', ['cached']);
        self::assertSame('attribute', $shadow->posts);
        self::assertTrue($shadow->relationLoaded('posts'));
        self::assertSame(['cached'], $shadow->getRelation('posts'));
        self::assertSame('attribute', $shadow->attributes()['posts']);
        self::assertFalse($shadow->changed());
    }

    public function testEagerLoadsOneQueryPerRelationAndMatchesMixedParents(): void
    {
        $this->seed();
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RelationCountingStatement::class]);
        $before = RelationCountingStatement::$executions;
        $users = User::query()->with(['posts', 'profile'])->sort('id')->get();
        self::assertCount(3, $users);
        self::assertSame($before + 3, RelationCountingStatement::$executions);
        self::assertCount(2, $users[0]->posts);
        self::assertInstanceOf(Profile::class, $users[0]->profile);
        self::assertCount(1, $users[1]->posts);
        self::assertNull($users[1]->profile);
        self::assertTrue($users[2]->posts->isEmpty());
        self::assertNull($users[2]->profile);
        self::assertFalse($users[0]->changed());
        self::assertFalse($users[1]->changed());
        self::assertFalse($users[2]->changed());

        $before = RelationCountingStatement::$executions;
        self::assertTrue(User::query()->with('posts')->filter('id', -1)->get()->isEmpty());
        self::assertSame($before + 1, RelationCountingStatement::$executions);

        $before = RelationCountingStatement::$executions;
        $posts = Post::query()->with('author')->sort('id')->all();
        self::assertCount(5, $posts);
        self::assertSame($before + 2, RelationCountingStatement::$executions);
        self::assertSame('Ada', $posts[0]->author->name);
        self::assertSame('Ada', $posts[1]->author->name);
        self::assertSame('Bob', $posts[2]->author->name);
        self::assertNull($posts[3]->author);
        self::assertNull($posts[4]->author);
        self::assertSame($before + 2, RelationCountingStatement::$executions);
    }

    public function testLargeEagerLoadChunksKeysWithoutPerParentQueries(): void
    {
        $this->manager->transaction(function (): void {
            for ($id = 1; $id <= 501; $id++) {
                $this->manager->table('users')->insert(['uuid' => 'u-' . $id, 'name' => 'User ' . $id]);
            }
            $this->manager->table('content_entries')->insert(['user_id' => 1, 'status' => 'first']);
            $this->manager->table('content_entries')->insert(['user_id' => 501, 'status' => 'last']);
        });
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RelationCountingStatement::class]);
        $before = RelationCountingStatement::$executions;
        $users = User::query()->with('posts')->sort('id')->get();
        self::assertCount(501, $users);
        self::assertSame($before + 3, RelationCountingStatement::$executions);
        self::assertCount(1, $users[0]->posts);
        self::assertTrue($users[1]->posts->isEmpty());
        self::assertCount(1, $users[500]->posts);
    }

    public function testLoadPartialRowsMissingKeysAndNullKeys(): void
    {
        $this->seed();
        $user = User::find(1);
        self::assertSame($user, $user->load(['posts', 'profile']));
        self::assertTrue($user->relationLoaded('posts'));
        self::assertTrue($user->relationLoaded('profile'));
        self::assertCount(2, $user->posts);
        $snapshot = $user->original();
        $user->unsetRelation('posts');
        self::assertFalse($user->relationLoaded('posts'));
        self::assertTrue($user->relationLoaded('profile'));
        self::assertSame($snapshot, $user->original());

        $partial = User::query()->select(['id', 'name'])->with('posts')->first();
        self::assertCount(2, $partial->posts);
        $withoutKey = User::query()->select(['name'])->with(['posts', 'profile'])->first();
        self::assertTrue($withoutKey->posts->isEmpty());
        self::assertNull($withoutKey->profile);
        self::assertFalse($withoutKey->changed());
        self::assertNull($withoutKey->posts()->first());

        $post = Post::query()->select(['id', 'status'])->with('author')->first();
        self::assertNull($post->author);
        self::assertFalse($post->changed());
        $postWithKey = Post::query()->select(['id', 'user_id'])->with('author')->first();
        self::assertSame('Ada', $postWithKey->author->name);
        self::assertNull(Post::find(4)->author);
        self::assertNull(Post::find(5)->author);
    }

    public function testRelatedConnectionAndRefreshCacheContracts(): void
    {
        $this->seed();
        $user = User::query()->with('archiveNotes')->first();
        self::assertCount(1, $user->archiveNotes);
        self::assertSame('archived', $user->archiveNotes[0]->body);
        self::assertTrue($user->relationLoaded('archiveNotes'));

        $this->config->set('database.default', 'archive');
        $user->name = 'pending';
        $this->manager->table('users', 'main')->filter('id', 1)->update(['score' => 'invalid']);
        try {
            $user->refresh();
            self::fail('Invalid cast must make refresh fail.');
        } catch (\App\Database\Casts\CastException) {
            self::assertTrue($user->relationLoaded('archiveNotes'));
            self::assertSame('pending', $user->name);
        }
        $this->manager->table('users', 'main')->filter('id', 1)->update(['score' => 3, 'name' => 'database']);
        self::assertSame($user, $user->refresh());
        self::assertFalse($user->relationLoaded('archiveNotes'));
        self::assertSame('database', $user->name);
        self::assertSame(3, $user->score);
        self::assertFalse($user->changed());

        $user->load('archiveNotes');
        self::assertCount(1, $user->archiveNotes);
        $user->name = 'updated';
        self::assertTrue($user->save());
        self::assertSame('updated', $this->manager->table('users', 'main')->filter('id', 1)->first()['name']);
        self::assertSame('Ada', $this->manager->table('users', 'archive')->first()['name']);
    }

    public function testRelationStateDoesNotResurrectDeletedModelAndErrorsAreClear(): void
    {
        $this->seed();
        $user = User::find(3);
        $user->setRelation('posts', []);
        self::assertFalse($user->changed());
        self::assertTrue($user->delete());
        $this->expectException(\LogicException::class);
        $user->save();
    }

    public function testInvalidRelationDefinitionsFailBeforeQuery(): void
    {
        $user = new User();
        foreach (['absent', 'invalid', 'badClass'] as $name) {
            try {
                $user->load($name);
                self::fail('Invalid relation should fail.');
            } catch (RelationException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        try {
            User::query()->with('absent')->filter('id', -1)->get();
            self::fail('Unknown eager relation must fail even when no parent rows match.');
        } catch (RelationException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        try {
            User::query()->with('absent')->filter('id', -1)->first();
            self::fail('Unknown eager relation must fail for an empty first() result.');
        } catch (RelationException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        $this->expectException(RelationException::class);
        User::query()->with('posts.comments')->get();
    }

    private function seed(): void
    {
        foreach ([
            ['uuid' => 'u-1', 'name' => 'Ada'],
            ['uuid' => 'u-2', 'name' => 'Bob'],
            ['uuid' => 'u-3', 'name' => 'Cat'],
        ] as $user) {
            $this->manager->table('users')->insert($user);
        }
        $this->manager->table('profiles')->insert(['user_id' => 1, 'account_uuid' => null, 'bio' => 'main']);
        $this->manager->table('profiles')->insert(['user_id' => null, 'account_uuid' => 'u-1', 'bio' => 'custom']);
        $this->manager->table('content_entries')->insert(['user_id' => 1, 'owner_uuid' => 'u-1', 'status' => 'published']);
        $this->manager->table('content_entries')->insert(['user_id' => 1, 'owner_uuid' => null, 'status' => 'draft']);
        $this->manager->table('content_entries')->insert(['user_id' => 2, 'owner_uuid' => 'u-2', 'status' => 'published']);
        $this->manager->table('content_entries')->insert(['user_id' => null, 'owner_uuid' => null, 'status' => 'orphan']);
        $this->manager->table('content_entries')->insert(['user_id' => 99, 'owner_uuid' => null, 'status' => 'missing']);
        $this->manager->table('archive_notes', 'archive')->insert(['user_id' => 1, 'body' => 'archived']);
        $this->manager->schema('archive')->create('users', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
        $this->manager->table('users', 'archive')->insert(['name' => 'Ada']);
    }
}

final class User extends Model
{
    protected string $table = 'users';
    protected array $casts = ['id' => 'integer', 'score' => 'integer'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): \App\Database\Relations\HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function customPosts(): \App\Database\Relations\HasMany
    {
        return $this->hasMany(Post::class, 'owner_uuid', 'uuid');
    }

    public function customProfile(): \App\Database\Relations\HasOne
    {
        return $this->hasOne(Profile::class, 'account_uuid', 'uuid');
    }

    public function archiveNotes(): \App\Database\Relations\HasMany
    {
        return $this->hasMany(ArchiveNote::class);
    }

    public function invalid(): string
    {
        return 'invalid';
    }

    public function badClass(): \App\Database\Relations\HasMany
    {
        return $this->hasMany(\stdClass::class);
    }
}

final class Post extends Model
{
    protected string $table = 'content_entries';
    protected array $casts = ['user_id' => 'integer'];

    public function author(): \App\Database\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function user(): \App\Database\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): \App\Database\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_uuid', 'uuid');
    }
}

final class Profile extends Model
{
    protected string $table = 'profiles';
}

final class ArchiveNote extends Model
{
    protected string $table = 'archive_notes';
    protected ?string $connection = 'archive';
}

/** Counts executed PDO statements so eager-loading tests fail on N+1 behavior. */
final class RelationCountingStatement extends PDOStatement
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
