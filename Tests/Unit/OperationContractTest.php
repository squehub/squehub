<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Api\Contract\ContractException;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use PHPUnit\Framework\TestCase;

/** Protects the immutable declaration boundary before routes are exported. */
final class OperationContractTest extends TestCase
{
    public function testFluentChangesDoNotMutateEarlierDeclarations(): void
    {
        $base = new OperationContract('users.show');
        $named = $base->summary('Read a user')->tags('Users');
        $withResponse = $named->response(200, Schema::string());

        self::assertNull($base->toArray()['summary']);
        self::assertSame([], $base->toArray()['tags']);
        self::assertSame([], $named->toArray()['responses']);
        self::assertSame(['Users'], $withResponse->toArray()['tags']);
        self::assertArrayHasKey(200, $withResponse->toArray()['responses']);
    }

    public function testStandard401UsesFinalPatPolicyRegardlessOfDeclarationOrder(): void
    {
        $before = (new OperationContract())->error(401)->pat(['users.read']);
        $after = (new OperationContract())->pat(['users.read'])->error(401);
        $session = (new OperationContract())->error(401)->pat()->session();

        foreach ([$before, $after] as $contract) {
            self::assertArrayHasKey('WWW-Authenticate',
                $contract->toArray()['responses'][401]['headers']);
            self::assertSame('pat', $contract->toArray()['security']['type']);
        }
        self::assertArrayNotHasKey('WWW-Authenticate',
            $session->toArray()['responses'][401]['headers']);
    }

    public function testStandardErrorsUseSharedSchemasAndExpectedHeaders(): void
    {
        $contract = (new OperationContract())
            ->error(422)->error(429)->error(405);
        $responses = $contract->toArray()['responses'];

        self::assertSame('#/components/schemas/SqueHubValidationError',
            $responses[422]['schema']['$ref']);
        self::assertSame('#/components/schemas/SqueHubApiError',
            $responses[429]['schema']['$ref']);
        self::assertArrayHasKey('Retry-After', $responses[429]['headers']);
        self::assertArrayHasKey('Allow', $responses[405]['headers']);
    }

    public function testDuplicateHeadersAreRejectedCaseInsensitively(): void
    {
        $this->expectException(ContractException::class);

        (new OperationContract())->header('X-Request-ID', Schema::string())
            ->header('x-request-id', Schema::string());
    }

    public function testDuplicateResponsesAreRejected(): void
    {
        $this->expectException(ContractException::class);

        (new OperationContract())->response(200, Schema::string())
            ->response(200, Schema::integer());
    }
}
