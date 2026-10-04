<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\Phase6G;

use App\Config\Repository;
use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\Factories\FactoryException;
use App\Database\Factories\FactoryRandom;
use App\Database\Factories\ModelFactory;
use App\Database\Model;
use App\Database\Schema\Table;
use App\Database\Seeding\SeederException;
use App\Database\Seeding\SeederRunner;
use App\Foundation\Application;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Real SQLite coverage for factories and the container-driven Seeder runner. */
final class SeederFactoryTest extends TestCase
{
    private DatabaseManager $manager;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $config = new Repository(['database' => [
            'default' => 'test',
            'connections' => [
                'test' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'archive' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ]]);
        $this->manager = new DatabaseManager($config);
        Database::setResolver(fn (): DatabaseManager => $this->manager);
        $this->manager->schema()->create('factory_users', static function (Table $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->unique('email');
            $table->string('status');
            $table->string('role');
            $table->boolean('active');
            $table->datetime('created_at')->nullable();
            $table->datetime('updated_at')->nullable();
            $table->datetime('deleted_at')->nullable();
        });
        $this->manager->schema('archive')->create('archive_factory_users', static function (Table $table): void {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Database::setResolver(null);
    }

    public function testMakeUsesIndependentUnsavedModelsAndNormalCasts(): void
    {
        $models = UserFactory::new()->seed(1234)->count(3)->make();
        self::assertInstanceOf(ModelCollection::class, $models);
        self::assertCount(3, $models);
        self::assertSame(0, $this->manager->table('factory_users')->count());
        self::assertNotSame($models[0], $models[1]);
        self::assertFalse($models[0]->exists());
        self::assertIsBool($models[0]->getAttribute('active'));
        self::assertSame($models->pluck('email'), UserFactory::new()->seed(1234)->count(3)->make()->pluck('email'));
        self::assertTrue($models[0]->save());
        self::assertSame(1, $this->manager->table('factory_users')->count());
    }

    public function testCreateCountStatesOverridesTimestampsAndSoftDeleteDefaults(): void
    {
        $factory = UserFactory::new()->seed(25)->state('admin')->state('disabled')->count(3);
        $models = $factory->create(['status' => 'active']);
        self::assertInstanceOf(ModelCollection::class, $models);
        self::assertCount(3, $models);
        self::assertSame(3, $this->manager->table('factory_users')->count());
        foreach ($models as $model) {
            self::assertTrue($model->exists());
            self::assertSame('admin', $model->getAttribute('role'));
            self::assertSame('active', $model->getAttribute('status'));
            self::assertFalse($model->isDeleted());
            self::assertNull($model->getAttribute('deleted_at'));
            self::assertNotNull($model->getAttribute('created_at'));
            self::assertSame($model->getAttribute('created_at'), $model->getAttribute('updated_at'));
        }
        self::assertCount(3, $models->toArray());
        self::assertSame(3, FactoryUser::query()->scope('active')->count());
        $one = UserFactory::new()->seed(33)->create(['email' => 'known@example.test']);
        self::assertInstanceOf(FactoryUser::class, $one);
        self::assertSame('known@example.test', $one->getAttribute('email'));
        self::assertSame(4, $this->manager->table('factory_users')->count());
    }

    public function testFactoryValidationAndBatchFailureDoNotPretendRollback(): void
    {
        foreach ([fn () => UserFactory::new()->count(0), fn () => UserFactory::new()->state('missing'),
                  fn () => InvalidUserFactory::new()->make(), fn () => MissingModelFactory::new()->make(),
                  fn () => InvalidStateFactory::new()->state('invalid')] as $invalid) {
            try {
                $invalid();
                self::fail('Expected invalid factory use.');
            } catch (FactoryException) {
            }
        }
        try {
            UserFactory::new()->count(3)->create(['email' => 'duplicate@example.test']);
            self::fail('Expected unique constraint failure.');
        } catch (\App\Database\Exception\QueryException) {
            self::assertSame(1, $this->manager->table('factory_users')->count());
        }
        try {
            $this->manager->transaction(static function (): void {
                UserFactory::new()->count(2)->create();
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            self::assertSame(1, $this->manager->table('factory_users')->count());
        }
        try {
            UserFactory::new()->make(['deleted_at' => '2020-01-01']);
            self::fail('Expected managed deletion-state rejection.');
        } catch (InvalidArgumentException) {
        }
    }

    public function testPerFactoryRandomSeedAndCommonHelpers(): void
    {
        $first = new FactoryRandom(901);
        $second = new FactoryRandom(901);
        $values = static fn (FactoryRandom $random): array => [
            $random->name(), $random->email(), $random->username(), $random->sentence(),
            $random->integer(1, 100), $random->boolean(), $random->uuid(),
            $random->date(), $random->datetime(), $random->element(['a', 'b']),
        ];
        self::assertSame($values($first), $values($second));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (new FactoryRandom(1))->uuid());
    }

    public function testFactoryUsesModelConfiguredConnection(): void
    {
        $user = ArchiveUserFactory::new()->create();
        self::assertInstanceOf(ArchiveFactoryUser::class, $user);
        self::assertTrue($user->exists());
        self::assertSame(1, $this->manager->table('archive_factory_users', 'archive')->count());
        self::assertSame(0, $this->manager->table('factory_users')->count());
    }

    public function testSeederOrderCycleSafetyContainerResolutionAndReversibleRollback(): void
    {
        $project = new TemporaryProject();
        try {
            $project->write('Config/App.php', "<?php return ['env' => 'testing'];");
            $project->write('Config/Database.php', "<?php return ['default' => 'test', 'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']]];");
            $project->write('Database/Seeders/DatabaseSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class DatabaseSeeder extends \App\Database\Seeding\Seeder {
    public function run(): void { $this->call([ASeeder::class, BSeeder::class, ASeeder::class]); }
}
PHP);
            $project->write('Database/Seeders/ASeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class ASeeder extends \App\Database\Seeding\Seeder {
    public function __construct(private \App\Config\Repository $config) {}
    public function run(): void {
        if ($this->config->get('app.env') !== 'testing') { throw new \RuntimeException('container injection failed'); }
        \App\Database\Database::manager()->table('seed_log')->insert(['name' => 'A']);
    }
}
PHP);
            $project->write('Database/Seeders/BSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class BSeeder extends \App\Database\Seeding\Seeder {
    public function run(): void { \App\Database\Database::manager()->table('seed_log')->insert(['name' => 'B']); }
}
PHP);
            $project->write('Database/Seeders/CycleA.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class CycleA extends \App\Database\Seeding\Seeder {
    public function run(): void { $this->call([CycleB::class]); }
}
PHP);
            $project->write('Database/Seeders/CycleB.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class CycleB extends \App\Database\Seeding\Seeder {
    public function run(): void { $this->call([CycleA::class]); }
}
PHP);
            $project->write('Database/Seeders/FailureSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class FailureSeeder extends \App\Database\Seeding\Seeder {
    public function run(): void {
        \App\Database\Database::manager()->table('seed_log')->insert(['name' => 'before-failure']);
        throw new \RuntimeException('private row value');
    }
}
PHP);
            $project->write('Database/Seeders/ConstructorFailSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class ConstructorFailSeeder extends \App\Database\Seeding\Seeder {
    public function __construct() { throw new \RuntimeException('private constructor detail'); }
    public function run(): void {}
}
PHP);
            $project->write('Database/Seeders/NotSeeder.php', '<?php namespace Database\Seeders; final class NotSeeder {}');
            $project->write('Database/Seeders/ReversibleSeeder.php', <<<'PHP'
<?php
namespace Database\Seeders;
final class ReversibleSeeder extends \App\Database\Seeding\Seeder implements \App\Database\Seeding\ReversibleSeeder {
    public function run(): void { \App\Database\Database::manager()->table('seed_log')->insert(['name' => 'reversible']); }
    public function rollback(): void { \App\Database\Database::manager()->table('seed_log')->filter('name', 'reversible')->delete(); }
}
PHP);
            $app = new Application($project->path());
            $app->register(\App\Database\DatabaseServiceProvider::class);
            $app->bootstrap();
            $this->manager = $app->container()->make(DatabaseManager::class);
            $this->manager->schema()->create('seed_log', static function (Table $table): void {
                $table->id(); $table->string('name');
            });
            $runner = $app->container()->make(SeederRunner::class);
            self::assertContains('DatabaseSeeder', $runner->available());
            self::assertContains('ReversibleSeeder', $runner->available());
            $done = $runner->run();
            self::assertSame(['A', 'B', 'A'], array_column($this->manager->table('seed_log')->all(), 'name'));
            self::assertCount(4, $done);
            self::assertSame('Database\\Seeders\\DatabaseSeeder', $done[3]);
            $runner->run('BSeeder');
            self::assertSame(4, $this->manager->table('seed_log')->count());
            try {
                $runner->run('CycleA');
                self::fail('Expected cycle rejection.');
            } catch (SeederException $exception) {
                self::assertStringContainsString('Seeder cycle:', $exception->getMessage());
            }
            foreach (['MissingSeeder', 'NotSeeder', 'ConstructorFailSeeder', 'FailureSeeder'] as $name) {
                try {
                    $runner->run($name);
                    self::fail('Expected Seeder failure.');
                } catch (SeederException $exception) {
                    self::assertStringContainsString($name, $exception->getMessage());
                    self::assertStringNotContainsString('private row value', $exception->getMessage());
                    if ($name === 'FailureSeeder') {
                        self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
                    }
                }
            }
            self::assertSame(5, $this->manager->table('seed_log')->count());
            try {
                $this->manager->transaction(static fn () => $runner->run('FailureSeeder'));
                self::fail('Expected Seeder failure inside caller transaction.');
            } catch (SeederException) {
                self::assertSame(5, $this->manager->table('seed_log')->count());
            }
            $app->config()->set('app.env', 'production');
            try {
                $runner->run('BSeeder');
                self::fail('Expected production refusal.');
            } catch (SeederException) {
                self::assertSame(5, $this->manager->table('seed_log')->count());
            }
            $runner->run('BSeeder', true);
            self::assertSame(6, $this->manager->table('seed_log')->count());
            $runner->run('ReversibleSeeder', true);
            self::assertSame(7, $this->manager->table('seed_log')->count());
            try {
                $runner->rollback('BSeeder', true);
                self::fail('Expected a nonreversible Seeder to refuse rollback.');
            } catch (SeederException) {
                self::assertSame(7, $this->manager->table('seed_log')->count());
            }
            try {
                $runner->rollback('ReversibleSeeder');
                self::fail('Expected production rollback to require --force.');
            } catch (SeederException) {
                self::assertSame(7, $this->manager->table('seed_log')->count());
            }
            $runner->rollback('ReversibleSeeder', true);
            self::assertSame(6, $this->manager->table('seed_log')->count());
        } finally {
            $project->remove();
        }
    }
}

