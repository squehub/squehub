<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Foundation\Application;
use App\Http\ExceptionHandler;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\OAuth\OAuthException;
use App\OAuth\OAuthCancelledException;
use App\OAuth\OAuthManager;
use App\OAuth\OAuthProviderConfig;
use App\OAuth\OAuthTransactionStore;
use App\Session\Drivers\ArraySessionDriver;
use App\Session\SessionManager;
use App\Session\SessionStore;
use App\Support\SecretRedactor;
use App\Support\SensitiveKey;
use PHPUnit\Framework\TestCase;

/** Configuration and privacy boundaries for an opt-in external identity client. */
final class OAuthConfigurationTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function providerSettings(): array
    {
        return [
            'issuer' => 'https://identity.example.test/tenant',
            'client_id' => 'application-client',
            'redirect_uri' => 'https://app.example.test/auth/callback',
            'scopes' => ['openid', 'profile'],
        ];
    }

    public function testTrustedEndpointsAndDistinctApplicationCallbackOrigin(): void
    {
        $config = new OAuthProviderConfig('company.oidc', self::providerSettings());
        self::assertSame('company.oidc', $config->name());
        self::assertSame(['openid', 'profile'], $config->scopes());
        $config->validateEndpoint('https://identity.example.test/tenant/token');
        self::assertSame('https://app.example.test/auth/callback', $config->redirectUri());
    }

    public function testDottedProviderNameRemainsOneConfigurationKey(): void
    {
        $repository = new Repository([
            'session' => ['driver' => 'array'],
            'oauth' => ['providers' => ['company.oidc' => self::providerSettings()]],
        ]);
        $manager = new OAuthManager($repository, new HttpClient(), new SessionManager($repository));
        self::assertInstanceOf(\App\OAuth\OAuthProvider::class, $manager->provider('company.oidc'));
    }

    /** @dataProvider invalidProviderSettings */
    public function testInvalidProviderConfigurationFailsBeforeAnyRequest(array $override): void
    {
        $this->expectException(OAuthException::class);
        new OAuthProviderConfig('company', array_replace(self::providerSettings(), $override));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidProviderSettings(): iterable
    {
        yield 'insecure issuer' => [['issuer' => 'http://identity.example.test']];
        yield 'loopback without explicit policy' => [['issuer' => 'http://127.0.0.1:8123']];
        yield 'issuer userinfo' => [['issuer' => 'https://user:password@identity.example.test']];
        yield 'issuer fragment' => [['issuer' => 'https://identity.example.test/#frag']];
        yield 'issuer query' => [['issuer' => 'https://identity.example.test/?x=1']];
        yield 'private IP literal' => [['issuer' => 'https://10.1.2.3']];
        yield 'missing openid' => [['scopes' => ['profile']]];
        yield 'duplicate scope' => [['scopes' => ['openid', 'openid']]];
        yield 'unsupported algorithm' => [['signing_algorithm' => 'HS256']];
        yield 'excessive skew' => [['clock_skew' => 301]];
        yield 'issuer omitted from trust' => [['trusted_hosts' => ['elsewhere.example.test']]];
        yield 'redirect fragment' => [['redirect_uri' => 'https://app.example.test/callback#fragment']];
        yield 'redirect query' => [['redirect_uri' => 'https://app.example.test/callback?next=/']];
    }

    public function testExplicitLoopbackPolicyIsNarrow(): void
    {
        $config = new OAuthProviderConfig('local', [
            'issuer' => 'http://127.0.0.1:8123',
            'client_id' => 'local-client',
            'redirect_uri' => 'http://localhost:8000/auth/callback',
            'allow_local_http' => true,
        ]);
        $config->validateEndpoint('http://127.0.0.1:8123/token');
        try {
            $config->validateEndpoint('http://169.254.169.254/latest/meta-data');
            self::fail('A metadata service endpoint was accepted.');
        } catch (OAuthException) {
            self::assertTrue(true);
        }
    }

    public function testConfigSecretsAreRedactedWithoutMaskingOrdinaryApiState(): void
    {
        $secret = 'client-secret-only-for-redaction';
        $redactor = new SecretRedactor(new Repository([
            'oauth' => ['providers' => ['company' => ['client_secret' => $secret]]],
        ]));
        self::assertSame('credential [REDACTED]', $redactor->redact('credential ' . $secret));
        self::assertTrue(SensitiveKey::matches('code_verifier'));
        self::assertTrue(SensitiveKey::matches('oauth_state'));
        self::assertTrue(SensitiveKey::matches('oidc_nonce'));
        self::assertFalse(SensitiveKey::matches('state'));
    }

    public function testTransactionPolicyMustBeShortAndBounded(): void
    {
        $session = new SessionStore(new ArraySessionDriver());
        foreach ([[59, 8], [901, 8], [600, 0], [600, 17]] as [$ttl, $maximum]) {
            try {
                new OAuthTransactionStore($session, null, $ttl, $maximum);
                self::fail('Invalid OAuth transaction policy was accepted.');
            } catch (OAuthException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCancellationUsesSafeApiEnvelopeWithoutProviderText(): void
    {
        $app = new Application(BASE_DIR);
        $app->config()->set('api.enabled', true);
        $response = (new ExceptionHandler($app))->render(
            new OAuthCancelledException('provider-private-description'),
            new Request('GET', '/api/auth/callback')
        );
        self::assertSame(400, $response->status());
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('oauth_cancelled', json_decode($response->content(), true)['error']['code']);
        self::assertStringNotContainsString('provider-private-description', $response->content());
    }
}
