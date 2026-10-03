<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\ModelClock;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpResponse;
use App\OAuth\IdTokenVerifier;
use App\OAuth\OAuthException;
use App\OAuth\OAuthProviderConfig;
use App\OAuth\OidcProtocol;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

/** OIDC trust-boundary checks using only test-owned keys and HTTP fakes. */
final class OidcProtocolSecurityTest extends TestCase
{
    private const ISSUER = 'https://id.example.test';
    private const CLIENT_ID = 'security-test-client';
    private const NONCE = 'security-test-nonce';
    private const JWKS_URI = self::ISSUER . '/keys';
    private const NOW = 1_700_000_000;

    /** @var array<string,OpenSSLAsymmetricKey> */
    private static array $privateKeys = [];
    /** @var array<string,array<string,string>> */
    private static array $publicKeys = [];

    private HttpClient $http;
    private OidcSecurityClock $clock;
    private OAuthProviderConfig $config;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('openssl')) self::markTestSkipped('OpenSSL is required for RS256 fixtures.');

        // Windows PHP may not have a default OpenSSL configuration file.
        $configuration = tempnam(sys_get_temp_dir(), 'oidc-security-openssl-');
        self::assertNotFalse($configuration);
        try {
            self::assertNotFalse(file_put_contents($configuration, "[req]\ndefault_bits = 2048\n"));
            foreach (['first', 'second'] as $name) {
                $key = openssl_pkey_new([
                    'config' => $configuration,
                    'private_key_type' => OPENSSL_KEYTYPE_RSA,
                    'private_key_bits' => 2048,
                ]);
                self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
                $details = openssl_pkey_get_details($key);
                self::assertIsArray($details);
                self::assertIsArray($details['rsa'] ?? null);
                self::$privateKeys[$name] = $key;
                self::$publicKeys[$name] = [
                    'kty' => 'RSA', 'kid' => 'shared-kid', 'alg' => 'RS256', 'use' => 'sig',
                    'n' => self::base64Url($details['rsa']['n']),
                    'e' => self::base64Url($details['rsa']['e']),
                ];
            }
        } finally {
            unlink($configuration);
        }
    }

    protected function setUp(): void
    {
        $this->http = new HttpClient();
        $this->http->fake([]); // An unmatched request must never reach a transport.
        $this->clock = new OidcSecurityClock(self::NOW);
        $this->config = $this->config();
    }

    public function testValidRsaFixtureProducesAnExternalIdentity(): void
    {
        $this->fakeJwks(self::$publicKeys['first']);
        $identity = $this->verifier()->verify($this->config, $this->token(), self::JWKS_URI, self::NONCE);

        self::assertSame('example', $identity->provider());
        self::assertSame(self::ISSUER, $identity->issuer());
        self::assertSame('user-123', $identity->subject());
        self::assertSame(['GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    public function testSignatureMustMatchTrustedJwksMaterial(): void
    {
        $this->fakeJwks(self::$publicKeys['first']);

        $this->reject(
            fn () => $this->verifier()->verify($this->config, $this->token([], 'second'),
                self::JWKS_URI, self::NONCE),
            'ID token signature is invalid.'
        );
        self::assertSame(['GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    public function testUnsignedSymmetricAndRemoteKeyHeadersAreRejectedBeforeKeyFetch(): void
    {
        $claims = $this->claims();
        foreach ([
            ['alg' => 'none', 'kid' => 'shared-kid'],
            ['alg' => 'HS256', 'kid' => 'shared-kid'],
            ['alg' => 'RS256', 'kid' => 'shared-kid', 'jku' => 'https://attacker.example.test/keys'],
            ['alg' => 'RS256', 'kid' => 'shared-kid', 'jwk' => self::$publicKeys['first']],
            ['alg' => 'RS256', 'kid' => 'shared-kid', 'crit' => ['exp']],
        ] as $header) {
            $jwt = self::base64Url(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
                . self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR)) . '.signature';
            $this->reject(fn () => $this->verifier()->verify($this->config, $jwt,
                self::JWKS_URI, self::NONCE), 'ID token header is invalid.');
        }
        self::assertSame([], $this->capturedRequests());
    }

    public function testIssuerAudienceNonceAndAuthorizedPartyMustMatch(): void
    {
        $this->fakeJwks(self::$publicKeys['first']);
        $verifier = $this->verifier();
        $claims = $this->claims();
        foreach ([
            ['iss' => 'https://other.example.test'],
            ['aud' => 'another-client'],
            ['nonce' => 'wrong-nonce'],
            ['aud' => [self::CLIENT_ID, 'other-client']],
            ['aud' => [self::CLIENT_ID, 'other-client'], 'azp' => 'other-client'],
            ['azp' => 'other-client'],
        ] as $changes) {
            $jwt = $this->token(array_replace($claims, $changes));
            $this->reject(fn () => $verifier->verify($this->config, $jwt,
                self::JWKS_URI, self::NONCE), 'ID token claims are invalid.');
        }
        self::assertSame(['GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    public function testExpiredAndFutureTokensAreRejected(): void
    {
        $this->fakeJwks(self::$publicKeys['first']);
        $verifier = $this->verifier();
        foreach ([
            ['exp' => self::NOW - 1],
            ['iat' => self::NOW + 301, 'exp' => self::NOW + 600],
            ['nbf' => self::NOW + 301],
        ] as $changes) {
            $jwt = $this->token(array_replace($this->claims(), $changes));
            $this->reject(fn () => $verifier->verify($this->config, $jwt,
                self::JWKS_URI, self::NONCE), 'ID token verification failed.');
        }
        self::assertSame(['GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    public function testRequiredTimingClaimsAreValidatedAfterSignatureVerification(): void
    {
        $this->fakeJwks(self::$publicKeys['first']);
        $verifier = $this->verifier();
        foreach (['exp', 'iat'] as $missing) {
            $claims = $this->claims();
            unset($claims[$missing]);
            $this->reject(fn () => $verifier->verify($this->config, $this->token($claims),
                self::JWKS_URI, self::NONCE), 'ID token timing is invalid.');
        }
        self::assertSame(['GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    public function testMalformedAmbiguousAndPrivateJwksAreRejected(): void
    {
        $private = self::$publicKeys['first'];
        $private['d'] = 'AQAB';
        foreach ([
            ['body' => '{bad-json', 'error' => 'OIDC signing keys are invalid.'],
            ['body' => self::json(['keys' => [self::$publicKeys['first'], self::$publicKeys['first']]]),
                'error' => 'OIDC signing keys are ambiguous.'],
            ['body' => self::json(['keys' => [$private]]), 'error' => 'OIDC signing keys are invalid.'],
            ['body' => self::json(['keys' => []]), 'error' => 'OIDC signing keys are unavailable.'],
            ['body' => self::json(['keys' => array_fill(0, 33, self::$publicKeys['first'])]),
                'error' => 'OIDC signing keys are invalid.'],
        ] as $case) {
            $this->http->fake(['GET ' . self::JWKS_URI => new HttpResponse(200, $case['body'])]);
            $this->reject(fn () => $this->verifier()->verify($this->config, $this->token(),
                self::JWKS_URI, self::NONCE), $case['error']);
            self::assertSame(['GET ' . self::JWKS_URI], $this->capturedRequests());
        }
    }

    public function testDiscoveryRejectsWrongIssuerAndUntrustedEndpoints(): void
    {
        $protocol = new OidcProtocol($this->http, $this->clock);
        foreach ([
            ['issuer' => 'https://other.example.test', 'error' => 'OIDC provider metadata is incompatible.'],
            ['jwks_uri' => 'https://attacker.example.test/keys', 'error' => 'OIDC endpoint host is not trusted.'],
            ['token_endpoint' => 'http://id.example.test/token', 'error' => 'OIDC endpoints require HTTPS.'],
        ] as $case) {
            $error = $case['error'];
            unset($case['error']);
            $document = array_replace($this->metadata(), $case);
            $this->http->fake(['GET ' . self::ISSUER . '/.well-known/openid-configuration'
                => new HttpResponse(200, self::json($document))]);
            $this->reject(fn () => $protocol->metadata($this->config), $error);
            self::assertSame(['GET ' . self::ISSUER . '/.well-known/openid-configuration'],
                $this->capturedRequests());
        }
    }

    public function testExchangeRejectsTokenEndpointErrorAndMalformedTokenResponse(): void
    {
        $protocol = new OidcProtocol($this->http, $this->clock);
        foreach ([
            ['response' => new HttpResponse(400, self::json(['error' => 'invalid_grant'])),
                'error' => 'OIDC token exchange failed.'],
            ['response' => new HttpResponse(302, '',
                ['Location' => ['https://attacker.example.test/collect']]),
                'error' => 'OIDC token exchange failed.'],
            ['response' => new HttpResponse(200, '{bad-json'),
                'error' => 'OIDC token response is invalid.'],
            ['response' => new HttpResponse(200, self::json(['access_token' => 'access-only'])),
                'error' => 'OIDC token response is invalid.'],
        ] as $case) {
            $this->http->fake(['POST ' . self::ISSUER . '/token' => $case['response']]);
            $this->reject(fn () => $protocol->exchange($this->config, $this->metadata(),
                'authorization-code', str_repeat('v', 43), self::NONCE), $case['error']);
            self::assertSame(['POST ' . self::ISSUER . '/token'], $this->capturedRequests());
        }
    }

    public function testPublicClientRequiresNoneAuthMethodAndSendsClientIdInForm(): void
    {
        $public = $this->config('public', null);
        $protocol = new OidcProtocol($this->http, $this->clock);
        $basicOnly = $this->metadata();
        $this->reject(fn () => $protocol->exchange($public, $basicOnly,
            'authorization-code', str_repeat('v', 43), self::NONCE),
            'OIDC provider authentication method is unsupported.');
        self::assertSame([], $this->capturedRequests());

        $supportsPublicClient = array_replace($basicOnly,
            ['token_endpoint_auth_methods_supported' => ['none']]);
        $this->http->fake(['POST ' . self::ISSUER . '/token'
            => new HttpResponse(400, self::json(['error' => 'invalid_grant']))]);
        $this->reject(fn () => $protocol->exchange($public, $supportsPublicClient,
            'authorization-code', str_repeat('v', 43), self::NONCE),
            'OIDC token exchange failed.');

        $requests = $this->http->captured();
        self::assertCount(1, $requests);
        self::assertSame('POST ' . self::ISSUER . '/token', $requests[0]->method . ' ' . $requests[0]->url);
        self::assertArrayNotHasKey('authorization', $requests[0]->headers);
        parse_str((string) $requests[0]->body, $body);
        self::assertSame(self::CLIENT_ID, $body['client_id'] ?? null);
        self::assertSame(str_repeat('v', 43), $body['code_verifier'] ?? null);
    }

    public function testExchangeRejectsUntrustedMetadataBeforeSendingCode(): void
    {
        $metadata = array_replace($this->metadata(), ['token_endpoint' => 'https://attacker.example.test/token']);
        $protocol = new OidcProtocol($this->http, $this->clock);

        $this->reject(fn () => $protocol->exchange($this->config, $metadata,
            'authorization-code', str_repeat('v', 43), self::NONCE), 'OIDC endpoint host is not trusted.');
        self::assertSame([], $this->capturedRequests());
    }

    public function testCachedKeyIsRefreshedWhenProviderRotatesMaterialUnderSameKid(): void
    {
        $this->http->fake(['GET ' . self::JWKS_URI => [
            new HttpResponse(200, self::json(['keys' => [self::$publicKeys['first']]])),
            new HttpResponse(200, self::json(['keys' => [self::$publicKeys['second']]])),
        ]]);
        $verifier = $this->verifier();

        self::assertSame('user-123', $verifier->verify($this->config, $this->token(),
            self::JWKS_URI, self::NONCE)->subject());
        self::assertSame('user-123', $verifier->verify($this->config, $this->token([], 'second'),
            self::JWKS_URI, self::NONCE)->subject());
        self::assertSame(['GET ' . self::JWKS_URI, 'GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    public function testJwksCacheIsIsolatedByProviderIdentity(): void
    {
        $this->http->fake(['GET ' . self::JWKS_URI => [
            new HttpResponse(200, self::json(['keys' => [self::$publicKeys['first']]])),
            new HttpResponse(200, self::json(['keys' => [self::$publicKeys['second']]])),
        ]]);
        $verifier = $this->verifier();
        $jwt = $this->token();

        self::assertSame('user-123', $verifier->verify($this->config, $jwt,
            self::JWKS_URI, self::NONCE)->subject());
        $otherProvider = $this->config('other');
        $this->reject(fn () => $verifier->verify($otherProvider, $jwt,
            self::JWKS_URI, self::NONCE), 'ID token signature is invalid.');
        self::assertSame(['GET ' . self::JWKS_URI, 'GET ' . self::JWKS_URI], $this->capturedRequests());
    }

    private function config(string $name = 'example', ?string $clientSecret = 'test-only-secret'): OAuthProviderConfig
    {
        return new OAuthProviderConfig($name, [
            'issuer' => self::ISSUER,
            'client_id' => self::CLIENT_ID,
            'client_secret' => $clientSecret,
            'redirect_uri' => 'https://app.example.test/auth/callback',
            'clock_skew' => 0,
        ]);
    }

    private function verifier(): IdTokenVerifier
    {
        return new IdTokenVerifier($this->http, $this->clock);
    }

    /** @return array<string,mixed> */
    private function claims(): array
    {
        return [
            'iss' => self::ISSUER, 'sub' => 'user-123', 'aud' => self::CLIENT_ID,
            'nonce' => self::NONCE, 'iat' => self::NOW, 'exp' => self::NOW + 300,
        ];
    }

    /** @param array<string,mixed> $claims */
    private function token(array $claims = [], string $key = 'first'): string
    {
        return JWT::encode($claims === [] ? $this->claims() : $claims,
            self::$privateKeys[$key], 'RS256', 'shared-kid');
    }

    /** @param array<string,string> $jwk */
    private function fakeJwks(array $jwk): void
    {
        $this->http->fake(['GET ' . self::JWKS_URI
            => new HttpResponse(200, self::json(['keys' => [$jwk]]))]);
    }

    /** @return array<string,mixed> */
    private function metadata(): array
    {
        return [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'jwks_uri' => self::JWKS_URI,
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
    }

    /** @return list<string> */
    private function capturedRequests(): array
    {
        return array_map(static fn ($request): string => $request->method . ' ' . $request->url,
            $this->http->captured());
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function reject(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('An invalid OIDC input was accepted.');
        } catch (OAuthException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}

final class OidcSecurityClock implements ModelClock
{
    public function __construct(private int $timestamp) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
