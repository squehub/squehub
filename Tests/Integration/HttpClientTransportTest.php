<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\HttpClient\HttpClient;
use App\HttpClient\HttpClientException;
use App\HttpClient\HttpConnectionException;
use App\HttpClient\HttpConfigurationException;
use App\HttpClient\HttpDecodeException;
use App\HttpClient\HttpTimeoutException;
use App\Reliability\RetryPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** A loopback PHP server verifies actual cURL behavior without internet access. */
final class HttpClientTransportTest extends TestCase
{
    private static ?Process $server = null;
    private static string $base;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl')) self::markTestSkipped('cURL extension is required.');
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($socket === false) self::markTestSkipped('Loopback socket is unavailable.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        self::$base = 'http://127.0.0.1:' . $port;
        self::$server = new Process([PHP_BINARY, 'Tests/Fixtures/HttpClientServer.php', (string) $port],
            dirname(__DIR__, 2));
        self::$server->disableOutput();
        self::$server->start();
        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage, 0.1);
            if ($probe !== false) { fclose($probe); return; }
            if (!self::$server->isRunning()) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::stopServer();
        self::markTestSkipped('Local PHP HTTP server could not start.');
    }

    public static function tearDownAfterClass(): void { self::stopServer(); }

    private static function stopServer(): void
    {
        if (self::$server === null) return;
        $parts = parse_url(self::$base);
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $parts['port'], $number, $message, 1);
        if ($socket !== false) {
            fwrite($socket, "GET /shutdown HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            fclose($socket);
        }
        $deadline = microtime(true) + 3;
        while (self::$server->isRunning() && microtime(true) < $deadline) usleep(10000);
        if (self::$server->isRunning()) self::$server->stop(0);
        self::$server = null;
    }

    public function testMethodsJsonQueryHeadersAndBinary(): void
    {
        $client = new HttpClient();
        $response = $client->pending()->withQuery(['tag' => ['a', 'b'], 'city' => 'Lagos é'])
            ->withToken('test-token')->acceptJson()->get(self::$base . '/echo?existing=1');
        $echo = $response->json();
        self::assertSame('GET', $echo['method']);
        self::assertStringContainsString('tag=a&tag=b&city=Lagos%20%C3%A9', $echo['uri']);
        self::assertSame('Bearer test-token', $echo['headers']['authorization']);
        self::assertSame('application/json', $echo['headers']['accept']);
        $posted = $client->post(self::$base . '/echo', ['amount' => 5000])->json();
        self::assertSame('POST', $posted['method']);
        self::assertSame('{"amount":5000}', $posted['body']);
        self::assertStringContainsString('application/json', $posted['headers']['content-type']);
        foreach (['PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method) {
            $response = $client->request($method, self::$base . '/echo');
            self::assertSame(200, $response->status());
            if ($method !== 'HEAD') self::assertSame($method, $response->json()['method']);
        }
        self::assertSame("\x00\xff\x01\x80", $client->get(self::$base . '/binary')->body());
        self::assertSame(['one', 'two'], $client->get(self::$base . '/json')->headers()['x-fixture']);
        self::assertNull($client->get(self::$base . '/null-json')->json());
        $this->expectException(HttpDecodeException::class);
        $client->get(self::$base . '/invalid-json')->json();
    }

    public function testFormRawAndMultipartFileSources(): void
    {
        $client = new HttpClient();
        $form = $client->pending()->asForm()->post(self::$base . '/echo', ['name' => 'A B'])->json();
        self::assertSame('name=A%20B', $form['body']);
        self::assertSame('A B', $form['fields']['name']);
        $raw = $client->pending()->withBody('<hello/>', 'application/xml')
            ->post(self::$base . '/echo')->json();
        self::assertSame('<hello/>', $raw['body']);
        $path = tempnam(sys_get_temp_dir(), 'squehub-http-upload-');
        try {
            file_put_contents($path, 'file contents');
            $multipart = $client->pending()->multipart()->field('name', 'document')
                ->file('disk', $path, 'disk.txt', 'text/plain')
                ->fileBytes('memory', 'memory contents', 'memory.txt', 'text/plain')
                ->post(self::$base . '/multipart')->json();
            self::assertStringContainsString('name="name"', $multipart['body']);
            self::assertStringContainsString('document', $multipart['body']);
            self::assertStringContainsString('file contents', $multipart['body']);
            self::assertStringContainsString('memory contents', $multipart['body']);
            self::assertStringContainsString('filename="memory.txt"', $multipart['body']);
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'stream contents');
            rewind($stream);
            $streamed = $client->pending()->multipart()->fileStream('stream', $stream,
                'stream.txt', 'text/plain')->post(self::$base . '/multipart')->json();
            self::assertStringContainsString('stream contents', $streamed['body']);
            self::assertTrue(is_resource($stream));
            self::assertSame(strlen('stream contents'), ftell($stream));
            fclose($stream);
        } finally { @unlink($path); }
    }

    public function testRedirectStatusTimeoutAndLimits(): void
    {
        $client = new HttpClient();
        self::assertSame(['ok' => true], $client->get(self::$base . '/redirect')->json());
        $notFound = $client->get(self::$base . '/status?code=404');
        self::assertSame(404, $notFound->status());
        self::assertTrue($notFound->failed());
        foreach ([422, 429, 500, 503] as $status) {
            self::assertSame($status, $client->get(self::$base . '/status?code=' . $status)->status());
        }
        self::assertSame(302, $client->pending()->followRedirects(0)->get(self::$base . '/redirect')->status());
        $crossHost = $client->pending()->withToken('secret-token')
            ->get(self::$base . '/redirect-cross');
        self::assertSame(302, $crossHost->status());
        self::assertStringContainsString('http://localhost:', $crossHost->header('location'));
        try { $client->get(self::$base . '/redirect-file'); self::fail('Unsafe redirect followed.'); }
        catch (HttpConfigurationException) {}
        try { $client->pending()->followRedirects(2)->get(self::$base . '/redirect-loop'); self::fail('Loop followed forever.'); }
        catch (HttpClientException) {}
        try { $client->pending()->maxResponseBytes(10)->get(self::$base . '/large'); self::fail('Large response accepted.'); }
        catch (HttpClientException) {}
        try { $client->pending()->timeout(0.05)->get(self::$base . '/delay'); self::fail('Timeout ignored.'); }
        catch (HttpTimeoutException) {}
    }

    public function testRetryPolicyPerformsTwoRealLoopbackExchanges(): void
    {
        $client = new HttpClient();
        $response = $client->pending()->withRetryPolicy(new RetryPolicy(2, 0))
            ->get(self::$base . '/retry-once');
        self::assertSame(200, $response->status());
        self::assertSame('attempt-2', $response->body());
    }

    public function testDownloadsAndStreamOwnership(): void
    {
        $client = new HttpClient();
        $path = sys_get_temp_dir() . '/squehub-http-' . bin2hex(random_bytes(8));
        try {
            $response = $client->pending()->sink($path)->get(self::$base . '/large');
            self::assertTrue($response->successful());
            self::assertSame('', $response->body());
            self::assertSame(65536, filesize($path));
            try { $client->pending()->sink($path)->get(self::$base . '/binary'); self::fail('Existing sink replaced.'); }
            catch (HttpClientException) {}
            $missing = $path . '.missing';
            self::assertSame(404, $client->pending()->sink($missing)
                ->get(self::$base . '/status?code=404')->status());
            self::assertFileDoesNotExist($missing);
            $stream = fopen('php://temp', 'w+b');
            $client->pending()->sinkStream($stream)->get(self::$base . '/binary');
            self::assertTrue(is_resource($stream));
            rewind($stream);
            self::assertSame("\x00\xff\x01\x80", stream_get_contents($stream));
            fclose($stream);
        } finally { @unlink($path); }
    }
}
