<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Pagination;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\Pagination\Page;
use App\Database\Relations\BelongsToMany;
use App\Database\Relations\HasMany;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** Verifies offset pages on isolated SQLite tables and the existing eager-loader path. */
final class PaginationTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for pagination integration tests.');
        }
        $this->manager = new DatabaseManager(new Repository(['database' => [
            'default' => 'main',
            'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ]]));
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $pdo = $this->manager->connection()->pdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, status TEXT, secret TEXT, score INTEGER, note TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER, UNIQUE(user_id, role_id))');
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testRawTablePagesAndMetadata(): void
    {
        $this->seedUsers(5);
        $query = $this->manager->table('users')->sort('id');
        $originalSql = $query->toSql();
        $first = $query->page(1, 2);
        self::assertIsArray($first->items());
        self::assertSame([1, 2], array_map('intval', array_column($first->items(), 'id')));
        self::assertSame(1, $first->page());
        self::assertSame(2, $first->perPage());
        self::assertSame(5, $first->total());
        self::assertSame(3, $first->pages());
        self::assertSame(1, $first->from());
        self::assertSame(2, $first->to());
        self::assertTrue($first->hasNext());
        self::assertFalse($first->hasPrevious());
        self::assertSame(2, $first->nextPage());
        self::assertNull($first->previousPage());
        self::assertSame($originalSql, $query->toSql());

        $middle = $query->page(2, 2);
        self::assertSame([3, 4], array_map('intval', array_column($middle->items(), 'id')));
        self::assertSame(3, $middle->from());
        self::assertSame(4, $middle->to());
        self::assertTrue($middle->hasNext());
        self::assertTrue($middle->hasPrevious());
        $last = $query->page(3, 2);
        self::assertSame([5], array_map('intval', array_column($last->items(), 'id')));
        self::assertSame(5, $last->from());
        self::assertSame(5, $last->to());
        self::assertFalse($last->hasNext());
        self::assertSame(2, $last->previousPage());
        $beyond = $query->page(99, 2);
        self::assertSame([], $beyond->items());
        self::assertSame(99, $beyond->page());
        self::assertSame(5, $beyond->total());
        self::assertSame(3, $beyond->pages());
        self::assertNull($beyond->from());
        self::assertNull($beyond->to());
        self::assertTrue($beyond->hasPrevious());
        self::assertFalse($beyond->hasNext());
        self::assertSame(1, $query->page(1, 1)->to());
        self::assertSame($first->toArray(), $first->jsonSerialize());
        self::assertSame($first->toArray(), json_decode($first->toJson(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['items', 'page', 'per_page', 'total', 'pages', 'from', 'to', 'has_next', 'has_previous'], array_keys($first->toArray()));
    }

    public function testEmptyPageAndInvalidArguments(): void
    {
        $empty = $this->manager->table('users')->page(1, 20);
        self::assertSame([], $empty->items());
        self::assertSame(0, $empty->total());
        self::assertSame(0, $empty->pages());
        self::assertNull($empty->from());
        self::assertNull($empty->to());
        self::assertFalse($empty->hasNext());
        self::assertFalse($empty->hasPrevious());
        foreach ([[0, 20], [1, 0], [-1, 2], [PHP_INT_MAX, 2], [2, PHP_INT_MAX]] as [$number, $size]) {
            try {
                $this->manager->table('users')->page($number, $size);
                self::fail('Invalid pagination values must fail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFilteredTotalsAndTwoSelects(): void
    {
        $this->seedUsers(7);
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PageCountingStatement::class]);
        PageCountingStatement::$executions = 0;
        PageCountingStatement::$bound = [];
        $query = $this->manager->table('users')->filter('status', 'active')->sort('id', 'desc');
        $page = $query->page(2, 2);
        self::assertSame(4, $page->total());
        self::assertSame([3, 1], array_map('intval', array_column($page->items(), 'id')));
        self::assertSame(2, PageCountingStatement::$executions);
        self::assertSame(4, $query->count());
        self::assertCount(4, $query->all()); // The original builder retained no page bounds.

        self::assertSame(5, $this->manager->table('users')->filter('status', 'active')
            ->orFilter('id', 2)->page(1, 2)->total());
        self::assertSame(3, $this->manager->table('users')->filterIn('id', [1, 3, 5])
            ->page(1, 2)->total());
        self::assertSame(4, $this->manager->table('users')->filterNull('note')->page(1, 2)->total());
        self::assertSame(4, $this->manager->table('users')->select(['name'])->filter('status', 'active')
            ->page(1, 2)->total());
    }

    public function testUnsupportedShapesFailClearly(): void
    {
        $queries = [
            $this->manager->table('users')->limit(2),
            $this->manager->table('users')->skip(2),
            $this->manager->table('users')->skip(0),
            $this->manager->table('users')->distinct(),
            $this->manager->table('users')->group('status'),
            $this->manager->table('users')->group('status')->having('status', 'active'),
            $this->manager->table('users')->join('posts', 'users.id', '=', 'posts.user_id'),
        ];
        foreach ($queries as $query) {
            try {
                $query->page(1, 2);
                self::fail('Ambiguous pagination shape must fail.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(LogicException::class);
        PageUser::query()->limit(3)->page(1, 2);
    }

    public function testModelPageHydrationAndBoundedEagerLoading(): void
    {
        $this->seedUsers(10);
        for ($id = 1; $id <= 10; $id++) {
            $this->manager->table('posts')->insert(['user_id' => $id, 'title' => 'Post ' . $id]);
        }
        $this->manager->table('roles')->insert(['name' => 'reader']);
        $this->manager->table('roles')->insert(['name' => 'writer']);
        $this->manager->table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        $this->manager->table('user_roles')->insert(['user_id' => 2, 'role_id' => 1]);
        $this->manager->table('user_roles')->insert(['user_id' => 10, 'role_id' => 2]);
        $pdo = $this->manager->connection()->pdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PageCountingStatement::class]);
        PageCountingStatement::$executions = 0;
        PageCountingStatement::$bound = [];
        $query = PageUser::query()->with(['posts', 'roles'])->sort('id');
        $originalSql = $query->toSql();
        $page = $query->page(1, 2);
        self::assertSame($originalSql, $query->toSql());
        self::assertInstanceOf(Page::class, $page);
        self::assertInstanceOf(ModelCollection::class, $page->items());
        self::assertCount(2, $page->items());
        self::assertSame(10, $page->total());
        self::assertSame(5, $page->pages());
        self::assertSame(1, $page->items()[0]->id);
        self::assertSame(2, $page->items()[1]->id);
        self::assertInstanceOf(ModelCollection::class, $page->items()[0]->posts);
        self::assertInstanceOf(ModelCollection::class, $page->items()[0]->roles);
        self::assertSame('Post 1', $page->items()[0]->posts->first()->title);
        self::assertSame(1, $page->items()[1]->roles->first()->pivot()['role_id']);
        self::assertFalse($page->items()[0]->changed());
        self::assertLessThanOrEqual(6, PageCountingStatement::$executions);
        self::assertSame(5, PageCountingStatement::$executions);
        self::assertContains(1, array_map('intval', PageCountingStatement::$bound));
        self::assertContains(2, array_map('intval', PageCountingStatement::$bound));
        self::assertNotContains(10, array_map('intval', PageCountingStatement::$bound));
        self::assertArrayNotHasKey('secret', $page->toArray()['items'][0]);
        self::assertArrayNotHasKey('posts', $page->toArray()['items'][0]);
        self::assertArrayNotHasKey('roles', $page->toArray()['items'][0]);
        self::assertSame(1, $page->toArray()['items'][0]['score']);
        self::assertSame('2024-01-02T03:04:05Z', $page->toArray()['items'][0]['created_at']);
        self::assertSame($page->toArray(), json_decode($page->toJson(), true, 512, JSON_THROW_ON_ERROR));

        $last = PageUser::query()->with(['posts', 'roles'])->sort('id')->page(5, 2);
        self::assertSame(10, $last->items()->last()->id);
        self::assertSame('Post 10', $last->items()->last()->posts->first()->title);
        self::assertSame('writer', $last->items()->last()->roles->first()->name);
        self::assertInstanceOf(ModelCollection::class, PageUser::query()->filter('id', -1)->all());
        self::assertTrue(PageUser::query()->filter('id', -1)->get()->isEmpty());
        $emptyPage = PageUser::query()->filter('id', -1)->page(1, 2);
        self::assertInstanceOf(ModelCollection::class, $emptyPage->items());
        self::assertTrue($emptyPage->items()->isEmpty());
        self::assertSame(0, $emptyPage->pages());
        self::assertNull(PageUser::find(999));
        self::assertInstanceOf(PageUser::class, PageUser::query()->first());
        self::assertIsArray($this->manager->table('users')->all());
    }

    private function seedUsers(int $count): void
    {
        for ($id = 1; $id <= $count; $id++) {
            $this->manager->table('users')->insert([
                'name' => 'User ' . $id,
                'status' => $id % 2 === 1 ? 'active' : 'inactive',
                'secret' => 'secret-' . $id,
                'score' => $id,
                'note' => $id % 2 === 1 ? null : 'note',
                'created_at' => '2024-01-02 03:04:05',
            ]);
        }
    }
}

final class PageUser extends Model
{
    protected string $table = 'users';
    protected array $hidden = ['secret'];
    protected array $casts = ['id' => 'integer', 'score' => 'integer', 'created_at' => 'datetime'];

    public function posts(): HasMany
    {
        return $this->hasMany(PagePost::class, 'user_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PageRole::class, 'user_roles', 'user_id', 'role_id');
    }
}

final class PagePost extends Model
{
    protected string $table = 'posts';
}

final class PageRole extends Model
{
    protected string $table = 'roles';
}

/** Counts prepared statement executions in the pagination query plan. */
final class PageCountingStatement extends PDOStatement
{
    public static int $executions = 0;
    /** @var list<mixed> */
    public static array $bound = [];

    protected function __construct()
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        self::$bound[] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        self::$executions++;
        return parent::execute($params);
    }
}
