<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Foundation\Application;
use App\HttpClient\HttpClient;
use App\HttpClient\Http as CanonicalHttp;
use App\HttpClient\HttpServiceProvider;
use App\Plugins\Http;
use App\Plugins\HttpResponse;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Plugins and canonical access share the current Application-owned client. */
final class HttpClientApplicationTest extends TestCase
{
    public function testPluginsGatewayAndApplicationIsolation(): void
    {
        $firstProject = new TemporaryProject();
        $secondProject = new TemporaryProject();
        try {
            $first = new Application($firstProject->path());
            $first->register(HttpServiceProvider::class);
            $first->bootstrap();
            $firstClient = $first->container()->make(HttpClient::class);
            self::assertSame($firstClient, Http::client());
            self::assertSame($firstClient, CanonicalHttp::client());
            Http::fake(['*' => Http::response(['application' => 'first'])]);
            self::assertSame('first', Http::get('https://service.example.test/')->json()['application']);
            self::assertInstanceOf(HttpResponse::class, Http::response(['ok' => true]));

            $second = new Application($secondProject->path());
            $second->register(HttpServiceProvider::class);
            $second->bootstrap();
            self::assertNotSame($firstClient, Http::client());
            Http::fake(['*' => Http::response(['application' => 'second'])]);
            self::assertSame('second', Http::get('https://service.example.test/')->json()['application']);
            self::assertSame('first', $firstClient->get('https://service.example.test/')->json()['application']);
            self::assertCount(2, $firstClient->captured());
            self::assertCount(1, Http::captured());
            Http::resetFake();
            self::assertSame([], Http::captured());
            self::assertCount(2, $firstClient->captured());
        } finally {
            CanonicalHttp::setResolver(null);
            $firstProject->remove();
            $secondProject->remove();
        }
    }
}
