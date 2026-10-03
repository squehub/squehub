<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\MySqlRelationDepth;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelQuery;
use App\Database\Relations\BelongsTo;
use App\Database\Relations\HasMany;
use App\Database\Relations\HasManyThrough;
use App\Database\Relations\HasOneThrough;
use App\Database\Relations\MorphTo;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Qualification on an explicitly confirmed, empty MySQL database only. No
 * application database configuration or environment file is consulted.
 *
 * @group mysql
 */
final class MySqlRelationDepthOptInTest extends TestCase
{
    public function testNestedExistenceCountsThroughAndMorphOnDisposableMySql(): void
    {
        if (getenv('SQUEHUB_TEST_MYSQL_ENABLED') !== '1') {
            self::markTestSkipped('Set SQUEHUB_TEST_MYSQL_ENABLED=1 and explicit disposable test database settings.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_mysql is required for the opt-in MySQL integration test.');
        }

        $host = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_HOST');
        $port = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_PORT');
        $database = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_DATABASE');
        $user = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_USER');
        $password = getenv('SQUEHUB_TEST_MYSQL_PASSWORD');
        $confirmation = $this->requiredEnvironment('SQUEHUB_TEST_MYSQL_CONFIRM_DATABASE');
        self::assertNotFalse($password, 'Set SQUEHUB_TEST_MYSQL_PASSWORD explicitly, even when empty.');
        self::assertMatchesRegularExpression('/\Asquehub_test_[A-Za-z0-9_]+\z/D', $database);
        self::assertSame($database, $confirmation, 'Test database confirmation must match exactly.');

        $manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'disposable',
            'connections' => ['disposable' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'database' => $database, 'username' => $user,
                'password' => $password, 'charset' => 'utf8mb4',
            ]],
        ]]));
        $pdo = $manager->connection()->pdo();
        self::assertSame($database, $pdo->query('SELECT DATABASE()')->fetchColumn());
        $version = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        $comment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
        self::assertStringNotContainsString('mariadb', $version . $comment, 'This test verifies MySQL semantics.');
        self::assertSame('innodb', strtolower((string) $pdo->query('SELECT @@default_storage_engine')->fetchColumn()));

        // All opt-in database tests share this lock, so two cooperating test
        // processes cannot both pass the empty-schema guard before DDL begins.
        $lockName = 'squehub_phase6a_' . substr(hash('sha256', $database), 0, 40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        self::assertSame(1, (int) $lock->fetchColumn(), 'Another SqueHub MySQL test is using this database.');
        try {
            self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM),
                'The confirmed test database must be empty before this test may create tables.');
            try {
                $this->createSchema($pdo);
                Database::setResolver(static fn (): DatabaseManager => $manager);
                $manager->morphMap()->define('post', MySqlDepthPost::class);
                $manager->morphMap()->define('country', MySqlDepthCountry::class);
                $this->seed($manager);

                self::assertSame([1, 2], $this->ids(MySqlDepthCountry::query()->has('posts')));
                self::assertSame([1], $this->ids(MySqlDepthCountry::query()->has('profile')));
                self::assertSame([1, 2], $this->ids(MySqlDepthCountry::query()
                    ->has('posts.comments.commentable')));
                self::assertSame([3], $this->ids(MySqlDepthCountry::query()
                    ->doesntHave('posts.comments.commentable')));
                self::assertSame([1, 2], $this->ids(MySqlDepthCountry::query()
                    ->whereHas('posts.comments', static fn (ModelQuery $comments): ModelQuery =>
                        $comments->filter('body', 'approved'))));

                $countries = MySqlDepthCountry::query()->withCount('posts')->sort('id')->get();
                $counts = [];
                foreach ($countries as $countedCountry) {
                    $counts[] = (int) $countedCountry->getAttribute('posts_count');
                }
                self::assertSame([2, 1, 0], $counts);
                $country = MySqlDepthCountry::find(1);
                self::assertInstanceOf(MySqlDepthCountry::class, $country);
                self::assertSame([1, 2], array_map('intval', $country->posts->pluck('id')));
                self::assertSame(1, (int) $country->profile?->getAttribute('id'));
                self::assertSame(2, (int) MySqlDepthPost::query()->withCount('comments')
                    ->filter('id', 1)->first()?->getAttribute('comments_count'));

                self::assertSame([1, 2, 3, 4, 7], $this->ids(MySqlDepthComment::query()
                    ->has('commentable')));
                self::assertSame([5, 6], $this->ids(MySqlDepthComment::query()
                    ->doesntHave('commentable')));
                self::assertSame([1, 3, 4], $this->ids(MySqlDepthComment::query()
                    ->whereHasMorph('commentable', [
                        'post' => static fn (ModelQuery $posts): ModelQuery => $posts->scope('published'),
                    ])));
                self::assertSame([7], $this->ids(MySqlDepthComment::query()
                    ->whereHasMorph('commentable', ['country' => null])));
            } finally {
                Database::setResolver(null);
                // Only these exact tables may have been created after the
                // empty-schema guard; never drop unrelated application data.
                foreach (['phase16r_comments', 'phase16r_profiles', 'phase16r_posts',
                    'phase16r_users', 'phase16r_countries'] as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // Closing this PDO also releases its advisory lock.
            }
            $manager->disconnect();
        }
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE phase16r_countries (id BIGINT PRIMARY KEY, name VARCHAR(80) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE phase16r_users (
            id BIGINT PRIMARY KEY, country_id BIGINT, name VARCHAR(80) NOT NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE phase16r_posts (
            id BIGINT PRIMARY KEY, user_id BIGINT, status VARCHAR(30) NOT NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE phase16r_profiles (
            id BIGINT PRIMARY KEY, user_id BIGINT, deleted_at DATETIME NULL
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE phase16r_comments (
            id BIGINT PRIMARY KEY, post_id BIGINT, commentable_type VARCHAR(80),
            commentable_id BIGINT, body VARCHAR(80), deleted_at DATETIME NULL
        ) ENGINE=InnoDB');
    }

    private function seed(DatabaseManager $manager): void
    {
        $manager->table('phase16r_countries')->insertMany([
            ['id' => 1, 'name' => 'Aland'], ['id' => 2, 'name' => 'Bland'],
            ['id' => 3, 'name' => 'Cland'],
        ]);
        $manager->table('phase16r_users')->insertMany([
            ['id' => 1, 'country_id' => 1, 'name' => 'Ada', 'deleted_at' => null],
            ['id' => 2, 'country_id' => 1, 'name' => 'Bea', 'deleted_at' => '2024-01-01 00:00:00'],
            ['id' => 3, 'country_id' => 2, 'name' => 'Cy', 'deleted_at' => null],
        ]);
        $manager->table('phase16r_posts')->insertMany([
            ['id' => 1, 'user_id' => 1, 'status' => 'published', 'deleted_at' => null],
            ['id' => 2, 'user_id' => 1, 'status' => 'draft', 'deleted_at' => null],
            ['id' => 3, 'user_id' => 2, 'status' => 'published', 'deleted_at' => null],
            ['id' => 4, 'user_id' => 3, 'status' => 'published', 'deleted_at' => '2024-01-01 00:00:00'],
            ['id' => 5, 'user_id' => 3, 'status' => 'published', 'deleted_at' => null],
        ]);
        $manager->table('phase16r_profiles')->insertMany([
            ['id' => 1, 'user_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'user_id' => 2, 'deleted_at' => null],
            ['id' => 3, 'user_id' => 3, 'deleted_at' => '2024-01-01 00:00:00'],
        ]);
        $manager->table('phase16r_comments')->insertMany([
            ['id' => 1, 'post_id' => 1, 'commentable_type' => 'post', 'commentable_id' => 1,
                'body' => 'approved', 'deleted_at' => null],
            ['id' => 2, 'post_id' => 2, 'commentable_type' => 'post', 'commentable_id' => 2,
                'body' => 'pending', 'deleted_at' => null],
            ['id' => 3, 'post_id' => 3, 'commentable_type' => 'post', 'commentable_id' => 3,
                'body' => 'pending', 'deleted_at' => null],
            ['id' => 4, 'post_id' => 5, 'commentable_type' => 'post', 'commentable_id' => 5,
                'body' => 'approved', 'deleted_at' => null],
            ['id' => 5, 'post_id' => 5, 'commentable_type' => 'App\\Database\\Model', 'commentable_id' => 1,
                'body' => 'untrusted', 'deleted_at' => null],
            ['id' => 6, 'post_id' => 1, 'commentable_type' => 'post', 'commentable_id' => 4,
                'body' => 'orphaned', 'deleted_at' => null],
            ['id' => 7, 'post_id' => 5, 'commentable_type' => 'country', 'commentable_id' => 1,
                'body' => 'country target', 'deleted_at' => null],
        ]);
    }

    /** @return list<int> */
    private function ids(ModelQuery $query): array
    {
        return array_map('intval', $query->sort('id')->all()->pluck('id'));
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);
        self::assertIsString($value, "Set {$key} for the disposable MySQL test.");
        self::assertNotSame('', $value, "Set {$key} for the disposable MySQL test.");
        return $value;
    }
}

/**
 * @property-read ModelCollection $posts
 * @property-read MySqlDepthProfile|null $profile
 */
final class MySqlDepthCountry extends Model
{
    protected string $table = 'phase16r_countries';

    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(MySqlDepthPost::class, MySqlDepthUser::class,
            'country_id', 'user_id', 'id', 'id');
    }

    public function profile(): HasOneThrough
    {
        return $this->hasOneThrough(MySqlDepthProfile::class, MySqlDepthUser::class,
            'country_id', 'user_id', 'id', 'id');
    }
}

final class MySqlDepthUser extends Model
{
    protected string $table = 'phase16r_users';
    protected bool $softDeletes = true;
}

final class MySqlDepthPost extends Model
{
    protected string $table = 'phase16r_posts';
    protected bool $softDeletes = true;

    protected static function scopes(): array
    {
        return ['published' => static fn (ModelQuery $query): ModelQuery =>
            $query->filter('status', 'published')];
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MySqlDepthComment::class, 'post_id');
    }
}

final class MySqlDepthProfile extends Model
{
    protected string $table = 'phase16r_profiles';
    protected bool $softDeletes = true;
}

final class MySqlDepthComment extends Model
{
    protected string $table = 'phase16r_comments';
    protected bool $softDeletes = true;

    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(MySqlDepthPost::class, 'post_id');
    }
}
