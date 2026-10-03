<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Http\Request;
use App\Http\TrustedProxyPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Verifies that only configured network peers may supply request authority. */
final class TrustedProxyPolicyTest extends TestCase
{
    public function testDefaultPolicyIgnoresBothForwardingFamilies(): void
    {
        $policy = $this->policy();
        $request = $this->request('198.51.100.8', [
            'Forwarded' => 'for=203.0.113.9;proto=https;host=forwarded.example.test',
            'X-Forwarded-For' => '203.0.113.10',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'legacy.example.test',
        ]);

        self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
            $policy->resolve($request));
        self::assertTrue($policy->allowsHost('direct.example.test'));
    }

    public function testImmediatePeerMustBeTrustedEvenWhenForwardedChainContainsATrustedAddress(): void
    {
        $policy = $this->policy(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded']);
        $request = $this->request('198.51.100.8', [
            'X-Forwarded-For' => '203.0.113.9, 10.0.0.8',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'admin.example.test',
        ]);

        self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
            $policy->resolve($request));
    }

    public function testExactIpv4Ipv6AndCidrsAreMatchedAsNetworkAddresses(): void
    {
        $policy = $this->policy([
            'proxies' => ['10.20.30.40', '2001:db8::40', '192.0.2.0/24', '2001:db8:abcd::/48'],
            'profile' => 'x-forwarded',
        ]);
        $headers = ['X-Forwarded-For' => '203.0.113.7'];

        foreach (['10.20.30.40', '2001:db8::40', '192.0.2.0', '192.0.2.255',
            '2001:db8:abcd::', '2001:db8:abcd:ffff::1'] as $peer) {
            self::assertSame('203.0.113.7', $policy->resolve($this->request($peer, $headers))['ip'], $peer);
        }
        foreach (['10.20.30.41', '2001:db8::41', '192.0.3.1', '2001:db8:abce::1'] as $peer) {
            self::assertNull($policy->resolve($this->request($peer, $headers))['ip'], $peer);
        }
    }

    public function testRightmostTrustedHopsAreSkippedButAnUntrustedHopStopsTheWalk(): void
    {
        $policy = $this->policy([
            'proxies' => ['10.0.0.10', '10.0.0.11'], 'profile' => 'x-forwarded',
        ]);
        $request = $this->request('10.0.0.11', [
            'X-Forwarded-For' => '203.0.113.99, 198.51.100.2, 10.0.0.10',
        ]);
        self::assertSame('198.51.100.2', $policy->resolve($request)['ip']);

        $legitimate = $this->request('10.0.0.11', [
            'X-Forwarded-For' => '203.0.113.7, 10.0.0.10',
        ]);
        self::assertSame('203.0.113.7', $policy->resolve($legitimate)['ip']);
    }

    public function testForwardedAuthorityFollowsTheTrustedBoundary(): void
    {
        $policy = $this->policy([
            'proxies' => ['10.0.0.10', '10.0.0.11'], 'profile' => 'forwarded',
        ]);
        $trustedChain = $this->request('10.0.0.11', [
            'Forwarded' => 'for=203.0.113.7;proto=https;host=public.example.test, '
                . 'for=10.0.0.10;proto=http;host=internal-edge.example.test',
        ]);
        self::assertSame(['ip' => '203.0.113.7', 'host' => 'public.example.test', 'scheme' => 'https'],
            $policy->resolve($trustedChain));

        // The leftmost value is supplied through an untrusted intermediary.
        $untrustedMiddle = $this->request('10.0.0.11', [
            'Forwarded' => 'for=203.0.113.99;proto=https;host=attacker.example.test, '
                . 'for=198.51.100.2;proto=http;host=boundary.example.test, '
                . 'for=10.0.0.10;proto=http;host=internal-edge.example.test',
        ]);
        self::assertSame(['ip' => '198.51.100.2', 'host' => 'boundary.example.test', 'scheme' => 'http'],
            $policy->resolve($untrustedMiddle));
    }

    public function testForwardedQuotedValuesEscapesAndIpv6AreParsedWithoutSplittingInsideQuotes(): void
    {
        $policy = $this->policy(['proxies' => ['2001:db8::10'], 'profile' => 'forwarded']);
        $request = $this->request('2001:db8::10', [
            'Forwarded' => 'For="[2001:db8::1234]";Proto=HTTPS;Host="PUBLIC.EXAMPLE.TEST:8443";'
                . 'note="one,two;three\\"four";by=_hidden',
        ]);
        self::assertSame(['ip' => '2001:db8::1234',
            'host' => 'PUBLIC.EXAMPLE.TEST:8443', 'scheme' => 'https'], $policy->resolve($request));
    }

    public function testSelectedProfileCannotBorrowValuesFromOtherHeaderFamily(): void
    {
        $policy = $this->policy(['proxies' => ['10.0.0.8'], 'profile' => 'forwarded']);
        $request = $this->request('10.0.0.8', [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'other.example.test',
        ]);
        self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
            $policy->resolve($request));
    }

    public function testMalformedSelectedProfileDoesNotApplyAnyForwardedMetadata(): void
    {
        $policy = $this->policy(['proxies' => ['10.0.0.8'], 'profile' => 'x-forwarded']);
        foreach ([
            ['X-Forwarded-For' => '999.999.999.999', 'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'admin.example.test'],
            ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'javascript',
                'X-Forwarded-Host' => 'admin.example.test'],
            ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => "admin.example.test\r\nX-Evil: yes"],
            ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https,http',
                'X-Forwarded-Host' => 'admin.example.test'],
        ] as $headers) {
            self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
                $policy->resolve($this->request('10.0.0.8', $headers)));
        }

        $duplicate = $this->request('10.0.0.8', [
            'X-Forwarded-For' => '203.0.113.9', 'x-forwarded-for' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'admin.example.test',
        ]);
        self::assertTrue($duplicate->headerRepeated('X-Forwarded-For'));
        self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
            $policy->resolve($duplicate));
    }

    public function testMalformedRfcForwardedOrAmbiguousRepeatedHeaderRejectsEntireProfile(): void
    {
        $policy = $this->policy(['proxies' => ['10.0.0.8'], 'profile' => 'forwarded']);
        foreach ([
            'for="203.0.113.7;proto=https;host=public.example.test',
            'for=2001:::1;proto=https;host=public.example.test',
            'for=unknown;proto=https;host=public.example.test',
            'for=203.0.113.7;proto=javascript;host=public.example.test',
            'for=203.0.113.7;proto=https;host=public.example.test:99999',
            'for=203.0.113.7;proto=https;host=public.example.test,'
                . 'for=198.51.100.1;proto=https;host=public.example.test,',
            str_repeat('for=203.0.113.7,', 33) . 'for=203.0.113.7',
            "for=203.0.113.7;proto=https;host=public.example.test\0",
        ] as $forwarded) {
            self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
                $policy->resolve($this->request('10.0.0.8', ['Forwarded' => $forwarded])));
        }
        $duplicate = $this->request('10.0.0.8', [
            'Forwarded' => 'for=203.0.113.7;proto=https',
            'forwarded' => 'for=203.0.113.7;proto=https',
        ]);
        self::assertTrue($duplicate->headerRepeated('Forwarded'));
        self::assertSame(['ip' => null, 'host' => null, 'scheme' => null],
            $policy->resolve($duplicate));
    }

    public function testAllowedHostPolicyKeepsExactAndSubdomainBoundaries(): void
    {
        $policy = $this->policy(['allowed_hosts' => ['admin.example.test', '*.example.com']]);
        foreach (['admin.example.test', 'a.example.com', 'a.b.example.com'] as $host) {
            self::assertTrue($policy->allowsHost($host), $host);
        }
        foreach (['example.com', 'evil-example.com', 'admin.example.test.evil'] as $host) {
            self::assertFalse($policy->allowsHost($host), $host);
        }
    }

    public function testInvalidProxyConfigurationFailsBeforeHandlingRequests(): void
    {
        foreach ([
            ['proxies' => ['10.0.0.0/0']],
            ['proxies' => ['2001:db8::/0']],
            ['proxies' => ['10.0.0.1/33']],
            ['proxies' => ['2001:db8::/129']],
            ['proxies' => ['proxy.example.test']],
            ['proxies' => ['999.999.999.999']],
            ['profile' => 'all'],
            ['allowed_hosts' => ['*']],
        ] as $configuration) {
            try {
                $this->policy($configuration);
                self::fail('Invalid proxy configuration was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function policy(array $options = []): TrustedProxyPolicy
    {
        return new TrustedProxyPolicy(new Repository(['trustedProxies' => $options]));
    }

    /** @param array<string, string> $headers */
    private function request(string $peer, array $headers = []): Request
    {
        return new Request('GET', '/', headers: $headers, server: [
            'REMOTE_ADDR' => $peer, 'HTTP_HOST' => 'direct.example.test:8080', 'HTTPS' => 'off',
        ]);
    }
}