final class FactoryUser extends Model
{
    protected string $table = 'factory_users';
    protected array $fillable = ['name', 'email', 'status', 'role', 'active'];
    protected array $casts = ['active' => 'boolean'];
    protected bool $timestamps = true;
    protected bool $softDeletes = true;
    protected static function scopes(): array
    {
        return ['active' => static fn (\App\Database\ModelQuery $query): \App\Database\ModelQuery =>
            $query->filter('status', 'active')];
    }
}

/** @extends ModelFactory<FactoryUser> */
final class UserFactory extends ModelFactory
{
    protected string $model = FactoryUser::class;
    protected function definition(): array
    {
        return [
            'name' => $this->fake()->name(),
            'email' => $this->fake()->email(),
            'status' => 'pending',
            'role' => 'member',
            'active' => $this->fake()->boolean(),
        ];
    }
    protected function states(): array
    {
        return ['admin' => ['role' => 'admin', 'status' => 'admin'],
            'disabled' => ['status' => 'disabled']];
    }
}

/** Exercises runtime validation against a binding supplied outside static analysis. */
final class InvalidUserFactory extends ModelFactory
{
    public function __construct()
    {
        parent::__construct();
        (new \ReflectionProperty(ModelFactory::class, 'model'))->setValue($this, \App\Core\Model::class);
    }

    protected function definition(): array { return ['name' => 'invalid']; }
}

/** A concrete factory without a model binding must fail with a framework error. */
final class MissingModelFactory extends ModelFactory
{
    protected function definition(): array { return ['name' => 'missing model']; }
}

/** A malformed state is rejected even when the state name itself is valid. */
final class InvalidStateFactory extends ModelFactory
{
    protected string $model = FactoryUser::class;
    protected function definition(): array { return ['name' => 'invalid state']; }
    protected function states(): array { return ['invalid' => 'not an attribute map']; }
}

final class ArchiveFactoryUser extends Model
{
    protected string $table = 'archive_factory_users';
    protected ?string $connection = 'archive';
    protected array $fillable = ['name'];
}

/** @extends ModelFactory<ArchiveFactoryUser> */
final class ArchiveUserFactory extends ModelFactory
{
    protected string $model = ArchiveFactoryUser::class;
    protected function definition(): array { return ['name' => 'Archive']; }
}
