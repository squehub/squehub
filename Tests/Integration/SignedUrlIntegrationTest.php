<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Contracts\Authenticatable;
use App\Authorization\Rbac\RbacManager;
use App\Database\DatabaseManager;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Http\FileResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseFactory;
use App\Plugins\RequireAbility;
use App\Plugins\SignedUrl;
use App\Plugins\TestCase;
use App\Routing\RouteRegistry;
use App\Security\SignedUrl\RequireSignedUrl;
use App\Support\RuntimeContext;
use App\Testing\TestApplication;
use App\Testing\TestClient;
use DateTimeImmutable;
use PDO;

/** The signed guard protects real routes, downloads, hosts, and RBAC actions. */
final class SignedUrlIntegrationTest extends TestCase
{
    private const NOW = 1800000000;
    private SignedUrlHttpClock $clock;

    protected function testingConfig(): array
    {
        return [
            'crypt' => ['driver' => 'auto', 'current' => 'primary',
                'keys' => ['primary' => 'base64:' . base64_encode(str_repeat('k', 32))]],
            'rbac' => ['enabled' => true, 'driver' => 'array'],
            'auth' => [
                'default' => 'web',
                'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
                'identities' => ['users' => ['driver' => 'model',
                    'model' => SignedUrlHttpUser::class, 'identifier' => 'id',
                    'password' => 'password', 'credentials' => ['email']]],
                'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4],
                    'rehash_on_login' => false, 'max_bytes' => 4096],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new SignedUrlHttpClock(self::NOW);
        $this->testApplication()->write('Project/Files/report.txt', 'signed download bytes');
    }

    private function routes(): RouteRegistry
    {
        $app = $this->app();
        $app->container()->instance(ModelClock::class, $this->clock);
        return $app->container()->make(RouteRegistry::class);
    }

    public function testValidSignedUrlServesPrivateDownloadAndBadSignatureStopsController(): void
    {
        $source = $this->testApplication()->path('Project/Files/report.txt');
        $responses = $this->app()->container()->make(ResponseFactory::class);
        $runs = 0;
        $this->routes()->get('/private/{name}', static function (Request $request) use ($source, $responses, &$runs): Response {
            ++$runs;
            return $responses->download($source, request: $request);
        })->named('private.download')->through(SignedUrl::middleware('private.download'));

        $url = SignedUrl::temporary('private.download', ['name' => 'report.txt'],
            ['receipt' => 'secret-receipt-marker'], self::NOW + 300, 'private.download');
        $valid = $this->get($url)->assertOk();
        self::assertInstanceOf(FileResponse::class, $valid->response());
        self::assertSame('signed download bytes', $this->emitted($valid->response()));
        self::assertSame(1, $runs);

        $invalid = $this->getJson($url . '&sqh_signature=second-value')->assertStatus(403);
        self::assertSame(1, $runs);
        foreach (['secret-receipt-marker', 'second-value', 'private.download',
            'base64:' . base64_encode(str_repeat('k', 32))] as $private) {
            self::assertStringNotContainsString($private, $invalid->content());
        }
        $planted = preg_replace('/sqh_signature=[^&]+/',
            'sqh_signature=planted-signature-secret', $url);
        self::assertIsString($planted);
        $browser = $this->get($planted)->assertStatus(403);
        self::assertSame(1, $runs);
        self::assertStringNotContainsString('planted-signature-secret', $browser->content());
        self::assertStringNotContainsString('secret-receipt-marker', $browser->content());
    }

    public function testApiScopedSignedUrlFailuresUseGenericJsonEnvelope(): void
    {
        $this->testApplication()->configure(['api' => ['enabled' => true, 'paths' => ['/api']]]);
        $runs = 0;
        $this->routes()->get('/api/private/{name}', static function () use (&$runs): string {
            ++$runs;
            return 'private content';
        })->named('api.private')->through(SignedUrl::middleware('api.private'));
        $url = SignedUrl::temporary('api.private', ['name' => 'report.txt'],
            ['receipt' => 'private-receipt-marker'], self::NOW + 300, 'api.private');
        $this->get($url)->assertOk();
        self::assertSame(1, $runs);

        $planted = preg_replace('/sqh_signature=[^&]+/',
            'sqh_signature=planted-signature-secret', $url);
        self::assertIsString($planted);
        $invalid = $this->get($planted)->assertStatus(403)->assertJson()
            ->assertJsonPath('error.code', 'invalid_signed_url')
            ->assertJsonPath('error.message', 'Invalid signed URL.')
            ->assertJsonMissing('error.details')
            ->assertHeader('Cache-Control', 'no-store');
        $requestId = $invalid->header('X-Request-ID');
        self::assertNotNull($requestId);
        $invalid->assertJsonPath('request_id', $requestId)
            ->assertNotContains('planted-signature-secret')
            ->assertNotContains('private-receipt-marker');

        $this->clock->set(self::NOW + 300);
        $expired = $this->get($url)->assertStatus(403)->assertJson()
            ->assertJsonPath('error.code', 'invalid_signed_url')
            ->assertJsonPath('error.message', 'Invalid signed URL.')
            ->assertJsonMissing('error.details');
        self::assertNotNull($expired->header('X-Request-ID'));
        self::assertSame(1, $runs);
    }

    public function testMountedLinkUsesOnePrefixAndRejectsOutsideMount(): void
    {
        $this->testApplication()->configure(['http' => ['base_path' => '/app']]);
        $this->routes()->get('/files/{name}', static fn (): string => 'mounted file')
            ->named('mounted.file')->through(new RequireSignedUrl('file.view'));
        $url = SignedUrl::temporary('mounted.file', ['name' => 'résumé.txt'], [],
            self::NOW + 300, 'file.view');
        self::assertStringStartsWith('/app/files/r%C3%A9sum%C3%A9.txt?', $url);
        $this->get($url)->assertOk()->assertContains('mounted file');
        $this->get(substr($url, 4))->assertStatus(404);
    }

    public function testStaticHostUsesTrustedForwardedAuthorityOnly(): void
    {
        $this->testApplication()->configure(['trustedProxies' => [
            'proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded',
        ]]);
        $this->routes()->get('/hosted', static fn (): string => 'hosted content',
            'downloads.example.test')->named('hosted.download')
            ->through(new RequireSignedUrl('hosted.download'));
        $url = SignedUrl::temporary('hosted.download', [], [],
            self::NOW + 300, 'hosted.download');
        $headers = ['X-Forwarded-Host' => 'downloads.example.test',
            'X-Forwarded-Proto' => 'https'];
        $trusted = $this->rawRequest($url, '10.0.0.8', $headers);
        self::assertSame(200, $this->app()->container()->make(Kernel::class)->handle($trusted)->status());
        $untrusted = $this->rawRequest($url, '198.51.100.8', $headers);
        self::assertSame(404, $this->app()->container()->make(Kernel::class)->handle($untrusted)->status());
        $wrongHost = $this->rawRequest($url, '10.0.0.8', [
            'X-Forwarded-Host' => 'other.example.test', 'X-Forwarded-Proto' => 'https',
        ]);
        self::assertSame(404, $this->app()->container()->make(Kernel::class)->handle($wrongHost)->status());
    }

    public function testValidSignatureStillRequiresRbacGrant(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for authenticated RBAC routing.');
        }
        $this->routes()->get('/reports/{id}', static fn (): string => 'private report')
            ->named('reports.download')
            ->through([SignedUrl::middleware('report.download'),
                RequireAbility::named('reports.download')]);
        $database = $this->app()->container()->make(DatabaseManager::class);
        $database->schema()->create('signed_url_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
        $user = SignedUrlHttpUser::create([
            'email' => 'reader@example.test',
            'password' => password_hash('unused', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->actingAs($user);
        $rbac = $this->app()->container()->make(RbacManager::class);
        $rbac->createPermission('reports.download');
        $rbac->createRole('reader');
        $rbac->grantPermission('reader', 'reports.download');
        $url = SignedUrl::temporary('reports.download', ['id' => '42'], [],
            self::NOW + 300, 'report.download');
        $this->getJson($url)->assertStatus(403);
        $rbac->assignRole($user, 'reader');
        $this->get($url)->assertOk()->assertContains('private report');
    }

    public function testApplicationContextsDoNotShareSigningKeys(): void
    {
        $first = TestApplication::temporary(['crypt' => [
            'current' => 'first',
            'keys' => ['first' => 'base64:' . base64_encode(str_repeat('a', 32))],
        ]]);
        $second = TestApplication::temporary(['crypt' => [
            'current' => 'second',
            'keys' => ['second' => 'base64:' . base64_encode(str_repeat('b', 32))],
        ]]);
        try {
            foreach ([$first, $second] as $fixture) {
                $app = $fixture->application();
                $app->container()->instance(ModelClock::class, $this->clock);
                $app->container()->make(RouteRegistry::class)
                    ->get('/isolated', static fn (): string => 'owning application')
                    ->named('isolated')->through(new RequireSignedUrl('isolation'));
            }
            RuntimeContext::select($first->application());
            $url = SignedUrl::temporary('isolated', [], [], self::NOW + 300, 'isolation');
            (new TestClient($second))->get($url)->assertStatus(403);
            (new TestClient($first))->get($url)->assertOk()->assertContains('owning application');
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    /** @param array<string,string> $headers */
    private function rawRequest(string $uri, string $peer, array $headers): Request
    {
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return new Request('GET', $uri, $query, headers: $headers,
            server: ['REMOTE_ADDR' => $peer, 'HTTP_HOST' => 'backend.internal:8080']);
    }

    private function emitted(Response $response): string
    {
        ob_start();
        try {
            $response->send();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}

final class SignedUrlHttpClock implements ModelClock
{
    public function __construct(private int $time) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->time);
    }

    public function set(int $time): void
    {
        $this->time = $time;
    }
}

final class SignedUrlHttpUser extends Model implements Authenticatable
{
    protected string $table = 'signed_url_users';
    protected array $fillable = ['email', 'password'];
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}
