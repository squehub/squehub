<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

/** Browser form overrides are captured before routing without changing other request sources. */
final class RequestMethodOverrideTest extends TestCase
{
    public function testOnlyPostFormBodiesCanSelectTheThreeUnsafeMethods(): void
    {
        foreach (['put' => 'PUT', 'PaTcH' => 'PATCH', 'DELETE' => 'DELETE'] as $submitted => $expected) {
            foreach (['application/x-www-form-urlencoded; charset=UTF-8',
                'multipart/form-data; boundary=example', null] as $contentType) {
                $headers = $contentType === null ? [] : ['Content-Type' => $contentType];
                $request = new Request('post', '/resource', form: ['_method' => $submitted], headers: $headers);
                self::assertSame($expected, $request->method());
                self::assertSame('POST', $request->transportMethod());
                self::assertSame($submitted, $request->input('_method'));
            }
        }
    }

    public function testInvalidOverridesKeepPostAndNeverBecomeArbitraryRouteMethods(): void
    {
        foreach (['', 'GET', 'POST', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT',
            ' DELETE ', "DELETE\n", str_repeat('D', 10000), ['DELETE'], 123, null] as $submitted) {
            $request = new Request('POST', '/resource', form: ['_method' => $submitted]);
            self::assertSame('POST', $request->method());
            self::assertSame('POST', $request->transportMethod());
            self::assertNull(Request::normalizeFormMethod($submitted));
        }
    }

    public function testNonPostQueryCookieAndHeaderValuesCannotOverride(): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'] as $transport) {
            $request = new Request($transport, '/resource', form: ['_method' => 'DELETE']);
            self::assertSame($transport, $request->method());
            self::assertSame($transport, $request->transportMethod());
        }
        $request = new Request('POST', '/resource', ['_method' => 'DELETE'], [],
            ['_method' => 'PATCH'], [], ['X-HTTP-Method-Override' => 'PUT']);
        self::assertSame('POST', $request->method());
    }

    public function testJsonOtherContentTypesAndAmbiguousHeadersDoNotOverride(): void
    {
        foreach (['application/json', 'application/vnd.squehub+json', 'text/plain', 'application/octet-stream'] as $type) {
            $request = new Request('POST', '/api/resource', form: ['_method' => 'DELETE'],
                headers: ['Content-Type' => $type], rawBody: '{"_method":"PATCH"}');
            self::assertSame('POST', $request->method());
        }
        foreach ([
            ['Content-Type' => 'application/x-www-form-urlencoded',
                'content-type' => 'application/x-www-form-urlencoded'],
            ['Content-Type' => 'application/x-www-form-urlencoded',
                'content-type' => 'application/json'],
        ] as $headers) {
            $request = new Request('POST', '/resource', form: ['_method' => 'DELETE'], headers: $headers);
            self::assertTrue($request->headerRepeated('Content-Type'));
            self::assertSame('POST', $request->method());
        }
    }

    public function testCaptureUsesTheActualPostFormCollection(): void
    {
        $original = [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER];
        try {
            $_GET = ['_method' => 'DELETE'];
            $_POST = ['_method' => 'PATCH', 'name' => 'Ada'];
            $_COOKIE = [];
            $_FILES = [];
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/resource',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
            $request = Request::capture();
            $_POST['_method'] = 'DELETE';
            self::assertSame('PATCH', $request->method());
            self::assertSame('POST', $request->transportMethod());
            self::assertSame('PATCH', $request->input('_method'));
        } finally {
            [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER] = $original;
        }
    }
}
