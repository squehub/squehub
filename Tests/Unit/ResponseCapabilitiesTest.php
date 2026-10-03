<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Http\Cookie;
use App\Http\FileResponseException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseFactory;
use App\Http\RedirectResponse;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** Exercises response body emission separately from Kernel response inspection. */
final class ResponseCapabilitiesTest extends TestCase
{
    private TemporaryProject $project;
    private ResponseFactory $factory;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->factory = new ResponseFactory();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testBinaryFactoryPreservesBytesWithoutInventingALength(): void
    {
        $bytes = "\x00\xff\x80A\r\n";
        $response = $this->factory->binary($bytes, 'application/x-fixture');

        self::assertInstanceOf(Response::class, $response);
        self::assertFalse($response->hasDeferredBody());
        self::assertSame($bytes, $response->content());
        self::assertSame('application/x-fixture', $response->header('Content-Type'));
        self::assertNull($response->header('Content-Length'));
        self::assertSame($bytes, $this->emitted($response));
        self::assertSame('', $this->emitted($response));

        $plain = $this->factory->binary("\x00\xff");
        self::assertSame('application/octet-stream', $plain->header('Content-Type'));
        self::assertSame("\x00\xff", $plain->content());
    }

    public function testTypedCookiesRemainSeparateAcrossResponseSubtypesAndClones(): void
    {
        $first = new Cookie(name: 'session_id', value: 'a b', secure: true,
            sameSite: 'Strict');
        $second = new Cookie(name: 'theme', value: 'dark', httpOnly: false,
            path: '/account');
        $base = new Response('ok');
        $response = $base->withCookie($first)->withCookie($second)
            ->withHeader('X-Probe', 'yes');

        self::assertSame([], $base->cookies());
        self::assertCount(2, $response->cookies());
        self::assertSame([$first, $second], $response->cookies());
        self::assertNull($response->header('Set-Cookie'));
        self::assertStringContainsString('session_id=a%20b', $first->headerValue());
        self::assertStringContainsString('Secure', $first->headerValue());
        self::assertStringContainsString('HttpOnly', $first->headerValue());
        self::assertStringContainsString('SameSite=Strict', $first->headerValue());
        self::assertStringContainsString('Path=/account', $second->headerValue());
        self::assertStringNotContainsString('HttpOnly', $second->headerValue());
        self::assertSame('yes', $response->header('X-Probe'));

        foreach ([new JsonResponse(['ok' => true]), new RedirectResponse('/next'),
            $this->factory->binary("\x00"),
            $this->factory->stream(static fn (): iterable => ['bytes']),
        ] as $subtype) {
            $decorated = $subtype->withCookie($first)->withCookie($second);
            self::assertSame($subtype::class, $decorated::class);
            self::assertCount(2, $decorated->cookies());
            self::assertSame([], $subtype->cookies());
        }
    }

