<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Api\Contract\Contract;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\ContractVerifier;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Contract operations remain portable while verifier traffic uses the mount. */
final class BasePathContractVerificationTest extends TestCase
{
    private TemporaryProject $project;

    protected function tearDown(): void
    {
        try {
            Contract::setResolver(null);
            Route::setResolver(null);
            if (isset($this->project)) {
                $this->project->remove();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testStaticContractPathsStayPortableAndRuntimeCaseUsesMountedKernel(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "testing", "debug" => false];');
        $this->project->write('Config/Http.php', '<?php return ["base_path" => "/app"];');
        $this->project->write('Config/Api.php', '<?php return ["enabled" => true, "paths" => ["/api"]];');
        $app = new Application($this->project->path());
        foreach ([HttpServiceProvider::class, RoutingServiceProvider::class,
            ContractServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $runs = 0;
        $app->container()->make(RouteRegistry::class)->get('/api/users/{id}',
            static function () use (&$runs): JsonResponse {
                ++$runs;
                return new JsonResponse(['id' => 7]);
            })->named('users.show')->contract((new OperationContract())
                ->path('id', Schema::integer())
                ->response(200, Schema::object(['id' => Schema::integer()])->required(['id'])));
        $manager = $app->container()->make(ContractManager::class);
        $manager->verify('users.show.success')->operation('users.show')->route(['id' => 7]);

        $contract = $manager->openApi();
        self::assertArrayHasKey('/api/users/{id}', $contract['paths']);
        self::assertArrayNotHasKey('/app/api/users/{id}', $contract['paths']);
        self::assertSame(0, $runs);

        $static = $app->container()->make(ContractVerifier::class)->verify(staticOnly: true);
        self::assertTrue($static->passed(), $static->toText());
        self::assertSame(0, $runs);
        $dynamic = $app->container()->make(ContractVerifier::class)->verify();
        self::assertTrue($dynamic->passed(), $dynamic->toText());
        self::assertSame(1, $runs);
        self::assertSame(1, $dynamic->toArray()['summary']['cases_passed']);
    }
}
