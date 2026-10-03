<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\HttpClient\CurlTransport;
use App\HttpClient\HttpClient;
use App\HttpClient\HttpConfigurationException;
use App\HttpClient\HttpResponse;
use App\HttpClient\HttpTransport;
use App\HttpClient\OutgoingRequest;
use App\Mail\Mailer;
use App\Mail\MailMessage;
use App\Mail\MailProviderException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Real cURL exchange of provider payloads with a test-only local receiver.
 * The transport adapter alone rewrites the fixed HTTPS origin; production Mail
 * configuration and provider endpoint selection are left unchanged.
 */
final class ApiMailLoopbackTest extends TestCase
{
    public const RESEND_URL = 'https://api.resend.com/emails';
    public const POSTMARK_URL = 'https://api.postmarkapp.com/email';
    private const TOKEN = 'SQUEHUB_MAIL_WIRE_TOKEN';

    public function testResendSendsItsJsonAndAuthorizationOverRealHttp(): void
    {
        $this->withReceiver('resend-success', function (string $base, string $capturePath): void {
            $transport = $this->transport($base);
            (new Mailer($this->config('resend'), httpClient: new HttpClient([], $transport)))
                ->send($this->message());

            self::assertCount(1, $transport->originalRequests);
            $original = $transport->originalRequests[0];
            self::assertSame(self::RESEND_URL, $original->url);
            self::assertTrue($original->verifyPeer);
            self::assertLessThanOrEqual(5.0, $original->connectTimeout);
            $capture = $this->capture($capturePath);
            self::assertStringStartsWith('POST /resend HTTP/1.', $capture['request_line']);
            self::assertSame('Bearer ' . self::TOKEN, $capture['headers']['authorization']);
            self::assertStringStartsWith('application/json', $capture['headers']['content-type']);
            $body = json_decode($capture['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('sender@example.test', $body['from']);
            self::assertSame(['recipient@example.test'], $body['to']);
            self::assertSame('Wire subject', $body['subject']);
            self::assertSame('Wire body', $body['text']);
        });
    }

    public function testPostmarkSendsItsJsonAndServerTokenOverRealHttp(): void
    {
        $this->withReceiver('postmark-success', function (string $base, string $capturePath): void {
            $transport = $this->transport($base);
            (new Mailer($this->config('postmark'), httpClient: new HttpClient([], $transport)))
                ->send($this->message());

            self::assertCount(1, $transport->originalRequests);
            self::assertSame(self::POSTMARK_URL, $transport->originalRequests[0]->url);
            $capture = $this->capture($capturePath);
            self::assertStringStartsWith('POST /postmark HTTP/1.', $capture['request_line']);
            self::assertSame(self::TOKEN, $capture['headers']['x-postmark-server-token']);
            self::assertStringStartsWith('application/json', $capture['headers']['content-type']);
            $body = json_decode($capture['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('sender@example.test', $body['From']);
            self::assertSame('recipient@example.test', $body['To']);
            self::assertSame('Wire subject', $body['Subject']);
            self::assertSame('Wire body', $body['TextBody']);
        });
    }

    public function testRejectedProviderBodyCannotEnterMailException(): void
    {
        $this->withReceiver('resend-reject', function (string $base, string $capturePath): void {
            $transport = $this->transport($base);
            try {
                (new Mailer($this->config('resend'), httpClient: new HttpClient([], $transport)))
                    ->send($this->message());
                self::fail('Expected a provider rejection.');
            } catch (MailProviderException $failure) {
                self::assertSame('validation', $failure->category());
                self::assertStringNotContainsString('SQUEHUB_MAIL_WIRE_SECRET', $failure->getMessage());
                self::assertStringNotContainsString('recipient@example.test', $failure->getMessage());
                self::assertStringNotContainsString(self::TOKEN, $failure->getMessage());
                self::assertNull($failure->getPrevious());
            }
            self::assertCount(1, $transport->originalRequests);
            self::assertStringStartsWith('POST /resend HTTP/1.',
                $this->capture($capturePath)['request_line']);
        });
    }

    /** @return array<string,mixed> */
    private function config(string $driver): array
    {
        return [
            'default' => $driver,
            'from' => ['address' => 'sender@example.test'],
            'transports' => [$driver => [
                'driver' => $driver, 'api_key' => self::TOKEN, 'timeout' => 3,
            ]],
        ];
    }

    private function message(): MailMessage
    {
        return (new MailMessage())->to('recipient@example.test')
            ->subject('Wire subject')->text('Wire body');
    }

    /**
     * Preserve the provider's fixed URL and all outgoing headers/body/options.
     * Only this test-owned transport exchanges them with the loopback fixture.
     */
    private function transport(string $base): ApiMailLoopbackTransport
    {
        return new ApiMailLoopbackTransport($base);
    }

    /** @return array{request_line:string,headers:array<string,string>,body:string} */
    private function capture(string $path): array
    {
        self::assertFileExists($path);
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param callable(string,string):void $run */
    private function withReceiver(string $mode, callable $run): void
    {
        if (!extension_loaded('curl')) self::markTestSkipped('cURL extension is required.');
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        if ($socket === false) self::markTestSkipped('Loopback socket is unavailable.');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $capturePath = tempnam(sys_get_temp_dir(), 'squehub-mail-wire-');
        if ($capturePath === false) self::markTestSkipped('Temporary capture file is unavailable.');
        $server = new Process([PHP_BINARY, 'Tests/Fixtures/ApiMailLoopbackServer.php',
            (string) $port, $capturePath, $mode], dirname(__DIR__, 2));
        $server->disableOutput();
        try {
            $server->start();
            $deadline = microtime(true) + 5;
            do {
                $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $number, $message, 0.1);
                if ($probe !== false) { fclose($probe); break; }
                if (!$server->isRunning()) break;
                usleep(20000);
            } while (microtime(true) < $deadline);
            if ($probe === false) self::markTestSkipped('Local Mail provider receiver could not start.');
            $run('http://127.0.0.1:' . $port, $capturePath);
        } finally {
            if ($server->isRunning()) $server->stop(0);
            @unlink($capturePath);
        }
    }
}

/** The only endpoint substitution allowed by the loopback integration test. */
final class ApiMailLoopbackTransport implements HttpTransport
{
    /** @var list<OutgoingRequest> */
    public array $originalRequests = [];

    public function __construct(private readonly string $base) {}

    public function send(OutgoingRequest $request): HttpResponse
    {
        $path = match ($request->url) {
            ApiMailLoopbackTest::RESEND_URL => '/resend',
            ApiMailLoopbackTest::POSTMARK_URL => '/postmark',
            default => throw new HttpConfigurationException('Unexpected Mail provider endpoint.'),
        };
        $this->originalRequests[] = $request;
        return (new CurlTransport())->send(new OutgoingRequest(
            $request->method, $this->base . $path, $request->headers, $request->body,
            $request->parts, $request->connectTimeout, $request->timeout,
            $request->verifyPeer, $request->caBundle, $request->maxBodyBytes,
            $request->maxRequestBytes, $request->sinkPath, $request->sinkStream,
        ));
    }
}
