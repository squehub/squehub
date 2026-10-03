<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Database\ModelClock;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\OAuth\OAuthCancelledException;
use App\OAuth\OAuthException;
use App\OAuth\OAuthManager;
use App\Session\SessionManager;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

/** Browser authorization and ID token verification without an outbound connection. */
final class OAuthFlowTest extends TestCase
{
    private const ISSUER = 'https://id.example.test';
    private const CALLBACK = '/auth/oidc/callback';
    private const CLIENT_ID = 'test-client';
    private const CLIENT_SECRET = 'test-only-client-secret';
    private const PRIVATE_ACCESS_TOKEN = 'provider-access-token-do-not-leak';

    private OAuthTestClock $clock;
    private Repository $config;
    private Diagnostics $diagnostics;
    private HttpClient $http;
    private SessionManager $sessions;
    private OAuthManager $manager;
    private ?OpenSSLAsymmetricKey $signingKey = null;
    /** @var array<string,string>|null */
    private ?array $publicJwk = null;

    protected function setUp(): void
    {
        $this->clock = new OAuthTestClock(1_700_000_000);
        $this->diagnostics = new Diagnostics(new Repository([]));
        $this->diagnostics->begin(new Request('GET', '/'));
        $this->http = new HttpClient([], null, $this->diagnostics);
        $settings = [
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'redirect_uri' => 'https://app.example.test' . self::CALLBACK,
            'scopes' => ['openid', 'profile', 'email'],
            'clock_skew' => 0,
        ];
        $this->config = new Repository([
            'session' => ['driver' => 'array'],
            'oauth' => ['providers' => ['example' => $settings, 'other' => $settings]],
        ]);
        $this->sessions = new SessionManager($this->config);
        $this->manager = new OAuthManager($this->config, $this->http, $this->sessions, $this->clock);
        $this->fakeDiscovery();
    }

    public function testRedirectUsesAuthorizationCodeAndPkceS256WithPrivateSessionTransaction(): void
    {
        $response = $this->manager->provider('example')->redirect([
            'prompt' => 'select_account', 'login_hint' => 'person@example.test',
        ]);
        $query = self::redirectQuery($response->header('Location'));

        self::assertSame(302, $response->status());
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
        self::assertSame('code', $query['response_type']);
        self::assertSame(self::CLIENT_ID, $query['client_id']);
        self::assertSame('https://app.example.test' . self::CALLBACK, $query['redirect_uri']);
        self::assertSame('openid profile email', $query['scope']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $query['state']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $query['nonce']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $query['code_challenge']);
        self::assertSame('select_account', $query['prompt']);
        self::assertSame('person@example.test', $query['login_hint']);
        self::assertSame([], $this->sessions->store()->all());
        self::assertSame(['GET ' . self::ISSUER . '/.well-known/openid-configuration'],
            array_map(static fn ($request): string => $request->method . ' ' . $request->url,
                $this->http->captured()));
    }

    public function testVerifiedCallbackReturnsExternalIdentityAndLeavesLocalAuthGuest(): void
    {
        $query = $this->begin();
        $this->fakeSignedToken($this->validClaims($query['nonce']));
        $identity = $this->manager->provider('example')->callback($this->callbackRequest($query['state']));

        self::assertSame('example', $identity->provider());
        self::assertSame(self::ISSUER, $identity->issuer());
        self::assertSame('user-123', $identity->subject());
        self::assertSame('person@example.test', $identity->email());
        self::assertTrue($identity->emailVerified());
        self::assertSame('Person Example', $identity->name());
        self::assertArrayNotHasKey('access_token', $identity->claims());
        self::assertSame([], $this->sessions->store()->all());
        self::assertNull($this->sessions->store()->authIdentifier('web'));

        $sent = $this->http->captured();
        self::assertSame(['GET', 'POST', 'GET'], array_map(static fn ($request): string => $request->method, $sent));
        self::assertSame(self::ISSUER . '/token', $sent[1]->url);
        self::assertSame(self::ISSUER . '/keys', $sent[2]->url);
        self::assertSame('Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET),
            $sent[1]->headers['authorization']);
        parse_str((string) $sent[1]->body, $body);
        self::assertSame('authorization_code', $body['grant_type']);
        self::assertSame('one-time-code', $body['code']);
        self::assertSame('https://app.example.test' . self::CALLBACK, $body['redirect_uri']);
        self::assertArrayNotHasKey('client_secret', $body);
        self::assertArrayNotHasKey('client_id', $body);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9._~-]{43,128}\z/D', $body['code_verifier']);
        self::assertSame($query['code_challenge'], self::base64Url(hash('sha256', $body['code_verifier'], true)));

