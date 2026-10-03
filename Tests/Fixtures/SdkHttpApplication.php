<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use App\Api\ApiError;
use App\Api\ApiResource;
use App\Api\Contract\ContractManager;
use App\Api\Contract\ContractServiceProvider;
use App\Api\Contract\OperationContract;
use App\Api\Contract\Schema;
use App\Database\Pagination\Page;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Validation\ValidationServiceProvider;

/** One declarative fixture builds the source contract and handles real HTTP. */
final class SdkHttpApplication
{
    public static function create(string $basePath, string $token): Application
    {
        $app = new Application($basePath);
        $app->register(HttpServiceProvider::class);
        $app->register(RoutingServiceProvider::class);
        $app->register(ValidationServiceProvider::class);
        $app->register(ContractServiceProvider::class);
        $app->bootstrap();
        $routes = $app->container()->make(RouteRegistry::class);
        $contracts = $app->container()->make(ContractManager::class);
        $contracts->resource('SdkItem', SdkHttpItemResource::class);

        $routes->get('/api/echo/{value}', static fn (Request $request, string $value): JsonResponse =>
            new JsonResponse([
                'value' => $value,
                'q' => (string) $request->query('q', ''),
                'version' => (string) $request->apiVersion(),
            ]))
            ->named('mirror.show')->apiVersion('v1')
            ->contract((new OperationContract())
                ->path('value', Schema::string())
                ->query('q', Schema::string())
                ->response(200, Schema::object([
                    'value' => Schema::string(),
                    'q' => Schema::string(),
                    'version' => Schema::string(),
                ])->required(['value', 'q', 'version']))
                ->error(404));

        $routes->post('/api/items', static function (Request $request): JsonResponse {
            $input = $request->validate(['name' => 'required|string']);
            return new JsonResponse(['id' => 7, 'name' => $input['name']], 201);
        })->named('items.create')->apiVersion('v1')
            ->contract((new OperationContract())
                ->body(Schema::object(['name' => Schema::string()])->required(['name']))
                ->response(201, Schema::ref('SdkItem'))
                ->error(422));

        $routes->get('/api/items', static fn (): JsonResponse =>
            SdkHttpItemResource::collection(new Page([
                ['id' => 1, 'name' => 'Ada'],
                ['id' => 2, 'name' => 'Bola'],
            ], 1, 2, 3))->response())
            ->named('items.index')->apiVersion('v1')
            ->contract((new OperationContract())->paginatedResponse(200,
                Schema::ref('SdkItem')));

        $routes->delete('/api/items/{id}', static fn (string $id): Response =>
            new Response('', 204))
            ->named('items.remove')->apiVersion('v1')
            ->contract((new OperationContract())->path('id', Schema::integer())
                ->response(204, null, 'Deleted'));

        $routes->get('/api/errors', static function (): never {
            throw ApiError::make('validation_failed', 'Bad item.', 422,
                ['name' => ['Name is required.']]);
        })->named('errors.show')->apiVersion('v1')
            ->contract((new OperationContract())->response(200, Schema::ref('SdkItem'))
                ->error(422));

        $routes->get('/api/secure', static function (Request $request) use ($token): JsonResponse {
            if ($request->header('Authorization') !== 'Bearer ' . $token) {
                throw ApiError::make('unauthenticated', 'Authentication is required.',
                    401, null, ['WWW-Authenticate' => 'Bearer']);
            }
            return new JsonResponse(['authorized' => true]);
        })->named('secure.show')->apiVersion('v1')
            ->contract((new OperationContract())->pat(['items.read'])
                ->response(200, Schema::object([
                    'authorized' => Schema::boolean(),
                ])->required(['authorized']))->error(401));

        return $app;
    }
}

final class SdkHttpItemResource extends ApiResource
{
    public static function contractSchema(): ?Schema
    {
        return Schema::object([
            'id' => Schema::integer(),
            'name' => Schema::string(),
        ])->required(['id', 'name']);
    }

    public function toArray(): array
    {
        return ['id' => $this->resource['id'], 'name' => $this->resource['name']];
    }
}
