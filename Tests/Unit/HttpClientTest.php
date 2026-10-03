<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpConfigurationException;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpDecodeException;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpStatusException;
use App\HttpClient\HttpTimeoutException;
use PHPUnit\Framework\TestCase;

/** Fakes exercise the public request contract without making a network call. */
final class HttpClientTest extends TestCase
{
    private HttpClient $client;
    private Diagnostics $diagnostics;

    protected function setUp(): void
    {
        $this->diagnostics = new Diagnostics(new Repository([]));
        $this->diagnostics->begin(new Request('GET', '/'));
        $this->client = new HttpClient([], null, $this->diagnostics);
    }

    public function testMethodsQueryEncodingAndCloneIsolation(): void
    {
        $url = 'https://api.example.test/items?existing=1';
        $this->client->fake(['*' => new HttpResponse(200, 'ok')]);
        $base = $this->client->pending()->withHeaders(['X-Client' => 'first']);
        $base->withQuery(['tag' => ['one', 'two'], 'city' => 'Lagos é'])->get($url);
        $base->get($url);
        $base->post($url, ['amount' => 5000]);
        $base->put($url, ['amount' => 6000]);
        $base->patch($url, ['amount' => 7000]);
        $base->delete($url);
        $base->head($url);
        $base->request('OPTIONS', $url);
        $sent = $this->client->captured();
        self::assertCount(8, $sent);
        self::assertSame('https://api.example.test/items?existing=1&tag=one&tag=two&city=Lagos%20%C3%A9', $sent[0]->url);
        self::assertSame($url, $sent[1]->url);
        self::assertSame(['GET', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            array_map(static fn ($request) => $request->method, $sent));
        self::assertSame('application/json', $sent[2]->headers['content-type']);
        self::assertSame('{"amount":5000}', $sent[2]->body);
        self::assertSame('first', $sent[7]->headers['x-client']);
    }

    public function testAuthenticationBodiesAndHeaderValidation(): void
    {
        $this->client->fake(['*' => new HttpResponse(204)]);
        $this->client->pending()->withToken('secret-token')->acceptJson()
            ->asForm()->post('https://example.test/login', ['name' => 'A B']);
        $this->client->pending()->withBasicAuth('user', 'pass')
            ->withBody('<hello/>', 'application/xml')->post('https://example.test/xml');
        $this->client->pending()->multipart()->field('name', 'document')
            ->fileBytes('upload', 'abc', 'a.txt', 'text/plain')->post('https://example.test/upload');
        $sent = $this->client->captured();
        self::assertSame('Bearer secret-token', $sent[0]->headers['authorization']);
        self::assertSame('application/json', $sent[0]->headers['accept']);
        self::assertSame('name=A%20B', $sent[0]->body);
        self::assertSame('Basic ' . base64_encode('user:pass'), $sent[1]->headers['authorization']);
        self::assertSame('<hello/>', $sent[1]->body);
        self::assertCount(2, $sent[2]->parts);
        self::assertArrayNotHasKey('content-type', $sent[2]->headers);
        foreach (['X-Unsafe' => "line\r\nInjected: yes", "X\nName" => 'safe'] as $name => $value) {
            try { $this->client->pending()->withHeaders([$name => $value]); self::fail('Unsafe header accepted.'); }
            catch (HttpConfigurationException) {}
        }
    }

    public function testSameOriginRedirectFollowsButCrossOriginStopsBeforeForwardingCredentials(): void
    {
        foreach ([null, 'application-choice'] as $explicit) {
            $this->client->fake([
                'GET https://one.example.test/start' => new HttpResponse(302, '',
                    ['Location' => ['/same']]),
                'GET https://one.example.test/same' => new HttpResponse(302, '',
                    ['Location' => ['https://two.example.test/final']]),
                'GET https://two.example.test/final' => new HttpResponse(200, 'ok'),
            ]);
            $pending = $this->client->pending()->withToken('credential')
                ->withHeaders(['X-API-Key' => 'custom-credential']);
            if ($explicit !== null) {
                $pending = $pending->withHeaders(['X-Correlation-ID' => $explicit]);
            }
            $response = $pending->get('https://one.example.test/start');
            self::assertSame(302, $response->status());
            self::assertSame('https://two.example.test/final', $response->header('location'));
            $captured = $this->client->captured();
            self::assertCount(2, $captured);
            $expected = $explicit ?? $this->diagnostics->correlation()->current();
            self::assertSame($expected, $captured[0]->headers['x-correlation-id']);
            self::assertSame($expected, $captured[1]->headers['x-correlation-id']);
            self::assertSame('credential', substr($captured[1]->headers['authorization'], 7));
            self::assertSame('custom-credential', $captured[1]->headers['x-api-key']);
        }
    }

    public function testCrossOriginRedirectDoesNotForwardPostBodyOrCustomHeader(): void
    {
        foreach ([307, 308] as $status) {
            $this->client->fake([
                'POST https://one.example.test/start' => new HttpResponse($status, '',
                    ['Location' => ['https://two.example.test/final']]),
                'POST https://two.example.test/final' => new HttpResponse(200, 'leaked'),
            ]);
            $response = $this->client->pending()->withHeaders(['X-API-Key' => 'custom-credential'])
                ->withBody('body-credential', 'text/plain')
                ->post('https://one.example.test/start');

            self::assertSame($status, $response->status());
            self::assertSame('https://two.example.test/final', $response->header('location'));
            self::assertCount(1, $this->client->captured());
            self::assertSame('body-credential', $this->client->captured()[0]->body);
        }
    }

    public function testUppercaseHttpsSchemeCannotDisguiseDifferentPortAsSameOrigin(): void
    {
        $this->client->fake([
            'GET HTTPS://one.example.test/start' => new HttpResponse(302, '',
                ['Location' => ['https://one.example.test:80/final']]),
            'GET https://one.example.test:80/final' => new HttpResponse(200, 'leaked'),
        ]);

        $response = $this->client->pending()->withHeaders(['X-API-Key' => 'custom-credential'])
            ->get('HTTPS://one.example.test/start');

        self::assertSame(302, $response->status());
        self::assertCount(1, $this->client->captured());
    }

    public function testResponseSemanticsAndJsonFailures(): void
    {
        $response = new HttpResponse(422, '{"message":"invalid"}',
            ['Content-Type' => ['application/json'], 'Set-Cookie' => ['a=1', 'b=2']]);
        self::assertFalse($response->successful());
        self::assertTrue($response->failed());
        self::assertTrue($response->clientError());
        self::assertSame(422, $response->status());
        self::assertSame(['a=1', 'b=2'], $response->headers()['set-cookie']);
        self::assertSame('application/json', $response->header('CONTENT-TYPE'));
        self::assertSame(['message' => 'invalid'], $response->json());
        self::assertNull((new HttpResponse(200, 'null'))->json());
        self::assertSame(12, (new HttpResponse(200, '12'))->json());
        self::assertSame([1, 2], (new HttpResponse(200, '[1,2]'))->json());
        foreach (['', '{broken'] as $body) {
            try { (new HttpResponse(200, $body))->json(); self::fail('Bad JSON decoded.'); }
            catch (HttpDecodeException) {}
        }
        $this->expectException(HttpStatusException::class);
        $response->throw();
    }

    public function testRetryFailuresAndAggregatePrivateDiagnostics(): void
    {
        $secret = 'SQUEHUB_HTTP_SECRET_DO_NOT_LEAK';
        $this->client->fake(['GET https://api.example.test/' . $secret => [
            new HttpTimeoutException('External HTTP request timed out.'),
            new HttpResponse(503, $secret),
            new HttpResponse(200, '{"ok":true}'),
        ]]);
        $result = $this->client->pending()->withToken($secret)->retry(3, 0)
            ->get('https://api.example.test/' . $secret);
        self::assertTrue($result->successful());
        self::assertSame(['ok' => true], $result->json());
        self::assertCount(3, $this->client->captured());
        $metrics = $this->diagnostics->snapshot()['http_client'];
        self::assertSame(1, $metrics['requests']);
        self::assertSame(3, $metrics['attempts']);
        self::assertSame(2, $metrics['retried']);
        self::assertSame(1, $metrics['successful']);
        self::assertSame(0, $metrics['failed']);
        self::assertStringNotContainsString($secret, json_encode($this->diagnostics->snapshot()));
        $this->client->resetFake();
        self::assertSame([], $this->client->captured());
    }

    public function testUnmatchedFakeAndInvalidUrlNeverReachNetwork(): void
    {
        $this->client->fake(['GET https://allowed.test/*' => new HttpResponse(200)]);
        try { $this->client->get('https://unmatched.test/'); self::fail('Unmatched fake reached network.'); }
        catch (HttpConfigurationException) {}
        foreach (['file:///etc/passwd', 'ftp://example.test/', 'https://user:password@example.test/'] as $url) {
            try { $this->client->get($url); self::fail('Invalid URL accepted.'); }
            catch (HttpConfigurationException $exception) {
                self::assertStringNotContainsString('password', $exception->getMessage());
            }
        }
    }

    public function testFakeConnectionFailureAndApplicationLikeIsolation(): void
    {
        $other = new HttpClient();
        $this->client->fake(['*' => new HttpConnectionException('External HTTP request failed to connect.')]);
        $other->fake(['*' => new HttpResponse(200, 'other')]);
        self::assertSame('other', $other->get('https://example.test/')->body());
        $this->expectException(HttpConnectionException::class);
        $this->client->get('https://example.test/');
    }

    public function testDiagnosticsResetAndBodyPrivacy(): void
    {
        $secret = 'SQUEHUB_HTTP_SECRET_DO_NOT_LEAK';
        $this->client->fake(['*' => new HttpResponse(500, $secret)]);
        self::assertSame(500, $this->client->pending()->withBody($secret, 'text/plain')
            ->post('https://api.example.test/?secret=' . $secret)->status());
        $snapshot = $this->diagnostics->snapshot();
        self::assertSame(1, $snapshot['http_client']['requests']);
        self::assertSame(1, $snapshot['http_client']['failed']);
        self::assertStringNotContainsString($secret, json_encode($snapshot));
        $this->diagnostics->begin(new Request('GET', '/next'));
        self::assertSame(0, $this->diagnostics->snapshot()['http_client']['requests']);
    }

    public function testRetryStopsAtConfiguredAttemptsAndLeavesOtherStatusesAlone(): void
    {
        $this->client->fake(['*' => new HttpResponse(404, 'missing')]);
        self::assertSame(404, $this->client->pending()->retry(3, 0)
            ->get('https://api.example.test/missing')->status());
        self::assertCount(1, $this->client->captured());
        $this->client->fake(['*' => [new HttpTimeoutException('External HTTP request timed out.'),
            new HttpTimeoutException('External HTTP request timed out.')]]);
        try {
            $this->client->pending()->retry(2, 0)->get('https://api.example.test/timeout');
            self::fail('Exhausted retry did not raise a timeout.');
        } catch (HttpTimeoutException) {}
        self::assertCount(2, $this->client->captured());
    }

    public function testTlsVerificationIsDefaultAndOptOutIsExplicit(): void
    {
        $this->client->fake(['*' => new HttpResponse(200)]);
        $this->client->get('https://api.example.test/');
        $this->client->pending()->withoutVerifying()->get('https://api.example.test/');
        self::assertTrue($this->client->captured()[0]->verifyPeer);
        self::assertFalse($this->client->captured()[1]->verifyPeer);
    }
}
