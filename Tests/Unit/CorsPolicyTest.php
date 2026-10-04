<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Api\ApiError;
use App\Api\CorsPolicy;
use App\Config\Repository;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CorsPolicyTest extends TestCase
{
    public function testDefaultPolicyIsDisabledAndOrdinaryOptionsIsNotPreflight(): void
    {
        $policy = new CorsPolicy(new Repository());
        $request = $this->request('OPTIONS', '/api/users', []);
        self::assertFalse($policy->isPreflight($request));
        self::assertNull($policy->preflight($request, static fn (): bool => true));
        $response = new Response('ordinary');
        self::assertSame($response, $policy->decorate($request, $response));

        $attempt = $this->request('OPTIONS', '/api/users', [
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST',
        ]);
        self::assertTrue($policy->isPreflight($attempt));
        self::assertNull($policy->preflight($attempt, static fn (): bool => true));
        self::assertSame($response, $policy->decorate($attempt, $response));

        $originOnly = $this->request('OPTIONS', '/api/users', ['Origin' => 'https://app.example.com']);
        self::assertFalse($policy->isPreflight($originOnly));
    }

    public function testExactOriginAndMethodReceiveActualResponseHeadersAndVaryIsMerged(): void
    {
        $policy = $this->policy([
            'allowed_origins' => ['https://app.example.com:8443'],
            'exposed_headers' => ['X-Request-ID'],
            'allow_credentials' => true,
        ]);
        $request = $this->request('get', '/api/users', ['Origin' => 'HTTPS://APP.EXAMPLE.COM:8443']);
        $response = $policy->decorate($request,
            new Response('ok', 200, ['Vary' => 'Accept-Encoding, origin, ACCEPT-ENCODING']));

        self::assertSame('HTTPS://APP.EXAMPLE.COM:8443', $response->header('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->header('Access-Control-Allow-Credentials'));
        self::assertSame('X-Request-ID', $response->header('Access-Control-Expose-Headers'));
        self::assertSame('Accept-Encoding, origin', $response->header('Vary'));
        self::assertSame('ok', $response->content());
    }

    public function testOriginMatchingRejectsLookalikesSchemePortAndMalformedValues(): void
    {
        $policy = $this->policy(['allowed_origins' => ['https://example.com']]);
        foreach ([
            'https://example.com.attacker.test', 'https://example.com.evil',
            'http://example.com', 'https://example.com:8443',
            'https://example.com/path', 'https://example.com/',
            'https://user@example.com', 'https://example.com?x=1',
            'https://example.com, https://evil.test', 'null',
        ] as $origin) {
            $response = $policy->decorate($this->request('GET', '/api/users', ['Origin' => $origin]), new Response('ok'));
            self::assertNull($response->header('Access-Control-Allow-Origin'), $origin);
            self::assertSame('Origin', $response->header('Vary'), $origin);
        }
        $defaultPort = $policy->decorate($this->request('GET', '/api/users',
            ['Origin' => 'https://example.com:443']), new Response('ok'));
        self::assertSame('https://example.com:443', $defaultPort->header('Access-Control-Allow-Origin'));
    }

    public function testWildcardNeverCoversNullAndCredentialsWithWildcardFailAtConstruction(): void
    {
        $policy = $this->policy(['allowed_origins' => ['*']]);
        $allowed = $policy->decorate($this->request('GET', '/api/x',
            ['Origin' => 'https://any.example']), new Response());
        self::assertSame('*', $allowed->header('Access-Control-Allow-Origin'));
        self::assertNull($allowed->header('Access-Control-Allow-Credentials'));
        $null = $policy->decorate($this->request('GET', '/api/x', ['Origin' => 'null']), new Response());
        self::assertNull($null->header('Access-Control-Allow-Origin'));

        $explicit = $this->policy(['allowed_origins' => ['null']]);
        $null = $explicit->decorate($this->request('GET', '/api/x', ['Origin' => 'null']), new Response());
        self::assertSame('null', $null->header('Access-Control-Allow-Origin'));

        $this->expectException(InvalidArgumentException::class);
        $this->policy(['allowed_origins' => ['*'], 'allow_credentials' => true]);
    }

    public function testPreflightChecksPolicyAndRouterWithoutInvokingApplicationCode(): void
    {
        $policy = $this->policy([
            'allowed_origins' => ['https://app.example.com'],
            'allowed_methods' => ['post'],
            'allowed_headers' => ['Content-Type', 'X-CSRF-Token'],
            'allow_credentials' => true,
            'max_age' => 900,
        ]);
        $request = $this->request('OPTIONS', '/api/users', [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => ' pOsT ',
            'Access-Control-Request-Headers' => 'content-type, X-CsRf-ToKeN',
        ]);
        $probes = [];
        $response = $policy->preflight($request, static function (string $method) use (&$probes): bool {
            $probes[] = $method;
            return true;
        });
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(['POST'], $probes);
        self::assertSame(204, $response->status());
        self::assertSame('', $response->content());
        self::assertSame('https://app.example.com', $response->header('Access-Control-Allow-Origin'));
        self::assertSame('POST', $response->header('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, X-CSRF-Token', $response->header('Access-Control-Allow-Headers'));
        self::assertSame('true', $response->header('Access-Control-Allow-Credentials'));
        self::assertSame('900', $response->header('Access-Control-Max-Age'));
        self::assertSame('Origin, Access-Control-Request-Method, Access-Control-Request-Headers', $response->header('Vary'));
        self::assertEquals($response->headers(), $policy->decorate($request, $response)->headers());
    }

    public function testDeniedPreflightsNeverInvokeRouteProbeAndCannotReceiveAllowOrigin(): void
    {
        $policy = $this->policy([
            'allowed_origins' => ['https://app.example.com'],
            'allowed_methods' => ['POST'],
            'allowed_headers' => ['Content-Type'],
        ]);
        foreach ([
            ['Origin' => 'https://evil.test', 'Access-Control-Request-Method' => 'POST'],
            ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'DELETE'],
            ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'X-Private'],
            ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => "Content-Type\r\nX-Private"],
            ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'TRACE'],
        ] as $headers) {
            $request = $this->request('OPTIONS', '/api/users', $headers);
            self::assertTrue($policy->isPreflight($request));
            $this->assertDenied(static fn () => $policy->preflight($request,
                static function (): bool { self::fail('Denied preflight probed the Router.'); }));
            $denial = $policy->decorate($request, new Response('', 403, ['Vary' => 'Accept-Encoding']));
            self::assertNull($denial->header('Access-Control-Allow-Origin'));
            self::assertSame('Accept-Encoding, Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
                $denial->header('Vary'));
        }
        $request = $this->request('OPTIONS', '/api/users', [
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertDenied(static fn () => $policy->preflight($request, static fn (): bool => false));
    }

    public function testScopedDownstreamCorsHeadersCannotOverridePolicy(): void
    {
        $policy = $this->policy(['allowed_origins' => ['https://app.example.com']]);
        $conflicting = new Response('normal', 401, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => 'GET, POST, DELETE',
            'Access-Control-Allow-Headers' => 'Authorization',
            'Access-Control-Expose-Headers' => 'Set-Cookie',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Accept-Encoding',
        ]);
        $denied = $policy->decorate($this->request('GET', '/api/x',
            ['Origin' => 'https://evil.test']), $conflicting);
        foreach (['Allow-Origin', 'Allow-Credentials', 'Allow-Methods', 'Allow-Headers',
            'Expose-Headers', 'Max-Age'] as $suffix) {
            self::assertNull($denied->header('Access-Control-' . $suffix));
        }
        self::assertSame('Accept-Encoding, Origin', $denied->header('Vary'));
        self::assertSame(401, $denied->status());
        self::assertSame('normal', $denied->content());

        $allowed = $policy->decorate($this->request('GET', '/api/x',
            ['Origin' => 'https://app.example.com']), $conflicting);
        self::assertSame('https://app.example.com', $allowed->header('Access-Control-Allow-Origin'));
        self::assertNull($allowed->header('Access-Control-Allow-Credentials'));
        self::assertNull($allowed->header('Access-Control-Expose-Headers'));

        $preflight = $this->request('OPTIONS', '/api/x', [
            'Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET',
        ]);
        $deniedPreflight = $policy->decorate($preflight, $conflicting);
        self::assertNull($deniedPreflight->header('Access-Control-Allow-Origin'));
    }

    public function testScopeIsSegmentAwareAndOrdinaryWebAndHealthStayUnaffected(): void
    {
        $policy = $this->policy(['allowed_origins' => ['https://app.example.com']]);
        foreach (['/apix', '/health/live', '/health/ready', '/'] as $path) {
            $request = $this->request('GET', $path, ['Origin' => 'https://app.example.com']);
            $response = new Response('ok');
            self::assertSame($response, $policy->decorate($request, $response), $path);
        }
        $allowed = $policy->decorate($this->request('GET', '/api/v1/users',
            ['Origin' => 'https://app.example.com']), new Response());
        self::assertSame('https://app.example.com', $allowed->header('Access-Control-Allow-Origin'));
    }

    public function testDeniedActualOriginContinuesApplicationAndVaryMergesWithoutDuplicates(): void
    {
        $policy = $this->policy(['allowed_origins' => ['https://app.example.com']]);
        $request = $this->request('GET', '/api/users', ['Origin' => 'https://denied.example']);
        $response = $policy->decorate($request, new Response('normal body', 422, ['Vary' => 'Origin, Accept']));
        self::assertSame(422, $response->status());
        self::assertSame('normal body', $response->content());
        self::assertNull($response->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin, Accept', $response->header('Vary'));
        $missingOrigin = $policy->decorate($this->request('GET', '/api/users', []), new Response());
        self::assertNull($missingOrigin->header('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $missingOrigin->header('Vary'));
        $methodDenied = $this->policy([
            'allowed_origins' => ['https://app.example.com'], 'allowed_methods' => ['GET'],
        ])->decorate($this->request('DELETE', '/api/users', ['Origin' => 'https://app.example.com']),
            new Response('application decides', 405));
        self::assertNull($methodDenied->header('Access-Control-Allow-Origin'));
        self::assertSame(405, $methodDenied->status());
    }

    public function testConflictingPreflightHeaderCopiesAreDeniedBeforeRouterProbe(): void
    {
        $policy = $this->policy([
            'allowed_origins' => ['https://app.example.com'],
            'allowed_headers' => ['Content-Type', 'X-CSRF-Token'],
        ]);
        foreach ([
            ['Origin' => 'https://evil.test', 'origin' => 'https://app.example.com',
                'Access-Control-Request-Method' => 'GET'],
            ['Origin' => 'https://app.example.com',
                'Access-Control-Request-Method' => 'POST', 'access-control-request-method' => 'GET'],
            ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'X-Private',
                'access-control-request-headers' => 'Content-Type'],
        ] as $headers) {
            $request = $this->request('OPTIONS', '/api/users', $headers);
            $this->assertDenied(static fn () => $policy->preflight($request,
                static function (): bool { self::fail('Conflicting header probed the Router.'); }));
        }
    }

    public function testInvalidConfigurationIsRejectedEvenWhenDisabledAndInstancesAreIsolated(): void
    {
        foreach ([
            ['enabled' => 'yes'], ['paths' => ['/api*']], ['paths' => ['/api/../admin']],
            ['allowed_origins' => ['https://example.com/path']],
            ['allowed_origins' => ['https://example.com.evil/']],
            ['allowed_methods' => ['TRACE']], ['allowed_methods' => ['GET\n']],
            ['allowed_headers' => ['Bad Header']], ['exposed_headers' => ['Bad Header']],
            ['exposed_headers' => ['Set-Cookie']], ['exposed_headers' => ['Cookie']],
            ['allow_credentials' => 'yes'], ['max_age' => -1], ['max_age' => 86401],
            ['max_age' => null], ['enabled' => null], ['allowed_origins' => null],
        ] as $invalid) {
            try {
                $this->policy(['enabled' => false, ...$invalid]);
                self::fail('Invalid CORS configuration was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('CORS', $exception->getMessage());
            }
        }
        $first = $this->policy(['allowed_origins' => ['https://first.example']]);
        $second = $this->policy(['allowed_origins' => ['https://second.example']]);
        $request = $this->request('GET', '/api/x', ['Origin' => 'https://first.example']);
        self::assertSame('https://first.example', $first->decorate($request, new Response())->header('Access-Control-Allow-Origin'));
        self::assertNull($second->decorate($request, new Response())->header('Access-Control-Allow-Origin'));
    }

    private function policy(array $options = []): CorsPolicy
    {
        return new CorsPolicy(new Repository(['api' => ['cors' => ['enabled' => true, ...$options]]]));
    }

    private function request(string $method, string $path, array $headers): Request
    {
        return new Request($method, $path, [], [], [], [], $headers);
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a denied preflight.');
        } catch (ApiError $error) {
            self::assertSame(403, $error->status());
            self::assertSame('cors_preflight_denied', $error->errorCode());
            self::assertSame('CORS preflight request is not allowed.', $error->publicMessage());
        }
    }
}
