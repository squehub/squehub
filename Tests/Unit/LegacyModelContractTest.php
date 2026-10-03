<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Core\Model;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LegacyModelContractTest extends TestCase
{
    public function testStaticArrayCrudApiShapeWithoutOpeningDatabase(): void
    {
        $signatures = [
            'all' => 0,
            'where' => 2,
            'whereFirst' => 2,
            'find' => 1,
            'create' => 1,
            'update' => 2,
            'delete' => 1,
            'whereAll' => 2,
        ];
        foreach ($signatures as $method => $arguments) {
            $reflection = new ReflectionMethod(Model::class, $method);
            self::assertTrue($reflection->isStatic(), $method);
            self::assertSame($arguments, $reflection->getNumberOfRequiredParameters(), $method);
        }
        // Row values and SQL are deferred until an isolated database driver exists.
    }
}
