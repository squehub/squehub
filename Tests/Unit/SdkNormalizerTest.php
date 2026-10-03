<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Api\Sdk\SdkException;
use App\Api\Sdk\SdkNormalizer;
use PHPUnit\Framework\TestCase;

final class SdkNormalizerTest extends TestCase
{
    public function testNativeOperationsAreNormalizedAndPrivateAnnotationsAreDropped(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['User']['properties']['name']['description'] = 'private example';
        $artifact['schemas']['User']['properties']['name']['examples'] = ['private example'];
        $artifact['schemas']['User']['properties']['name']['default'] = 'private example';
        $artifact['operations'][0]['summary'] = 'private example';
        $artifact['operations'][0]['route_name'] = 'private example';
        $artifact['operations'][0]['parameters'][0]['description'] = 'private example';
        $artifact['webhooks'][] = [
            'type' => 'user.created', 'description' => 'private example',
            'data_schema' => ['type' => 'string', 'examples' => ['private example']],
        ];

        $model = SdkNormalizer::fromArtifact($artifact);
        self::assertSame(['users', 'show'], $model->operations[0]['segments']);
        self::assertSame('UsersShow', $model->operations[0]['symbol']);
        self::assertSame('User', $model->schemaSymbols['User']);
        self::assertSame('SqueHubApiErrorBody', $model->schemaSymbols['SqueHubApiError']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $model->fingerprint);
        self::assertStringNotContainsString('private example', json_encode([
            $model->schemas, $model->operations, $model->webhooks,
        ], JSON_THROW_ON_ERROR));

        $withoutAnnotations = self::artifact();
        $withoutAnnotations['webhooks'][] = [
            'type' => 'user.created', 'description' => null,
            'data_schema' => ['type' => 'string'],
        ];
        self::assertSame($model->fingerprint,
            SdkNormalizer::fromArtifact($withoutAnnotations)->fingerprint);
    }

    public function testRecursiveReferencesAndCompositionsRemainNamed(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Category'] = [
            'type' => 'object',
            'properties' => [
                'children' => ['type' => 'array', 'items' => [
                    '$ref' => '#/components/schemas/Category',
                ]],
                'value' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
            'required' => ['children'],
        ];
        $model = SdkNormalizer::fromArtifact($artifact);
        self::assertSame('#/components/schemas/Category',
            $model->schemas['Category']['properties']['children']['items']['$ref']);
        self::assertCount(2, $model->schemas['Category']['properties']['value']['oneOf']);
    }

    public function testFingerprintIgnoresRegistrationOrderOfNamedMapsAndEnums(): void
    {
        $first = self::artifact();
        $first['schemas']['State'] = ['enum' => ['open', 'closed']];
        $second = self::artifact();
        $second['schemas'] = ['State' => ['enum' => ['closed', 'open']]]
            + array_reverse($second['schemas'], true);
        self::assertSame(SdkNormalizer::fromArtifact($first)->fingerprint,
            SdkNormalizer::fromArtifact($second)->fingerprint);
    }