        $snapshot = json_encode($this->diagnostics->snapshot(), JSON_THROW_ON_ERROR);
        foreach ([self::CLIENT_SECRET, self::PRIVATE_ACCESS_TOKEN, 'one-time-code',
            $query['state'], $query['nonce'], $body['code_verifier']] as $secret) {
            self::assertStringNotContainsString($secret, $snapshot);
        }
        self::assertSame(0, $this->diagnostics->snapshot()['auth']['logins']);

        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(3, $this->http->captured());
    }

    public function testStateIssuerAndProviderAreStrictAndFailedAttemptsCannotReplay(): void
    {
        $query = $this->begin();
        $this->reject(fn () => $this->manager->provider('example')->callback(
            $this->callbackRequest(str_repeat('x', 43))), 'OIDC callback transaction is invalid or expired.');
        self::assertCount(1, $this->http->captured());

        $this->reject(fn () => $this->manager->provider('example')->callback(
            $this->callbackRequest($query['state'], ['iss' => 'https://evil.example.test'])),
            'OIDC authorization response issuer is invalid.');
        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(1, $this->http->captured());

        $other = $this->begin();
        $this->reject(fn () => $this->manager->provider('other')->callback($this->callbackRequest($other['state'])),
            'OIDC callback transaction is invalid or expired.');
        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($other['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(2, $this->http->captured());
    }

    public function testMissingStateAndIssuerAreRejectedBeforeTokenExchange(): void
    {
        $query = $this->begin();
        $this->reject(fn () => $this->manager->provider('example')->callback(
            $this->callbackRequest($query['state'], ['state' => null])),
            'OIDC callback state is invalid.');
        // A malformed state cannot select or consume a transaction.
        $this->reject(fn () => $this->manager->provider('example')->callback(
            $this->callbackRequest($query['state'], ['iss' => null])),
            'OIDC authorization response issuer is invalid.');
        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(1, $this->http->captured());
    }

    public function testDuplicateRawCallbackParametersAreRejectedBeforeStateConsumption(): void
    {
        foreach (['state', 'iss', 'code'] as $duplicate) {
            $query = $this->begin();
            $parameters = ['state' => $query['state'], 'iss' => self::ISSUER, 'code' => 'one-time-code'];
            $uri = self::CALLBACK . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)
                . '&' . $duplicate . '=duplicate';
            $this->reject(fn () => $this->manager->provider('example')->callback(new Request('GET', $uri)),
                'OIDC callback query is invalid or duplicated.');
            // The valid state remains available: parsing failed before transaction consumption.
            $this->reject(fn () => $this->manager->provider('example')->callback(
                $this->callbackRequest($query['state'], ['iss' => 'https://evil.example.test'])),
                'OIDC authorization response issuer is invalid.');
        }
        self::assertCount(3, $this->http->captured());
    }

    public function testExpiredTransactionCannotExchangeCode(): void
    {
        $query = $this->begin();
        $this->clock->advance(601);
        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(1, $this->http->captured());
    }

    public function testSignedTokenMustMatchNonceIssuerAudienceAndTime(): void
    {
        foreach (['nonce', 'iss', 'aud', 'exp'] as $claim) {
            $query = $this->begin();
            $claims = $this->validClaims($query['nonce']);
            $claims[$claim] = match ($claim) {
                'nonce' => 'wrong-nonce',
                'iss' => 'https://other.example.test',
                'aud' => 'another-client',
                'exp' => $this->clock->now()->getTimestamp() - 1,
            };
            $this->fakeSignedToken($claims);
            $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
                $claim === 'exp' ? 'ID token verification failed.' : 'ID token claims are invalid.');
            $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
                'OIDC callback transaction is invalid or expired.');
        }
    }

    public function testInvalidSignatureIsRejectedAndCannotReplay(): void
    {
        $query = $this->begin();
        $this->ensureSigningKey();
        $token = JWT::encode($this->validClaims($query['nonce']), $this->signingKey, 'RS256', 'fixture-key');
        $segments = explode('.', $token);
        $segments[2][0] = $segments[2][0] === 'A' ? 'B' : 'A';
        $this->fakeToken(implode('.', $segments), [$this->publicJwk]);

        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'ID token signature is invalid.');
        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(3, $this->http->captured());
    }

    public function testCachedSigningKeyRefreshesAfterRotation(): void
    {
        $first = $this->begin();
        $this->fakeSignedToken($this->validClaims($first['nonce']));
        self::assertSame('user-123', $this->manager->provider('example')
            ->callback($this->callbackRequest($first['state']))->subject());

        $second = $this->begin();
        [$rotatedKey, $rotatedJwk] = $this->newSigningKey();
        $rotatedJwk['kid'] = 'fixture-key';
        $rotated = JWT::encode($this->validClaims($second['nonce']), $rotatedKey, 'RS256', 'fixture-key');
        $this->fakeToken($rotated, [$rotatedJwk]);
        self::assertSame('user-123', $this->manager->provider('example')
            ->callback($this->callbackRequest($second['state']))->subject());
        self::assertSame(['GET', 'POST', 'GET'],
            array_map(static fn ($request): string => $request->method, $this->http->captured()));
    }

    public function testDiscoveryRejectsWrongIssuerAndUntrustedEndpoint(): void
    {
        $this->fakeDiscovery(['issuer' => 'https://other.example.test']);
        $this->reject(fn () => $this->manager->provider('example')->redirect(),
            'OIDC provider metadata is incompatible.');
        self::assertSame([], $this->sessions->store()->all());
        self::assertCount(1, $this->http->captured());

        $this->fakeDiscovery(['token_endpoint' => 'https://evil.example.test/token']);
        $this->reject(fn () => $this->manager->provider('example')->redirect(),
            'OIDC endpoint host is not trusted.');
        self::assertSame([], $this->sessions->store()->all());
        self::assertCount(1, $this->http->captured());
    }

    public function testSeparateSessionManagersCannotConsumeEachOthersState(): void
    {
        $query = $this->begin();
        $otherSessions = new SessionManager($this->config);
        $otherManager = new OAuthManager($this->config, $this->http, $otherSessions, $this->clock);
        $this->reject(fn () => $otherManager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertSame([], $otherSessions->store()->all());
        // The failed attempt in the other session did not consume this browser's state.
        $this->reject(fn () => $this->manager->provider('example')->callback(
            $this->callbackRequest($query['state'], ['iss' => null])),
            'OIDC authorization response issuer is invalid.');
        self::assertCount(1, $this->http->captured());
    }

    public function testCancellationConsumesStateWithoutTokenExchange(): void
    {
        $query = $this->begin();
        try {
            $this->manager->provider('example')->callback($this->callbackRequest($query['state'], [
                'error' => 'access_denied', 'code' => null,
            ]));
            self::fail('Cancelled provider response was accepted.');
        } catch (OAuthCancelledException $exception) {
            self::assertSame('External sign-in was cancelled.', $exception->getMessage());
        }
        $this->reject(fn () => $this->manager->provider('example')->callback($this->callbackRequest($query['state'])),
            'OIDC callback transaction is invalid or expired.');
        self::assertCount(1, $this->http->captured());
    }

    public function testManagerRejectsUnconfiguredAndInvalidProviderNames(): void
    {
        $this->reject(fn () => $this->manager->provider('unknown'), 'OIDC provider is not configured.');
        $this->reject(fn () => $this->manager->provider('../example'), 'OIDC provider name is invalid.');
        self::assertSame([], $this->http->captured());
    }

    /** @return array<string,string> */
    private function begin(): array
    {
        return self::redirectQuery($this->manager->provider('example')->redirect()->header('Location'));
    }

    /** @return array<string,string> */
    private static function redirectQuery(?string $location): array
    {
        self::assertNotNull($location);
        self::assertStringStartsWith(self::ISSUER . '/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        return $query;
    }

    /** @param array<string,string|null> $extra */
    private function callbackRequest(string $state, array $extra = []): Request
    {
        $parameters = array_merge(['state' => $state, 'iss' => self::ISSUER, 'code' => 'one-time-code'], $extra);
        return new Request('GET', self::CALLBACK . '?'
            . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));
    }

    /** @param array<string,mixed> $overrides */
    private function fakeDiscovery(array $overrides = []): void
    {
        $this->http->fake(['GET ' . self::ISSUER . '/.well-known/openid-configuration'
            => self::discoveryResponse($overrides)]);
    }

    /** @param array<string,mixed> $claims */
    private function fakeSignedToken(array $claims): void
    {
        $this->ensureSigningKey();
        $token = JWT::encode($claims, $this->signingKey, 'RS256', 'fixture-key');
        $this->fakeToken($token, [$this->publicJwk]);
    }

    /** @param list<array<string,string>> $keys */
    private function fakeToken(string $token, array $keys): void
    {
        $routes = [
            'GET ' . self::ISSUER . '/.well-known/openid-configuration'
                => self::discoveryResponse(),
            'POST ' . self::ISSUER . '/token' => new HttpResponse(200, json_encode([
                'id_token' => $token, 'access_token' => self::PRIVATE_ACCESS_TOKEN,
                'refresh_token' => 'provider-refresh-token-do-not-leak',
            ], JSON_THROW_ON_ERROR)),
            'GET ' . self::ISSUER . '/keys' => new HttpResponse(200,
                json_encode(['keys' => $keys], JSON_THROW_ON_ERROR)),
        ];
        $this->http->fake($routes);
    }

    /** @param array<string,mixed> $overrides */
    private static function discoveryResponse(array $overrides = []): HttpResponse
    {
        return new HttpResponse(200, json_encode(array_merge([
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::ISSUER . '/keys',
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ], $overrides), JSON_THROW_ON_ERROR));
    }

    private function ensureSigningKey(): void
    {
        if ($this->signingKey !== null) return;
        [$this->signingKey, $this->publicJwk] = $this->newSigningKey();
    }

    /** @return array{0:OpenSSLAsymmetricKey,1:array<string,string>} */
    private function newSigningKey(): array
    {
        if (!extension_loaded('openssl')) self::markTestSkipped('OpenSSL is required for RS256 fixture.');
        // Some Windows PHP distributions have no default openssl.cnf. Supply
        // a minimal one so this fixture exercises RS256 on every platform.
        $configuration = tempnam(sys_get_temp_dir(), 'oidc-openssl-');
        self::assertNotFalse($configuration);
        try {
            self::assertNotFalse(file_put_contents($configuration, "[req]\ndefault_bits = 2048\n"));
            $key = openssl_pkey_new([
                'config' => $configuration,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ]);
        } finally {
            unlink($configuration);
        }
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $jwk = [
            'kty' => 'RSA', 'kid' => 'fixture-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
        return [$key, $jwk];
    }

    /** @return array<string,mixed> */
    private function validClaims(string $nonce): array
    {
        $now = $this->clock->now()->getTimestamp();
        return [
            'iss' => self::ISSUER, 'sub' => 'user-123', 'aud' => self::CLIENT_ID,
            'nonce' => $nonce, 'iat' => $now, 'exp' => $now + 300,
            'email' => 'person@example.test', 'email_verified' => true, 'name' => 'Person Example',
        ];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function reject(callable $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Invalid OAuth response was accepted.');
        } catch (OAuthException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final class OAuthTestClock implements ModelClock
{
    public function __construct(private int $timestamp) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
