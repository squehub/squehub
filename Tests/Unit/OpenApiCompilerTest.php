<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Api;

use App\Api\Contract\ContractException;
use App\Api\Contract\OpenApiCompiler;
use PHPUnit\Framework\TestCase;

/** Verifies the OpenAPI bridge against normalized SqueHub declarations, without booting an Application. */
final class OpenApiCompilerTest extends TestCase
{
    public function testCompilesOperationsSchemasSecurityVersionsAndOutgoingWebhooks(): void
    {
        $document = (new OpenApiCompiler())->compile(self::contract());

        self::assertSame('3.2.1', $document['openapi']);
        self::assertSame('https://spec.openapis.org/oas/3.2/dialect/2026-02-26',
            $document['jsonSchemaDialect']);
        self::assertSame(['title' => 'Fixture API', 'version' => 'v2'], $document['info']);
        self::assertSame([['url' => '/']], $document['servers']);
        self::assertSame(['SqueHubApiError', 'User'], array_keys($document['components']['schemas']));
        self::assertSame(['SqueHubPat', 'SqueHubSession'],
            array_keys($document['components']['securitySchemes']));

        $show = $document['paths']['/api/v2/users/{id}']['get'];
        self::assertSame('users.show', $show['operationId']);
        self::assertSame('v2', $show['x-squehub-api-version']);
        self::assertSame([['SqueHubPat' => []]], $show['security']);
        self::assertSame(['users.read'], $show['x-squehub-token-abilities']);
        self::assertSame(['users.view'], $show['x-squehub-authorization-abilities']);
        self::assertSame('path', $show['parameters'][0]['in']);
        self::assertTrue($show['parameters'][0]['required']);
        self::assertSame(['$ref' => '#/components/schemas/User'],
            $show['responses']['200']['content']['application/json']['schema']);
        self::assertSame('string', $show['responses']['404']['content']['application/json']
            ['schema']['properties']['request_id']['type']);

        $create = $document['paths']['/api/v2/users']['post'];
        self::assertSame(['SqueHubSession' => []], $create['security'][0]);
        self::assertSame('squehub_session', $document['components']['securitySchemes']
            ['SqueHubSession']['name']);
        self::assertTrue($create['requestBody']['required']);
        self::assertSame('object', $create['requestBody']['content']['application/json']['schema']['type']);
        self::assertSame('integer', $create['responses']['429']['headers']
            ['X-RateLimit-Remaining']['schema']['type']);

        $hook = $document['webhooks']['order.paid']['post'];
        self::assertSame('webhook.order.paid', $hook['operationId']);
        self::assertSame('2XX', array_key_first($hook['responses']));
        self::assertSame(['version', 'id', 'type', 'created_at', 'data'],
            $hook['requestBody']['content']['application/json']['schema']['required']);
        self::assertSame('order.paid', $hook['requestBody']['content']['application/json']
            ['schema']['properties']['type']['const']);
        self::assertSame(['SqueHub-Webhook-Id', 'SqueHub-Webhook-Delivery-Id',
            'SqueHub-Webhook-Timestamp', 'SqueHub-Webhook-Signature'],
            array_column($hook['parameters'], 'name'));
        self::assertArrayNotHasKey('order.paid', $document['paths']);
        self::assertArrayNotHasKey('controller', $show);
    }

    public function testSemanticallyReorderedDeclarationsGenerateIdenticalJson(): void
    {
        $compiler = new OpenApiCompiler();
        $first = self::contract();
        $second = $first;
        $second['operations'] = array_reverse($second['operations']);
        $second['schemas'] = array_reverse($second['schemas'], true);
        $second['application']['servers'] = array_reverse($second['application']['servers']);
        $second['operations'][1]['tags'] = array_reverse($second['operations'][1]['tags']);
        $second['operations'][1]['responses'] = array_reverse($second['operations'][1]['responses'], true);

        self::assertSame(
            json_encode($compiler->compile($first), JSON_THROW_ON_ERROR),
            json_encode($compiler->compile($second), JSON_THROW_ON_ERROR)
        );
    }