    public function testVersionSelectionIsRequiredWhenContractContainsMultipleVersions(): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['api_version'] = 'v1';
        $second = $artifact['operations'][0];
        $second['operation_id'] = 'users.showV2';
        $second['path'] = '/api/v2/users/{id}';
        $second['api_version'] = 'v2';
        $artifact['operations'][] = $second;
        $this->expectExceptionMessage('Multiple API versions');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testFilteredVersionIsStoredAndOtherVersionIsRejected(): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['api_version'] = 'v2';
        self::assertSame('v2', SdkNormalizer::fromArtifact($artifact, ' V2 ')->apiVersion);
        $this->expectExceptionMessage('users.show');
        SdkNormalizer::fromArtifact($artifact, 'v1');
    }

    public function testNoPublicOperationsFailsClearly(): void
    {
        $artifact = self::artifact();
        $artifact['operations'] = [];
        $this->expectExceptionMessage('No public contracted API operations');
        SdkNormalizer::fromArtifact($artifact);
    }

    /** @dataProvider unsupportedParameterProvider */
    public function testUnsupportedParameterSerializationFailsWithOperationId(
        string $location, array $schema): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['parameters'][] = [
            'name' => 'filter', 'in' => $location,
            'required' => false, 'schema' => $schema,
        ];
        $this->expectException(SdkException::class);
        $this->expectExceptionMessage('Operation users.show cannot be generated');
        SdkNormalizer::fromArtifact($artifact);
    }

    public static function unsupportedParameterProvider(): array
    {
        return [
            'cookie' => ['cookie', ['type' => 'string']],
            'array query' => ['query', ['type' => 'array', 'items' => ['type' => 'string']]],
            'nullable query' => ['query', ['type' => ['string', 'null']]],
            'composed query' => ['query', ['oneOf' => [
                ['type' => 'string'], ['type' => 'integer'],
            ]]],
        ];
    }

    public function testNonJsonRequestAndMissingSuccessSchemaFail(): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['request_body'] = [
            'required' => true, 'content_type' => 'text/plain',
            'schema' => ['type' => 'string'],
        ];
        try {
            SdkNormalizer::fromArtifact($artifact);
            self::fail('Non-JSON request should fail.');
        } catch (SdkException $exception) {
            self::assertStringContainsString('request media type', $exception->getMessage());
        }

        $artifact = self::artifact();
        $artifact['operations'][0]['responses'][200]['schema'] = null;
        $artifact['operations'][0]['responses'][200]['content_type'] = null;
        $this->expectExceptionMessage('success response 200 has no schema');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testBodyless204IsAllowedAndDefaultCannotSupplySuccess(): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['responses'] = [
            204 => ['content_type' => null, 'schema' => null, 'headers' => []],
            'default' => ['content_type' => 'application/json',
                'schema' => ['$ref' => '#/components/schemas/SqueHubApiError'],
                'headers' => []],
        ];
        self::assertArrayHasKey(204, SdkNormalizer::fromArtifact($artifact)
            ->operations[0]['responses']);
        unset($artifact['operations'][0]['responses'][204]);
        $this->expectExceptionMessage('no declared 2xx success response');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testHeadSuccessCannotDeclareAResponseBody(): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['method'] = 'HEAD';
        $this->expectExceptionMessage('HEAD success response declares a body schema');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testReferencedBooleanOnlySuccessSchemaIsRejected(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Anything'] = true;
        $artifact['operations'][0]['responses'][200]['schema'] = [
            '$ref' => '#/components/schemas/Anything',
        ];
        $this->expectExceptionMessage('untyped success response 200');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testCustomNonSuccessSchemaIsRejected(): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['responses'][400] = [
            'content_type' => 'application/json',
            'schema' => ['type' => 'string'],
            'headers' => [],
        ];
        $this->expectExceptionMessage('non-2xx response 400 is not a SqueHub API error envelope');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testNormalizedSymbolAndMethodPathCollisionsFail(): void
    {
        $artifact = self::artifact();
        $other = $artifact['operations'][0];
        $other['operation_id'] = 'users_show';
        $other['path'] = '/api/other/{id}';
        $artifact['operations'][] = $other;
        $this->expectExceptionMessage('generated symbol collides');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testOverlappingOneOfCannotBeRepresentedAsAPlainUnion(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Variant'] = ['oneOf' => [
            ['type' => 'integer'], ['type' => 'number'],
        ]];
        $this->expectExceptionMessage('Overlapping or unproven oneOf');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testOneOfEnumObjectsWithDifferentKeyOrderStillOverlap(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Variant'] = ['oneOf' => [
            ['enum' => [['a' => 1, 'b' => 2]]],
            ['enum' => [['b' => 2, 'a' => 1]]],
        ]];
        $this->expectExceptionMessage('Overlapping or unproven oneOf');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testNumericConstBranchesOneAndOnePointZeroAreNotDisjoint(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Variant'] = ['oneOf' => [
            ['const' => 1], ['const' => 1.0],
        ]];
        $this->expectExceptionMessage('Overlapping or unproven oneOf');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testTypedAdditionalPropertiesBesideNamedPropertiesFail(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Dictionary'] = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'additionalProperties' => ['type' => 'string'],
        ];
        $this->expectExceptionMessage('Typed additional properties beside named properties');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testSchemaInputAliasCollisionsFail(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['UserInput'] = ['type' => 'object'];
        $this->expectExceptionMessage('generated type collides');
        SdkNormalizer::fromArtifact($artifact);
    }

    public function testSchemaCannotShadowGeneratedTypeScriptUtilityType(): void
    {
        $artifact = self::artifact();
        $artifact['schemas']['Record'] = ['type' => 'string'];
        $this->expectExceptionMessage('collides with a generated SDK runtime symbol');
        SdkNormalizer::fromArtifact($artifact);
    }

    /** @dataProvider reservedOperationProvider */
    public function testJavaScriptObjectMethodSegmentsAreRejected(string $operationId): void
    {
        $artifact = self::artifact();
        $artifact['operations'][0]['operation_id'] = $operationId;
        $this->expectExceptionMessage('JavaScript object or promise method name is reserved');
        SdkNormalizer::fromArtifact($artifact);
    }

    public static function reservedOperationProvider(): array
    {
        return [['then'], ['users.then'], ['then.show'], ['toJSON'],
            ['users.toJSON'], ['users.__proto__'], ['users.hasOwnProperty']];
    }

    private static function artifact(): array
    {
        return [
            'squehub_contract' => '1',
            'schemas' => [
                'User' => ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ], 'required' => ['id', 'name']],
                'SqueHubApiError' => ['type' => 'object'],
            ],
            'operations' => [[
                'operation_id' => 'users.show', 'method' => 'GET',
                'path' => '/api/users/{id}', 'route_name' => 'users.show',
                'api_version' => null,
                'summary' => null, 'description' => null, 'tags' => [],
                'deprecated' => false,
                'parameters' => [[
                    'name' => 'id', 'in' => 'path', 'required' => true,
                    'schema' => ['type' => 'integer'], 'description' => null,
                ]],
                'request_body' => null,
                'responses' => [200 => [
                    'description' => 'User', 'content_type' => 'application/json',
                    'schema' => ['$ref' => '#/components/schemas/User'],
                    'headers' => [],
                ]],
                'security' => ['type' => 'none', 'token_abilities' => [],
                    'authorization_abilities' => []],
            ]],
            'webhooks' => [],
        ];
    }
}
