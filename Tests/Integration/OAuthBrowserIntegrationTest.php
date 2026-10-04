<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Auth\Auth;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Database\Database;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Diagnostics\Diagnostics;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Kernel;
use App\Http\RedirectResponse;
use App\Http\Request;
use App\HttpClient\Http;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpServiceProvider as OutboundHttpServiceProvider;
use App\OAuth\OAuth;
use App\OAuth\OAuthException;
use App\OAuth\OAuthManager;
use App\OAuth\OAuthServiceProvider;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Session\Session;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PDO;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Browser routes use the public gateway and preserve state across requests. */
final class OAuthBrowserIntegrationTest extends TestCase
{
    private TemporaryProject $project;

    protected function tearDown(): void
    {
        Auth::setResolver(null);
        Database::setResolver(null);
        OAuth::setResolver(null);
        Http::setResolver(null);
        Session::setResolver(null);
        Route::setResolver(null);
        if (isset($this->project)) $this->project->remove();
    }

    public function testBrowserCancellationReturns400AndConsumesStateWithoutTokenExchange(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/OAuth.php', '<?php return ' . var_export([
            'providers' => ['example' => [
                'issuer' => 'https://id.example.test',
                'client_id' => 'browser-client',
                'redirect_uri' => 'https://app.example.test/auth/oidc/callback',
                'scopes' => ['openid'],
            ]],
        ], true) . ';');

        $app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, SessionServiceProvider::class,
            OutboundHttpServiceProvider::class, HttpServiceProvider::class,
            RoutingServiceProvider::class, OAuthServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();

        $http = $app->container()->make(HttpClient::class);
        $http->fake(['GET https://id.example.test/.well-known/openid-configuration'
            => new HttpResponse(200, json_encode([
                'issuer' => 'https://id.example.test',
                'authorization_endpoint' => 'https://id.example.test/authorize',
                'token_endpoint' => 'https://id.example.test/token',
                'jwks_uri' => 'https://id.example.test/keys',
                'response_types_supported' => ['code'],
                'id_token_signing_alg_values_supported' => ['RS256'],
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
            ], JSON_THROW_ON_ERROR))]);

        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/auth/oidc/start', static fn () => OAuth::provider('example')->redirect());
        $routes->get('/auth/oidc/callback', static fn (Request $request): string =>
            OAuth::provider('example')->callback($request)->subject());

        $kernel = $app->container()->make(Kernel::class);
        $store = $app->container()->make(SessionManager::class)->store();
        $redirect = $kernel->handle(new Request('GET', '/auth/oidc/start'));
        self::assertSame(302, $redirect->status());
        self::assertStringStartsWith('https://id.example.test/authorize?', $redirect->header('Location'));
        parse_str((string) parse_url($redirect->header('Location'), PHP_URL_QUERY), $query);
        self::assertNotEmpty($query['state']);
        self::assertSame([], $store->all());
        $store->close();

        $callback = '/auth/oidc/callback?' . http_build_query([
            'state' => $query['state'], 'iss' => 'https://id.example.test',
            'error' => 'access_denied',
        ], '', '&', PHP_QUERY_RFC3986);
        $cancelled = $kernel->handle(new Request('GET', $callback));
        self::assertSame(400, $cancelled->status());
        self::assertStringContainsString('Sign-in cancelled', $cancelled->content());
        self::assertSame('no-store', $cancelled->header('Cache-Control'));
        self::assertNull($store->authIdentifier('web'));
        self::assertSame(0, $app->container()->make(Diagnostics::class)->snapshot()['auth']['logins']);
        $store->close();

        $replay = $kernel->handle(new Request('GET', $callback));
        self::assertSame(500, $replay->status());
        self::assertCount(1, $http->captured());
    }

    public function testBrowserCallbackExplicitlyLinksExistingLocalUserAndRedirectsSafely(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite is required.');
        $this->project = new TemporaryProject();
        $this->project->write('Config/App.php', '<?php return ["env" => "production", "debug" => false];');
        $this->project->write('Config/Session.php', '<?php return ["driver" => "array"];');
        $this->project->write('Config/Database.php', '<?php return ["default" => "main", "connections" => ["main" => ["driver" => "sqlite", "database" => ":memory:"]]];');
        $this->project->write('Config/OAuth.php', '<?php return ' . var_export([
            'providers' => ['example' => [
                'issuer' => 'https://id.example.test',
                'client_id' => 'browser-client',
                'redirect_uri' => 'https://app.example.test/auth/oidc/callback',
                'scopes' => ['openid'],
            ]],
        ], true) . ';');
        $this->project->write('Config/Auth.php', '<?php return ' . var_export([
            'default' => 'web',
            'guards' => ['web' => ['driver' => 'session', 'identity' => 'users']],
            'identities' => ['users' => [
                'driver' => 'model', 'model' => OAuthBrowserUser::class,
                'identifier' => 'id', 'password' => 'password', 'credentials' => ['email'],
            ]],
            'passwords' => ['algorithm' => 'bcrypt', 'options' => ['cost' => 4]],
        ], true) . ';');

        $app = new Application($this->project->path());
        $app->container()->instance(ModelClock::class, new OAuthBrowserTestClock());
        foreach ([DiagnosticsServiceProvider::class, DatabaseServiceProvider::class,
            SessionServiceProvider::class, OutboundHttpServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class,
            AuthServiceProvider::class, OAuthServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->bootstrap();
        $database = $app->container()->make(DatabaseManager::class);
        $database->schema()->create('oauth_users', static function (Table $table): void {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->string('password');
        });
        $local = OAuthBrowserUser::create([
            'email' => 'linked@example.test', 'name' => 'Linked User',
            'password' => password_hash('local-password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $linkedId = $local->authIdentifier();

        $http = $app->container()->make(HttpClient::class);
        $http->fake(['GET https://id.example.test/.well-known/openid-configuration'
            => self::metadataResponse()]);
        $routes = $app->container()->make(RouteRegistry::class);
        $routes->get('/auth/oidc/start', static fn () => OAuth::provider('example')->redirect());
        $routes->get('/auth/oidc/callback', static function (Request $request) use ($linkedId): RedirectResponse {
            $identity = OAuth::provider('example')->callback($request);
            // Application-owned account linking is explicit and subject-bound.
            if ($identity->provider() !== 'example'
                || $identity->issuer() !== 'https://id.example.test'
                || $identity->subject() !== 'linked-subject') {
                throw new OAuthException('External identity is not linked.');
            }
            $user = OAuthBrowserUser::find($linkedId);
            if ($user === null) throw new OAuthException('Linked local account is unavailable.');
            Auth::manager()->login($user);
            return new RedirectResponse('/dashboard', 303);
        });

        $kernel = $app->container()->make(Kernel::class);
        $store = $app->container()->make(SessionManager::class)->store();
        $redirect = $kernel->handle(new Request('GET', '/auth/oidc/start'));
        self::assertSame(302, $redirect->status());
        parse_str((string) parse_url($redirect->header('Location'), PHP_URL_QUERY), $query);
        self::assertNotEmpty($query['state']);
        self::assertNotEmpty($query['nonce']);
        self::assertFalse(Auth::manager()->check());
        $sessionBeforeLogin = $store->id();
        $store->close();

        [$token, $jwk] = self::signedFixtureToken($query['nonce']);
        $http->fake([
            'GET https://id.example.test/.well-known/openid-configuration' => self::metadataResponse(),
            'POST https://id.example.test/token' => new HttpResponse(200,
                json_encode(['id_token' => $token, 'access_token' => 'private-provider-token'], JSON_THROW_ON_ERROR)),
            'GET https://id.example.test/keys' => new HttpResponse(200,
                json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)),
        ]);
        $callback = '/auth/oidc/callback?' . http_build_query([
            'state' => $query['state'], 'iss' => 'https://id.example.test',
            'code' => 'one-time-code', 'next' => 'https://evil.example.test/',
        ], '', '&', PHP_QUERY_RFC3986);
        $completed = $kernel->handle(new Request('GET', $callback));
        self::assertSame(303, $completed->status(), $completed->content());
        self::assertSame('/dashboard', $completed->header('Location'));
        self::assertNotSame($sessionBeforeLogin, $store->id());
        self::assertSame((string) $linkedId, (string) Auth::manager()->id());
        self::assertSame((string) $linkedId, (string) $store->authIdentifier('web'));
        self::assertSame([], $store->all());
        self::assertSame(1, $app->container()->make(Diagnostics::class)->snapshot()['auth']['logins']);
        self::assertSame(['GET', 'POST', 'GET'],
            array_map(static fn ($sent): string => $sent->method, $http->captured()));
    }

    public function testTwoApplicationsKeepBrowserTransactionsIsolated(): void
    {
        $this->project = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            foreach ([$this->project, $secondProject] as $project) {
                $project->write('Config/Session.php', '<?php return ["driver" => "array"];');
                $project->write('Config/OAuth.php', '<?php return ' . var_export([
                    'providers' => ['example' => [
                        'issuer' => 'https://id.example.test',
                        'client_id' => 'browser-client',
                        'redirect_uri' => 'https://app.example.test/auth/oidc/callback',
                    ]],
                ], true) . ';');
            }
            $first = new Application($this->project->path());
            $second = new Application($secondProject->path());
            foreach ([$first, $second] as $app) {
                foreach ([SessionServiceProvider::class, OutboundHttpServiceProvider::class,
                    OAuthServiceProvider::class] as $provider) {
                    $app->register($provider);
                }
                $app->bootstrap();
            }
            $firstHttp = $first->container()->make(HttpClient::class);
            $firstHttp->fake(['GET https://id.example.test/.well-known/openid-configuration'
                => self::metadataResponse()]);
            $secondHttp = $second->container()->make(HttpClient::class);
            $secondHttp->fake(['*' => new HttpResponse(500)]);

            $firstManager = $first->container()->make(OAuthManager::class);
            $secondManager = $second->container()->make(OAuthManager::class);
            $redirect = $firstManager->provider('example')->redirect();
            parse_str((string) parse_url($redirect->header('Location'), PHP_URL_QUERY), $query);
            $first->container()->make(SessionManager::class)->store()->close();
            $request = new Request('GET', '/auth/oidc/callback?' . http_build_query([
                'state' => $query['state'], 'iss' => 'https://id.example.test',
                'code' => 'one-time-code',
            ], '', '&', PHP_QUERY_RFC3986));
            try {
                $secondManager->provider('example')->callback($request);
                self::fail('A second Application consumed another browser state.');
            } catch (OAuthException $exception) {
                self::assertSame('OIDC callback transaction is invalid or expired.', $exception->getMessage());
            }
            // The owning Application still has its transaction and reaches the issuer check.
            $missingIssuer = new Request('GET', '/auth/oidc/callback?state=' . rawurlencode($query['state'])
                . '&code=one-time-code');
            try {
                $firstManager->provider('example')->callback($missingIssuer);
                self::fail('A callback without issuer was accepted.');
            } catch (OAuthException $exception) {
                self::assertSame('OIDC authorization response issuer is invalid.', $exception->getMessage());
            }
            self::assertCount(1, $firstHttp->captured());
            self::assertSame([], $secondHttp->captured());
        } finally {
            $secondProject->remove();
        }
    }

    private static function metadataResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'issuer' => 'https://id.example.test',
            'authorization_endpoint' => 'https://id.example.test/authorize',
            'token_endpoint' => 'https://id.example.test/token',
            'jwks_uri' => 'https://id.example.test/keys',
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function signedFixtureToken(string $nonce): array
    {
        if (!extension_loaded('openssl')) self::markTestSkipped('OpenSSL is required for RS256 fixture.');
        $configuration = tempnam(sys_get_temp_dir(), 'oidc-openssl-');
        self::assertNotFalse($configuration);
        try {
            self::assertNotFalse(file_put_contents($configuration, "[req]\ndefault_bits = 2048\n"));
            $key = openssl_pkey_new([
                'config' => $configuration, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ]);
        } finally {
            unlink($configuration);
        }
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $jwk = [
            'kty' => 'RSA', 'kid' => 'browser-fixture', 'alg' => 'RS256', 'use' => 'sig',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
        $token = JWT::encode([
            'iss' => 'https://id.example.test', 'sub' => 'linked-subject',
            'aud' => 'browser-client', 'nonce' => $nonce,
            'iat' => 1_700_000_000, 'exp' => 1_700_000_300,
            'email' => 'linked@example.test', 'email_verified' => true,
        ], $key, 'RS256', 'browser-fixture');
        return [$token, $jwk];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

final class OAuthBrowserTestClock implements ModelClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@1700000000');
    }
}

final class OAuthBrowserUser extends Model implements Authenticatable
{
    protected string $table = 'oauth_users';
    protected array $fillable = ['email', 'name', 'password'];
    protected array $hidden = ['password'];
    protected bool $timestamps = false;

    public function authIdentifier(): int|string { return $this->getAttribute('id'); }
    public function authPasswordHash(): string { return $this->getAttribute('password'); }
}