    public function testEmptyPublicApiProducesObjectPathsInsteadOfJsonList(): void
    {
        $contract = self::contract();
        $contract['schemas'] = [];
        $contract['operations'] = [];
        $contract['webhooks'] = [];
        $document = (new OpenApiCompiler())->compile($contract);

        self::assertSame('{}', json_encode($document['paths'], JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('webhooks', $document);
        self::assertArrayNotHasKey('components', $document);
    }

    public function testPathsRemainPresentWhenNoOutgoingWebhookIsDeclared(): void
    {
        $contract = self::contract();
        $contract['webhooks'] = [];
        $document = (new OpenApiCompiler())->compile($contract);

        self::assertArrayHasKey('/api/v2/users/{id}', $document['paths']);
        self::assertArrayNotHasKey('webhooks', $document);
    }

    public function testDuplicateOperationIdFailsEvenWhenPathsDiffer(): void
    {
        $contract = self::contract();
        $contract['operations'][1]['operation_id'] = $contract['operations'][0]['operation_id'];
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    public function testPathParameterMustMatchRoutePlaceholder(): void
    {
        $contract = self::contract();
        $contract['operations'][0]['parameters'][0]['name'] = 'user';
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    public function testWebhookAndRouteOperationIdsCannotCollide(): void
    {
        $contract = self::contract();
        $contract['operations'][0]['operation_id'] = 'webhook.order.paid';
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    public function testDuplicateLogicalHeaderNameFails(): void
    {
        $contract = self::contract();
        $contract['operations'][0]['parameters'][] = [
            'name' => 'x-request-id', 'in' => 'header', 'required' => false,
            'schema' => ['type' => 'string'], 'description' => null, 'examples' => null,
        ];
        $contract['operations'][0]['parameters'][] = [
            'name' => 'X-Request-ID', 'in' => 'header', 'required' => false,
            'schema' => ['type' => 'string'], 'description' => null, 'examples' => null,
        ];
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    public function testSensitiveHeaderExamplesAreRejected(): void
    {
        $contract = self::contract();
        $contract['operations'][0]['parameters'][] = [
            'name' => 'Authorization', 'in' => 'header', 'required' => true,
            'schema' => ['type' => 'string'], 'description' => null,
            'examples' => ['actual' => 'Bearer PRIVATE-SECRET'],
        ];
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    public function testServerUserInfoIsRejectedAtExportBoundary(): void
    {
        $contract = self::contract();
        $contract['application']['servers'] = ['https://private-user@example.test'];
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    public function testPhpListIsNotAcceptedAsAJsonSchemaObject(): void
    {
        $contract = self::contract();
        $contract['schemas']['User'] = ['string'];
        $this->expectException(ContractException::class);
        (new OpenApiCompiler())->compile($contract);
    }

    /** @return array<string, mixed> */
    private static function contract(): array
    {
        $errorSchema = [
            'type' => 'object',
            'properties' => [
                'request_id' => ['type' => 'string'],
                'error' => ['type' => 'object', 'properties' => [
                    'code' => ['type' => 'string'], 'message' => ['type' => 'string'],
                ]],
            ],
        ];
        return [
            'squehub_contract' => '1',
            'application' => ['title' => 'Fixture API', 'version' => 'v2',
                'description' => null, 'servers' => ['/']],
            'schemas' => [
                'User' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                'SqueHubApiError' => $errorSchema,
            ],
            'operations' => [
                [
                    'operation_id' => 'users.show', 'method' => 'GET',
                    'path' => '/api/v2/users/{id}', 'route_name' => 'users.show',
                    'api_version' => 'v2', 'summary' => 'Show a user', 'description' => null,
                    'tags' => ['users', 'read'], 'deprecated' => false,
                    'parameters' => [[
                        'name' => 'id', 'in' => 'path', 'required' => true,
                        'schema' => ['type' => 'integer'], 'description' => null, 'examples' => null,
                    ]],
                    'request_body' => null,
                    'responses' => [
                        '200' => ['description' => 'A user.', 'content_type' => 'application/json',
                            'schema' => ['$ref' => '#/components/schemas/User'], 'headers' => []],
                        '404' => ['description' => 'Not found.', 'content_type' => 'application/json',
                            'schema' => $errorSchema, 'headers' => []],
                    ],
                    'security' => ['type' => 'pat', 'token_abilities' => ['users.read'],
                        'authorization_abilities' => ['users.view']],
                ],
                [
                    'operation_id' => 'users.create', 'method' => 'POST',
                    'path' => '/api/v2/users', 'route_name' => 'users.create',
                    'api_version' => null, 'summary' => null, 'description' => null,
                    'tags' => ['users'], 'deprecated' => false,
                    'parameters' => [],
                    'request_body' => [
                        'required' => true, 'content_type' => 'application/json',
                        'schema' => ['type' => 'object', 'properties' => [
                            'name' => ['type' => 'string'],
                        ]], 'description' => null,
                    ],
                    'responses' => [
                        '201' => ['description' => 'Created.', 'content_type' => 'application/json',
                            'schema' => ['$ref' => '#/components/schemas/User'], 'headers' => []],
                        '429' => ['description' => 'Too many requests.', 'content_type' => 'application/json',
                            'schema' => ['$ref' => '#/components/schemas/SqueHubApiError'],
                            'headers' => ['X-RateLimit-Remaining' => [
                                'description' => 'Permits left.', 'schema' => ['type' => 'integer'],
                            ]]],
                    ],
                    'security' => ['type' => 'session', 'cookie_name' => 'squehub_session',
                        'token_abilities' => [], 'authorization_abilities' => []],
                ],
            ],
            'webhooks' => [[
                'type' => 'order.paid', 'description' => 'An order was paid.',
                'data_schema' => ['type' => 'object', 'properties' => [
                    'order_id' => ['type' => 'string'],
                ]],
            ]],
        ];
    }
}
