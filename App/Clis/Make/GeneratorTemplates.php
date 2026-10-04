<?php

declare(strict_types=1);

namespace App\Clis\Make;

/** Framework-owned skeletons built only from validated PHP identifiers. */
final class GeneratorTemplates
{
    /**
     * Composite-only skeletons use the same renderer as single-file generators.
     * The API response names only the generated id column; future fields require
     * an explicit application edit rather than automatic Model serialization.
     */
    public static function feature(
        string $kind,
        string $baseNamespace,
        string $class,
        string $table,
        string $route,
        string $routeName,
        string $routeSource,
        string $migrationSource,
        string $testClass,
        string $fixtureRouteSource
    ): string {
        $content = match ($kind) {
            'model' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{BASE}}\Models;

use App\Plugins\Model;

/** {{CLASS}} records use the explicit table chosen by the Feature Blueprint. */
final class {{CLASS}} extends Model
{
    protected string $table = '{{TABLE}}';
}
PHP,
            'controller' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{BASE}}\Controllers;

use App\Plugins\Response;
use {{BASE}}\Api\Resources\{{CLASS}}Resource;
use {{BASE}}\Models\{{CLASS}};

/** A read-only starting point; application writes and rules are deliberate additions. */
final class {{CLASS}}Controller
{
    public function index(): Response
    {
        return {{CLASS}}Resource::collection({{CLASS}}::query()->page(1, 20))->response();
    }
}
PHP,
            'resource' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{BASE}}\Api\Resources;

use App\Plugins\ApiResource;
use {{BASE}}\Models\{{CLASS}};
use InvalidArgumentException;

/** Public representation starts with the generated key alone. Add fields explicitly. */
final class {{CLASS}}Resource extends ApiResource
{
    public function toArray(): array
    {
        if (!$this->resource instanceof {{CLASS}}) {
            throw new InvalidArgumentException('{{CLASS}}Resource needs a {{CLASS}} model.');
        }

        return ['id' => $this->resource->getAttribute('id')];
    }
}
PHP,
            'validation' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{BASE}}\Validation;

use App\Plugins\Request;

/** Add application rules before calling this from a future write handler. */
final class {{CLASS}}Validation
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public static function validate(Request $request): array
    {
        return $request->validate(self::rules());
    }
}
PHP,
            'route' => <<<'PHP'
<?php

declare(strict_types=1);

use App\Plugins\Route;
use {{BASE}}\Controllers\{{CLASS}}Controller;

Route::path('{{ROUTE}}')
    ->get([{{CLASS}}Controller::class, 'index'])
    ->named('{{ROUTE_NAME}}');
PHP,
            'test' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Project\Tests\Integration;

use App\Plugins\Request;
use App\Plugins\TestCase;
use {{BASE}}\Validation\{{CLASS}}Validation;

/** The disposable fixture exercises the generated migration, route, and HTTP Kernel. */
final class {{TEST_CLASS}} extends TestCase
{
    public function test_generated_feature_read_route_and_validation_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root . '/{{MIGRATION_SOURCE}}');
        $route = file_get_contents($root . '/{{ROUTE_SOURCE}}');
        self::assertIsString($migration);
        self::assertIsString($route);

        $this->testApplication()->write('{{MIGRATION_SOURCE}}', $migration);
        $this->testApplication()->write('{{FIXTURE_ROUTE_SOURCE}}', $route);
        $this->migrate();

        $this->getJson('{{ROUTE}}')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 0);
        self::assertSame([], {{CLASS}}Validation::validate(new Request('POST', '{{ROUTE}}')));
    }
}
PHP,
            default => throw new GeneratorException('Unknown Feature Blueprint source kind.'),
        };

        return strtr($content, [
            '{{BASE}}' => $baseNamespace,
            '{{CLASS}}' => $class,
            '{{TABLE}}' => $table,
            '{{ROUTE}}' => $route,
            '{{ROUTE_NAME}}' => $routeName,
            '{{ROUTE_SOURCE}}' => $routeSource,
            '{{MIGRATION_SOURCE}}' => $migrationSource,
            '{{TEST_CLASS}}' => $testClass,
            '{{FIXTURE_ROUTE_SOURCE}}' => $fixtureRouteSource,
        ]) . "\n";
    }

    public static function render(string $kind, string $namespace, string $class, ?string $table = null): string
    {
        $content = match ($kind) {
            'controller' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

final class {{CLASS}}
{
    public function index(): string
    {
        return '';
    }
}
PHP,
            'model' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use App\Plugins\Model;

final class {{CLASS}} extends Model
{
}
PHP,
            'middleware' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use App\Plugins\Request;
use App\Plugins\Response;
use Closure;

final class {{CLASS}}
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
PHP,
            'seeder' => <<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use App\Plugins\Seeder;

final class {{CLASS}} extends Seeder
{
    public function run(): void
    {
    }
}
PHP,
            'migration' => self::migration($table),
            default => throw new GeneratorException('Unknown generator.'),
        };

        return strtr($content, ['{{NAMESPACE}}' => $namespace, '{{CLASS}}' => $class]) . "\n";
    }

    private static function migration(?string $table): string
    {
        if ($table === null) {
            return <<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use App\Plugins\Schema;
use PDO;

final class {{CLASS}}
{
    public function up(PDO $pdo, Schema $schema): void
    {
    }

    public function down(PDO $pdo, Schema $schema): void
    {
    }
}
PHP;
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use App\Plugins\Schema;
use App\Plugins\Table;
use PDO;

final class {{CLASS}}
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('{{TABLE}}', static function (Table $table): void {
            $table->id();
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('{{TABLE}}');
    }
}
PHP, ['{{TABLE}}' => $table]);
    }
}
