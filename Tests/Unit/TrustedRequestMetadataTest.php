<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Http\BrowserNavigation;
use App\Http\Request;
use App\Http\TrustedProxyPolicy;
use App\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/** Keeps captured server values separate from a policy's effective authority. */
final class TrustedRequestMetadataTest extends TestCase
{
    public function testRequestIsDirectUntilAnExplicitPolicyIsApplied(): void
    {
        $request = $this->request('10.0.0.8', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'Public.Example.Test:8443',
        ]);
        self::assertSame('10.0.0.8', $request->ip());
        self::assertSame('internal.example.test', $request->host());
        self::assertSame('http', $request->scheme());
        self::assertSame(8080, $request->port());

        $request->applyTrustedProxyPolicy($this->policy(['proxies' => ['10.0.0.8'],
            'profile' => 'x-forwarded']));
        self::assertSame('203.0.113.7', $request->ip());
        self::assertSame('public.example.test', $request->host());
        self::assertSame('https', $request->scheme());
        self::assertSame(8443, $request->port());
        self::assertSame('10.0.0.8', $request->server('REMOTE_ADDR'));
        self::assertSame('internal.example.test:8080', $request->server('HTTP_HOST'));
        self::assertSame('off', $request->server('HTTPS'));
    }

    public function testUntrustedPeerKeepsDirectHostSchemeIpAndPort(): void
    {
        $request = $this->request('198.51.100.8', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'admin.example.test:8443',
        ]);
        $request->applyTrustedProxyPolicy($this->policy(['proxies' => ['10.0.0.8'],
            'profile' => 'x-forwarded']));
        self::assertSame('198.51.100.8', $request->ip());
        self::assertSame('internal.example.test', $request->host());
        self::assertSame('http', $request->scheme());
        self::assertSame(8080, $request->port());
    }

    public function testRfcForwardedQuotedIpv6AndHostPortAreNormalized(): void
    {
        $request = $this->request('2001:db8::10', [
            'Forwarded' => 'for="[2001:db8::1234]";proto=https;host="PUBLIC.EXAMPLE.TEST:8443"',
        ]);
        $request->applyTrustedProxyPolicy($this->policy(['proxies' => ['2001:db8::10'],
            'profile' => 'forwarded']));
        self::assertSame('2001:db8::1234', $request->ip());
        self::assertSame('public.example.test', $request->host());
        self::assertSame('https', $request->scheme());
        self::assertSame(8443, $request->port());
    }

    public function testUnknownForwardedIdentifierCannotBecomeClientIp(): void
    {
        $request = $this->request('10.0.0.8', [
            'Forwarded' => 'for=_hidden;proto=https;host=public.example.test',
        ]);
        $request->applyTrustedProxyPolicy($this->policy(['proxies' => ['10.0.0.8'],
            'profile' => 'forwarded']));
        self::assertSame('10.0.0.8', $request->ip());
        self::assertSame('internal.example.test', $request->host());
        self::assertSame('http', $request->scheme());
    }

    public function testTrustedSchemeWithoutForwardedHostUsesPublicDefaultPort(): void
    {
        $request = $this->request('10.0.0.8', [
            'X-Forwarded-Proto' => 'https',
        ]);
        $request->applyTrustedProxyPolicy($this->policy(['proxies' => ['10.0.0.8'],
            'profile' => 'x-forwarded']));
        self::assertSame('internal.example.test', $request->host());
        self::assertSame('https', $request->scheme());
        self::assertSame(443, $request->port());
        self::assertSame('internal.example.test:8080', $request->server('HTTP_HOST'));
    }

    public function testSequentialRequestsCannotShareEffectiveAuthority(): void
    {
        $policy = $this->policy(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded']);
        $proxied = $this->request('10.0.0.8', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example.test',
        ]);
        $proxied->applyTrustedProxyPolicy($policy);
        self::assertSame('public.example.test', $proxied->host());
        self::assertSame(443, $proxied->port());

        $direct = $this->request('198.51.100.8', [
            'X-Forwarded-For' => '192.0.2.1',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example.test',
        ]);
        $direct->applyTrustedProxyPolicy($policy);
        self::assertSame('198.51.100.8', $direct->ip());
        self::assertSame('internal.example.test', $direct->host());
        self::assertSame('http', $direct->scheme());
        self::assertSame(8080, $direct->port());
        self::assertSame('public.example.test', $proxied->host());
    }

    public function testBrowserSameOriginRefererUsesTrustedPublicAuthorityAndPort(): void
    {
        $sessions = new SessionManager(new Repository(['session' => ['driver' => 'array']]));
        $sessions->store()->setPreviousPath('/submit');
        $navigation = new BrowserNavigation($sessions);
        $policy = $this->policy(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded']);
        $headers = [
            'Referer' => 'https://public.example.test:8443/form?token=discarded',
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'public.example.test:8443',
        ];
        $trusted = new Request('POST', '/submit', headers: $headers, server: [
            'REMOTE_ADDR' => '10.0.0.8', 'HTTP_HOST' => 'internal.example.test:8080',
            'SERVER_PORT' => '8080', 'HTTPS' => 'off',
        ]);
        $trusted->applyTrustedProxyPolicy($policy);
        self::assertSame('/form', $navigation->back($trusted));

        $direct = new Request('POST', '/submit', headers: $headers, server: [
            'REMOTE_ADDR' => '198.51.100.8', 'HTTP_HOST' => 'internal.example.test:8080',
            'SERVER_PORT' => '8080', 'HTTPS' => 'off',
        ]);
        $direct->applyTrustedProxyPolicy($policy);
        self::assertNull($navigation->back($direct));
    }

    /** @param array<string, mixed> $options */
    private function policy(array $options): TrustedProxyPolicy
    {
        return new TrustedProxyPolicy(new Repository(['trustedProxies' => $options]));
    }

    /** @param array<string, string> $headers */
    private function request(string $peer, array $headers = []): Request
    {
        return new Request('GET', '/', headers: $headers, server: [
            'REMOTE_ADDR' => $peer,
            'HTTP_HOST' => 'internal.example.test:8080',
            'SERVER_PORT' => '8080',
            'HTTPS' => 'off',
        ]);
    }
}