    public function testCookieExpiresAndDeletionUseStableUtcAttributes(): void
    {
        $cookie = new Cookie(name: 'token', value: 'opaque',
            expires: new DateTimeImmutable('2030-01-02 03:04:05+03:00'),
            maxAge: 60, path: '/account', domain: 'EXAMPLE.TEST', secure: true,
            sameSite: 'None');
        $header = $cookie->headerValue();

        self::assertStringContainsString('Expires=Wed, 02 Jan 2030 00:04:05 GMT', $header);
        self::assertStringContainsString('Max-Age=60', $header);
        self::assertStringContainsString('Domain=example.test', $header);
        self::assertStringContainsString('SameSite=None', $header);
        $forget = Cookie::forget('token', '/account', 'example.test', secure: true);
        self::assertSame('', $forget->value());
        self::assertStringContainsString('Max-Age=0', $forget->headerValue());
        self::assertStringContainsString('Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            $forget->headerValue());
        self::assertStringContainsString('Path=/account', $forget->headerValue());
    }

    public function testCookieValidationRejectsHeaderInjectionAndInvalidPolicy(): void
    {
        foreach ([
            static fn (): Cookie => new Cookie('', 'value'),
            static fn (): Cookie => new Cookie('bad name', 'value'),
            static fn (): Cookie => new Cookie("bad\r\nHeader", 'value'),
            static fn (): Cookie => new Cookie('name', "value\r\nHeader"),
            static fn (): Cookie => new Cookie('name', "value\0binary"),
            static fn (): Cookie => new Cookie('name', 'value', path: "/bad\r\nPath"),
            static fn (): Cookie => new Cookie('name', 'value', domain: "bad\r\n.test"),
            static fn (): Cookie => new Cookie('name', 'value', sameSite: 'Sometimes'),
            static fn (): Cookie => new Cookie('name', 'value', sameSite: 'None'),
            static fn (): Cookie => new Cookie('__Secure-id', 'value'),
            static fn (): Cookie => new Cookie('__Host-id', 'value', secure: true,
                path: '/account'),
            static fn (): Cookie => new Cookie('__Host-id', 'value', secure: true,
                domain: 'example.test'),
            static fn (): Cookie => new Cookie('name', 'value', maxAge: -1),
        ] as $make) {
            try {
                $make();
                self::fail('An unsafe cookie was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCookieDebugOutputDoesNotExposeItsValue(): void
    {
        $secret = 'SQUEHUB_COOKIE_SECRET_DO_NOT_LEAK';
        $dump = print_r(new Cookie('token', $secret), true);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('[REDACTED]', $dump);
    }

    public function testStreamProducerRunsOnlyAtEmissionAndYieldsExactByteChunks(): void
    {
        $runs = 0;
        $response = $this->factory->stream(static function () use (&$runs): iterable {
            ++$runs;
            yield "\x00";
            yield '';
            yield "\xffpart";
        }, 202, ['Content-Type' => 'application/octet-stream']);

        self::assertSame(0, $runs);
        self::assertTrue($response->hasDeferredBody());
        self::assertSame('', $response->content());
        self::assertNull($response->header('Content-Length'));
        $decorated = $response->withHeader('X-Stream', 'yes');
        self::assertTrue($decorated->hasDeferredBody());
        self::assertSame('yes', $decorated->header('X-Stream'));
        self::assertSame(0, $runs);
        self::assertSame("\x00\xffpart", $this->emitted($decorated));
        self::assertSame(1, $runs);
        self::assertSame('', $this->emitted($decorated));
        self::assertSame(1, $runs);
    }

    public function testEmptyStreamAndHeadNeverPublishBodyBytes(): void
    {
        $empty = $this->factory->stream(static function (): iterable {
            if (false) yield 'unreachable';
        });
        self::assertSame('', $this->emitted($empty));

        $runs = 0;
        $head = $this->factory->stream(static function () use (&$runs): iterable {
            ++$runs;
            yield 'secret body';
        });
        self::assertSame('', $this->emitted($head, true));
        self::assertSame(0, $runs);

        foreach ([204, 205, 304] as $status) {
            $withoutBody = $this->factory->stream(static function () use (&$runs): iterable {
                ++$runs;
                yield 'should not run';
            }, $status);
            self::assertSame('', $this->emitted($withoutBody));
            self::assertSame(0, $runs);
        }
    }

    public function testStreamRejectsNonStringChunksAndDoesNotHideProducerFailure(): void
    {
        $invalid = $this->factory->stream(static fn (): iterable => ["before", 42]);
        [$partial, $failure] = $this->emittedFailure($invalid);
        self::assertSame('before', $partial);
        self::assertNotNull($failure);
        self::assertInstanceOf(\UnexpectedValueException::class, $failure);

        $before = $this->factory->stream(static function (): iterable {
            throw new RuntimeException('before first chunk');
            yield 'unreachable';
        });
        [$body, $error] = $this->emittedFailure($before);
        self::assertSame('', $body);
        self::assertInstanceOf(RuntimeException::class, $error);

        $after = $this->factory->stream(static function (): iterable {
            yield 'already sent';
            throw new RuntimeException('after first chunk');
        });
        [$body, $error] = $this->emittedFailure($after);
        self::assertSame('already sent', $body);
        self::assertInstanceOf(RuntimeException::class, $error);
    }

    public function testDownloadUsesSafeClientFilenameAndStreamsTheFile(): void
    {
        $bytes = str_repeat("\x00\xff\x80Q", 16384);
        $path = $this->fixture('private/Report.bin', $bytes);
        $response = $this->factory->download($path);

        self::assertTrue($response->hasDeferredBody());
        self::assertSame('', $response->content());
        self::assertSame(200, $response->status());
        self::assertSame((string) strlen($bytes), $response->header('Content-Length'));
        self::assertSame('application/octet-stream', $response->header('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->header('Content-Disposition'));
        self::assertStringContainsString('filename="Report.bin"',
            (string) $response->header('Content-Disposition'));
        self::assertStringNotContainsString($this->project->path(),
            (string) $response->header('Content-Disposition'));
        self::assertSame($bytes, $this->emitted($response));
    }

    public function testInlineFileAndZeroByteFileKeepCorrectMetadata(): void
    {
        $path = $this->fixture('static/empty.svg', '');
        $inline = $this->factory->file($path, 'chart.svg', 'image/svg+xml');

        self::assertSame(200, $inline->status());
        self::assertSame('image/svg+xml', $inline->header('Content-Type'));
        self::assertSame('0', $inline->header('Content-Length'));
        self::assertStringContainsString('inline', (string) $inline->header('Content-Disposition'));
        self::assertStringContainsString('filename="chart.svg"',
            (string) $inline->header('Content-Disposition'));
        self::assertSame('', $this->emitted($inline));

        $head = $this->factory->download($this->fixture('static/bytes.bin', 'BODY'));
        self::assertSame('4', $head->header('Content-Length'));
        self::assertSame('', $this->emitted($head, true));
    }

    public function testClientFilenameQuotesAndSemicolonRemainInsideQuotedParameter(): void
    {
        $path = $this->fixture('private/report.bin', 'data');
        $response = $this->factory->download($path, 'report "Q"; draft.txt');

        self::assertSame('attachment; filename="report \\"Q\\"; draft.txt"',
            $response->header('Content-Disposition'));
        self::assertSame('data', $this->emitted($response));
    }

    public function testFileFramingHeadersCannotBeChangedAfterConstruction(): void
    {
        $response = $this->factory->download($this->fixture('private/data.bin', '012345'));
        foreach (['Content-Length', 'Content-Range', 'Content-Disposition',
            'Accept-Ranges', 'Transfer-Encoding'] as $name) {
            try {
                $response->withHeader($name, 'invalid');
                self::fail('A managed file header was replaced.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                $response->withoutHeader($name);
                self::fail('A managed file header was removed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWithStatusCloneRetainsDeferredBodyAndCookies(): void
    {
        $runs = 0;
        $cookie = new Cookie('checkpoint', 'one');
        $original = $this->factory->stream(static function () use (&$runs): iterable {
            ++$runs;
            yield 'still deferred';
        })->withCookie($cookie);
        $changed = $original->withStatus(202)->withHeader('X-Stage', 'later');

        self::assertSame(200, $original->status());
        self::assertSame(202, $changed->status());
        self::assertTrue($changed->hasDeferredBody());
        self::assertSame([$cookie], $changed->cookies());
        self::assertSame(0, $runs);
        self::assertSame('still deferred', $this->emitted($changed));
        self::assertSame(1, $runs);
    }

    public function testFileReplacementBeforeEmissionFailsBeforePublishingBytes(): void
    {
        foreach (['nonempty' => 'body', 'zero' => ''] as $name => $bytes) {
            $path = $this->fixture('private/' . $name . '.bin', $bytes);
            $response = $this->factory->download($path);
            unlink($path);
            [$body, $failure] = $this->emittedFailure($response);

            self::assertSame('', $body);
            self::assertInstanceOf(FileResponseException::class, $failure);
            self::assertStringNotContainsString($path, $failure->getMessage());
        }
    }

    public function testUnicodeFilenameIsEncodedWithoutExposingPhysicalPath(): void
    {
        $path = $this->fixture('private/source.bin', 'data');
        $response = $this->factory->download($path, 'résumé 2026.pdf');
        $disposition = (string) $response->header('Content-Disposition');

        self::assertStringContainsString("filename*=UTF-8''", $disposition);
        self::assertStringContainsString('r%C3%A9sum%C3%A9%202026.pdf', $disposition);
        self::assertStringNotContainsString($this->project->path(), $disposition);
        self::assertStringNotContainsString("\r", $disposition);
        self::assertStringNotContainsString("\n", $disposition);
    }

    public function testUnsafeClientFilenamesNeverCreateResponseSplitting(): void
    {
        $path = $this->fixture('private/source.bin', 'data');
        foreach (["bad\r\nInjected: yes.txt", "bad\0name.txt", 'bad/name.txt', 'bad\\name.txt'] as $name) {
            try {
                $response = $this->factory->download($path, $name);
                $value = (string) $response->header('Content-Disposition');
                self::assertStringNotContainsString("\r", $value);
                self::assertStringNotContainsString("\n", $value);
                self::assertStringNotContainsString("\0", $value);
                self::assertStringNotContainsString($this->project->path(), $value);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMissingOrDirectorySourceFailsWithoutLeakingPhysicalPath(): void
    {
        foreach ([$this->project->path('secret-missing.txt'), $this->project->path()] as $path) {
            try {
                $this->factory->download($path);
                self::fail('An unavailable download source was accepted.');
            } catch (Throwable $error) {
                self::assertStringNotContainsString($path, $error->getMessage());
            }
        }
    }

    public function testSingleByteRangesReturnExactSlicesAndHeaders(): void
    {
        $path = $this->fixture('ranges/sequence.txt', '0123456789ABCDEF');
        foreach ([
            'bytes=0-3' => ['0123', 'bytes 0-3/16'],
            'bytes=10-' => ['ABCDEF', 'bytes 10-15/16'],
            'bytes=-4' => ['CDEF', 'bytes 12-15/16'],
            'bytes=5-5' => ['5', 'bytes 5-5/16'],
            'bytes=0-99' => ['0123456789ABCDEF', 'bytes 0-15/16'],
        ] as $range => [$expected, $contentRange]) {
            $response = $this->factory->download($path, request: $this->rangeRequest($range));
            self::assertSame(206, $response->status(), $range);
            self::assertSame('bytes', $response->header('Accept-Ranges'));
            self::assertSame($contentRange, $response->header('Content-Range'));
            self::assertSame((string) strlen($expected), $response->header('Content-Length'));
            self::assertSame($expected, $this->emitted($response));
        }
    }

    public function testInvalidRepeatedAndUnsatisfiableRangesReturn416WithoutBody(): void
    {
        $path = $this->fixture('ranges/sequence.txt', '0123456789ABCDEF');
        foreach (['bytes=16-', 'bytes=9-3', 'bytes=-0', 'bytes=0-1,3-4',
            'bytes=999999999999999999999999999999-', 'items=0-2'] as $range) {
            $response = $this->factory->download($path, request: $this->rangeRequest($range));
            self::assertSame(416, $response->status(), $range);
            self::assertSame('bytes */16', $response->header('Content-Range'));
            self::assertSame('0', $response->header('Content-Length'));
            self::assertSame('', $this->emitted($response));
        }

        $repeated = new Request('GET', '/file', [], [], [], [],
            ['Range' => 'bytes=0-1', 'range' => 'bytes=2-3']);
        $response = $this->factory->download($path, request: $repeated);
        self::assertSame(416, $response->status());
        self::assertSame('', $this->emitted($response));

        $zero = $this->factory->download($this->fixture('ranges/empty.txt', ''),
            request: $this->rangeRequest('bytes=0-0'));
        self::assertSame(416, $zero->status());
        self::assertSame('bytes */0', $zero->header('Content-Range'));
        self::assertSame('', $this->emitted($zero));
    }

    public function testConditionalIfRangeIsIgnoredWithoutPartialDelivery(): void
    {
        $path = $this->fixture('ranges/sequence.txt', '0123456789ABCDEF');
        $request = new Request('GET', '/file', [], [], [], [], [
            'Range' => 'bytes=2-4', 'If-Range' => '"unsupported-validator"',
        ]);
        $response = $this->factory->download($path, request: $request);

        self::assertSame(200, $response->status());
        self::assertNull($response->header('Content-Range'));
        self::assertSame('16', $response->header('Content-Length'));
        self::assertSame('0123456789ABCDEF', $this->emitted($response));
    }

    private function fixture(string $relative, string $bytes): string
    {
        $this->project->write($relative, $bytes);
        return $this->project->path($relative);
    }

    private function rangeRequest(string $range): Request
    {
        return new Request('GET', '/file', [], [], [], [], ['Range' => $range]);
    }

    /** Capture only bounded test fixtures; live application responses stay deferred. */
    private function emitted(Response $response, bool $head = false): string
    {
        ob_start();
        try {
            $response->send($head);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** A partial stream may already have emitted bytes before its producer fails. */
    private function emittedFailure(Response $response): array
    {
        ob_start();
        try {
            try {
                $response->send();
                return [(string) ob_get_contents(), null];
            } catch (Throwable $failure) {
                return [(string) ob_get_contents(), $failure];
            }
        } finally {
            ob_end_clean();
        }
    }
}
