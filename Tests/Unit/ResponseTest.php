<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Http\JsonResponse;
use App\Http\RedirectResponse;
use App\Http\Response;
use App\Http\ResponseEncodingException;
use App\Http\ResponseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class ResponseTest extends TestCase
{
    public function testContentStatusAndCaseInsensitiveHeaders(): void
    {
        $response = new Response('Hello', 201, ['content-type' => 'text/plain']);
        self::assertSame('Hello', $response->content());
        self::assertSame(201, $response->status());
        self::assertSame('text/plain', $response->header('Content-Type'));
        $changed = $response->withHeader('CONTENT-TYPE', 'text/html')->withContent('Changed');
        self::assertSame('text/plain', $response->header('Content-Type'));
        self::assertSame('text/html', $changed->header('content-type'));
        self::assertCount(1, $changed->headers());
        self::assertSame('Changed', $changed->content());
    }

    public function testStatusAndHeaderInjectionAreRejected(): void
    {
        foreach ([99, 600] as $status) {
            try {
                new Response('', $status);
                self::fail('Invalid status accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('status', $exception->getMessage());
            }
        }
        foreach ([['X-Test', "ok\r\nInjected: yes"], ["Bad\nName", 'ok']] as [$name, $value]) {
            try {
                new Response('', 200, [$name => $value]);
                self::fail('Header injection accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('header', $exception->getMessage());
            }
        }
    }

    public function testJsonResponseAndEncodingFailure(): void
    {
        $response = new JsonResponse(['success' => true, 'text' => '<script>'], 201);
        self::assertSame(201, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('content-type'));
        self::assertSame(['success' => true, 'text' => '<script>'], json_decode($response->content(), true));
        self::assertStringNotContainsString('<script>', $response->content());
        $this->expectException(ResponseEncodingException::class);
        new JsonResponse(['invalid' => NAN]);
    }

    public function testRedirectAndFactoryHelpers(): void
    {
        $response = new RedirectResponse('/dashboard');
        self::assertSame(302, $response->status());
        self::assertSame('/dashboard', $response->header('location'));
        self::assertSame('', $response->content());
        self::assertInstanceOf(ResponseFactory::class, response());
        self::assertSame('Hello', response('Hello')->content());
        self::assertInstanceOf(JsonResponse::class, response()->json(['ok' => true]));
        self::assertInstanceOf(RedirectResponse::class, response()->redirect('/home'));
        self::assertIsObject(redirect()); // Legacy no-argument behavior remains.
        $this->expectException(InvalidArgumentException::class);
        new RedirectResponse('/dashboard', 200);
    }

    public function testSendEmitsBodyOnceAndSuppressesHeadBody(): void
    {
        $originalStatus = http_response_code();
        $headersAvailable = !headers_sent();
        try {
            $normal = new Response('Body', 202);
            ob_start();
            $normal->send();
            $normal->send();
            self::assertSame('Body', ob_get_clean());
            self::assertSame($headersAvailable ? 202 : $originalStatus, http_response_code());

            ob_start();
            (new Response('Hidden', 200))->send(true);
            self::assertSame('', ob_get_clean());
            ob_start();
            (new Response('Hidden', 204))->send();
            self::assertSame('', ob_get_clean());
        } finally {
            if (is_int($originalStatus) && !headers_sent()) {
                http_response_code($originalStatus);
            }
        }
    }

    public function testSendAfterHeadersWereSentPreservesStatusAndBody(): void
    {
        // A separate PHP process provides a reliable headers-sent state even
        // when PHPUnit buffers its own output on the host under test.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'http_response_code(209); echo "prior";'
            . '(new \\App\\Http\\Response("Body", 202, ["X-Test" => "yes"]))->send();'
            . 'fwrite(STDERR, "STATUS=" . http_response_code());';
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('priorBody', $process->getOutput());
        self::assertSame('STATUS=209', $process->getErrorOutput());
    }

    public function testSendSetsStatusBeforeAnyOutput(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . '(new \\App\\Http\\Response("Body", 202, ["X-Test" => "yes"]))->send();'
            . 'fwrite(STDERR, "STATUS=" . http_response_code());';
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('Body', $process->getOutput());
        self::assertSame('STATUS=202', $process->getErrorOutput());
    }
}
