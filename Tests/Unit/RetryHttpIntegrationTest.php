<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\HttpClient\HttpClient;
use App\HttpClient\HttpConfigurationException;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpTimeoutException;
use App\Reliability\RetryPolicy;
use PHPUnit\Framework\TestCase;

/** HTTP retries reuse one immutable request and require unsafe replay consent. */
final class RetryHttpIntegrationTest extends TestCase
{
    public function testSafeRequestRetriesTransientStatusAndConnectionFailure(): void
    {
        $client = new HttpClient();
        $client->fake(['GET https://example.test/report' => [
            new HttpTimeoutException('External HTTP request timed out.'),
            new HttpResponse(503, '', ['retry-after' => ['0']]),
            new HttpResponse(200, 'ok'),
        ]]);
        $response = $client->pending()->withHeaders(['X-Example' => 'stable'])
            ->withRetryPolicy(new RetryPolicy(3, 0))
            ->get('https://example.test/report');
        self::assertSame(200, $response->status());
        self::assertCount(3, $client->captured());
        foreach ($client->captured() as $request) {
            self::assertSame('GET', $request->method);
            self::assertSame('stable', $request->headers['x-example']);
            self::assertSame('https://example.test/report', $request->url);
        }
    }

    public function testUnsafeMethodNeedsExplicitReplayAuthorization(): void
    {
        $client = new HttpClient();
        $client->fake(['POST https://example.test/pay' => [
            new HttpResponse(503), new HttpResponse(200),
        ]]);
        $policy = new RetryPolicy(2, 0);
        self::assertSame(503, $client->pending()->withRetryPolicy($policy)
            ->post('https://example.test/pay', ['amount' => 10])->status());
        self::assertCount(1, $client->captured());

        $client->fake(['POST https://example.test/pay' => [
            new HttpResponse(503), new HttpResponse(200),
        ]]);
        self::assertSame(200, $client->pending()->withRetryPolicy($policy, allowUnsafe: true)
            ->post('https://example.test/pay', ['amount' => 10])->status());
        self::assertCount(2, $client->captured());
        self::assertSame($client->captured()[0]->body, $client->captured()[1]->body);
    }

    public function testExistingExplicitRetryStillPermitsUnsafeMethod(): void
    {
        $client = new HttpClient();
        $client->fake(['POST https://example.test/action' => [
            new HttpResponse(503), new HttpResponse(204),
        ]]);
        self::assertSame(204, $client->pending()->retry(2, 0)
            ->post('https://example.test/action', 'same bytes')->status());
        self::assertCount(2, $client->captured());
        self::assertSame('same bytes', $client->captured()[0]->body);
        self::assertSame('same bytes', $client->captured()[1]->body);
    }

    public function testLowLevelSendDoesNotInferUnsafeConsentFromPolicy(): void
    {
        $client = new HttpClient();
        $client->fake(['POST https://example.test/action' => new HttpResponse(204)]);
        $client->post('https://example.test/action', 'same bytes');
        $request = $client->captured()[0];
        $policy = new RetryPolicy(2, 0);

        $client->fake(['POST https://example.test/action' => [
            new HttpResponse(503), new HttpResponse(204),
        ]]);
        self::assertSame(503, $client->send($request, 2, 0, 5, $policy)->status());
        self::assertCount(1, $client->captured());

        $client->fake(['POST https://example.test/action' => [
            new HttpResponse(503), new HttpResponse(204),
        ]]);
        self::assertSame(204, $client->send($request, 2, 0, 5, $policy, true)->status());
        self::assertCount(2, $client->captured());
    }

    public function testElapsedLimitPreventsRetryBeforeDelay(): void
    {
        $client = new HttpClient();
        $client->fake(['*' => [new HttpResponse(503), new HttpResponse(200)]]);
        $policy = new RetryPolicy(3, 100, maxElapsedMs: 50);
        self::assertSame(503, $client->pending()->withRetryPolicy($policy)
            ->get('https://example.test/limited')->status());
        self::assertCount(1, $client->captured());
    }

    public function testNonRewindableStreamStillRejectsMultiAttemptPolicy(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        try {
            $client = new HttpClient();
            $request = $client->pending()->sinkStream($stream)
                ->withRetryPolicy(new RetryPolicy(2, 0));
            $this->expectException(HttpConfigurationException::class);
            $request->get('https://example.test/download');
        } finally {
            fclose($stream);
        }
    }
}
