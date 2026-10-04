<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration\TypedApiResources;

use App\Api\ApiResource;
use App\Api\ResourceException;
use App\Data\NestedData;
use App\Data\ValidatedData;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Validation\ValidationServiceProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** The API still validates input and projects its public response explicitly. */
final class TypedApiResourceHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Kernel $kernel;
    private ?RegisterProfileData $received = null;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Api.php', '<?php return ["enabled" => true, "paths" => ["/api"]];');
        $app = new Application($this->project->path());
        foreach ([ValidationServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $this->kernel = $app->container()->make(Kernel::class);
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->post('/api/profiles', function (Request $request): JsonResponse {
            $this->received = $request->validatedAs(RegisterProfileData::class);
            return ProfileResource::make($this->received)->response(201);
        });
        $routes->post('/api/constructor', static function (Request $request): JsonResponse {
            $request->validatedAs(RejectingData::class);
            return new JsonResponse(['unexpected' => true]);
        });
    }

    protected function tearDown(): void
    {
        Route::setResolver(null);
        $this->project->remove();
    }

    public function testJsonInputMapsEnumAndExplicitNestedDataThenResourceChoosesFields(): void
    {
        $response = $this->post('/api/profiles', [
            'name' => 'Ada', 'email' => 'private@example.test', 'status' => 'active',
            'address' => ['city' => 'Lagos', 'internal_id' => 'hidden-nested'],
            'secret' => 'private-secret', 'is_admin' => true, 'password_hash' => 'private-hash',
        ]);

        self::assertSame(201, $response->status(), $response->content());
        self::assertInstanceOf(RegisterProfileData::class, $this->received);
        self::assertSame(ProfileStatus::Active, $this->received->status);
        self::assertInstanceOf(ProfileAddressData::class, $this->received->address);
        self::assertSame('Lagos', $this->received->address->city);
        self::assertSame([
            'name' => 'Ada', 'status' => 'active', 'city' => 'Lagos',
        ], json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        foreach (['private@example.test', 'private-secret', 'hidden-nested', 'private-hash'] as $private) {
            self::assertStringNotContainsString($private, $response->content());
        }
        self::assertObjectNotHasProperty('is_admin', $this->received);
        self::assertObjectNotHasProperty('password_hash', $this->received);
    }

    public function testValidationAndConstructorFailuresKeepSafeApiErrors(): void
    {
        $invalid = $this->post('/api/profiles', [
            'name' => 'Ada', 'email' => 'invalid', 'status' => 'active',
            'address' => ['city' => 'Lagos'], 'secret' => 'do-not-leak',
        ]);
        self::assertSame(422, $invalid->status(), $invalid->content());
        $payload = json_decode($invalid->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('email', $payload['error']['details']);
        self::assertStringNotContainsString('do-not-leak', $invalid->content());
        self::assertNull($this->received);

        $badEnum = $this->post('/api/profiles', [
            'name' => 'Ada', 'email' => 'ada@example.test', 'status' => 'untrusted',
            'address' => ['city' => 'Lagos'], 'secret' => 'do-not-leak',
        ]);
        self::assertSame(422, $badEnum->status(), $badEnum->content());
        self::assertStringNotContainsString('do-not-leak', $badEnum->content());

        $constructor = $this->post('/api/constructor', ['name' => 'do-not-leak']);
        self::assertSame(500, $constructor->status(), $constructor->content());
        self::assertSame('internal_error', json_decode($constructor->content(), true, 512, JSON_THROW_ON_ERROR)['error']['code']);
        self::assertStringNotContainsString('do-not-leak', $constructor->content());
        self::assertStringNotContainsString('constructor-private-message', $constructor->content());
    }

    public function testResourceRejectsImplicitObjectSerialization(): void
    {
        $source = new RegisterProfileData('Ada', 'private@example.test', ProfileStatus::Active,
            new ProfileAddressData('Lagos'), 'private-secret');
        self::assertSame(['name' => 'Ada', 'status' => 'active', 'city' => 'Lagos'],
            ProfileResource::make($source)->resolve());
        $this->expectException(ResourceException::class);
        LeakyProfileResource::make($source)->resolve();
    }

    private function post(string $path, array $input): \App\Http\Response
    {
        return $this->kernel->handle(new Request('POST', $path, headers: [
            'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ], rawBody: json_encode($input, JSON_THROW_ON_ERROR)));
    }
}

/** The enum is selected by trusted PHP type declarations, never by a client class name. */
enum ProfileStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
}

/** Nested input is mapped only because the constructor parameter opts in. */
final readonly class ProfileAddressData
{
    public function __construct(public string $city)
    {
    }
}

/** Incoming private fields remain in validated data only when rules name them. */
final readonly class RegisterProfileData implements ValidatedData
{
    public function __construct(
        public string $name,
        public string $email,
        public ProfileStatus $status,
        #[NestedData] public ProfileAddressData $address,
        public string $secret,
    ) {
    }

    public static function rules(): array
    {
        return [
            'name' => 'required|string', 'email' => 'required|email',
            'status' => 'required|string', 'address' => 'required|array',
            'address.city' => 'required|string', 'secret' => 'required|string',
        ];
    }
}

/** Resource output is an allowlist even when its source is a typed value. */
final class ProfileResource extends ApiResource
{
    public function toArray(): array
    {
        /** @var RegisterProfileData $profile */
        $profile = $this->resource;
        return [
            'name' => $profile->name,
            'status' => $profile->status->value,
            'city' => $profile->address->city,
        ];
    }
}

/** Raw objects are not a legal resource output. */
final class LeakyProfileResource extends ApiResource
{
    public function toArray(): array
    {
        return ['unfiltered' => $this->resource];
    }
}

/** Constructor invariants remain application-owned and cannot leak to clients. */
final readonly class RejectingData implements ValidatedData
{
    public function __construct(public string $name)
    {
        throw new RuntimeException('constructor-private-message');
    }

    public static function rules(): array
    {
        return ['name' => 'required|string'];
    }
}
