<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Routing\RouteCacheException;
use App\Routing\RouteCacheSource;
use PHPUnit\Framework\TestCase;

/** Route cache eligibility must prove a declaration-only PHP file. */
final class RouteCacheSourceTest extends TestCase
{
    public function testLiteralRoutesGroupsAndContractsAreEligible(): void
    {
        RouteCacheSource::assertCacheable(<<<'PHP'
<?php
declare(strict_types=1);
use App\Plugins\Route;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use Project\Controllers\UserController;

Route::path('/users/{id?}')->host('example.test')
    ->get([UserController::class, 'show'])
    ->named('users.show')->through(['auth'])
    ->where('id', 'integer')
    ->bind('id', \Project\Models\User::class);
Route::group()->prefix('/api')->apiVersion('v1')->routes(static function (): void {
    Route::path('/users')->get([UserController::class, 'index'])
        ->contract((new OperationContract('users.index'))
            ->summary('List users')
            ->response(200, Schema::array(Schema::string()), headers: ['X-Result' => Schema::string()]));
});
PHP, 'Project/Routes/Web.php');
        self::assertTrue(true);
    }

    /** @dataProvider rejectedSources */
    public function testDynamicOrSideEffectingPhpIsRejected(string $source): void
    {
        $this->expectException(RouteCacheException::class);
        RouteCacheSource::assertCacheable($source, 'Project/Routes/Web.php');
    }

    public static function rejectedSources(): array
    {
        return [
            ['<?php Route::path("/x")->get(static fn () => "x");'],
            ['<?php Route::path("/x")->get("Controller@show"); file_put_contents("marker", "x");'],
            ['<?php $path = "/x"; Route::path($path)->get("Controller@show");'],
            ['<?php Route::error(404, static fn () => "missing");'],
            ['<?php Route::path("/x")->get("Controller@show"); echo "hidden";'],
            ['<?php Route::path("/x")->get("Controller@show"); ?>outside'],
        ];
    }
}
