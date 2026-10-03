<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Http\Exception\MalformedJsonException;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testFormQueryAndRoutePathStaySeparate(): void
    {
        $request = new Request('post', '/users/?page=2', ['page' => '2'], ['name' => 'Ada', 'zero' => 0, 'empty' => '', 'off' => false]);
        self::assertSame('POST', $request->method());
        self::assertSame('/users/?page=2', $request->uri());
        self::assertSame('/users', $request->path());
        self::assertSame('2', $request->query('page'));
        self::assertSame(1, $request->query('missing', 1));
        self::assertSame(['page' => '2'], $request->query());
        self::assertSame('Ada', $request->input('name'));
        self::assertSame('fallback', $request->input('page', 'fallback'));
        self::assertSame(['name' => 'Ada', 'zero' => 0, 'empty' => '', 'off' => false], $request->all());
        self::assertSame(['name' => 'Ada', 'zero' => 0], $request->only(['name', 'zero', 'missing']));
        foreach (['zero', 'empty', 'off'] as $key) {
            self::assertTrue($request->has($key));
        }
        self::assertFalse($request->has('missing'));
    }

    public function testJsonBodyAndContentType(): void
    {
        $request = new Request('POST', '/api', [], ['ignored' => 'form'], [], [],
            ['Content-Type' => 'Application/Json; charset=UTF-8'], [], '{"email":"ada@example.test","active":false}');
        self::assertSame('application/json', $request->contentType());
        self::assertSame('ada@example.test', $request->input('email'));
        self::assertSame(false, $request->json('active'));
        self::assertSame(['email' => 'ada@example.test', 'active' => false], $request->json());
        self::assertTrue($request->expectsJson());
        self::assertFalse($request->has('ignored'));
    }

    public function testMalformedJsonThrowsDefinedHttpError(): void
    {
        $request = new Request('POST', '/', [], [], [], [], ['Content-Type' => 'application/json'], [], '{broken');
        $this->expectException(MalformedJsonException::class);
        $request->json();
    }

    public function testHeadersBearerCookiesFilesServerAndAttributes(): void
    {
        $file = ['name' => 'avatar.png', 'tmp_name' => '/tmp/example'];
        $request = new Request('GET', '/', [], [], ['theme' => 'dark'], ['avatar' => $file],
            ['authorization' => 'Bearer abc123', 'ACCEPT' => 'application/problem+json'],
            ['HTTP_HOST' => 'example.test:8080', 'HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame('Bearer abc123', $request->header('AUTHORIZATION'));
        self::assertSame('abc123', $request->bearerToken());
        self::assertTrue($request->expectsJson());
        self::assertSame('dark', $request->cookie('theme'));
        self::assertSame($file, $request->file('avatar'));
        self::assertSame('example.test', $request->host());
        self::assertSame('https', $request->scheme());
        self::assertSame('127.0.0.1', $request->ip());
        self::assertSame('on', $request->server('HTTPS'));
        self::assertArrayHasKey('Authorization', $request->headers());
        $request->setAttribute('route.params', ['id' => 7]);
        self::assertSame(['id' => 7], $request->attribute('route.params'));
        self::assertSame('missing', $request->attribute('other', 'missing'));
    }

    public function testBearerParsingRejectsAmbiguousHeadersAndWhitespace(): void
    {
        foreach ([
            'Bearer', 'Bearer ', 'Bearer  token', "Bearer\ttoken",
            ' Bearer token', 'Bearer token ', 'Basic token',
            'Bearer token, Bearer other', 'Bearer token extra',
            'Bearer ' . str_repeat('a', 2048),
        ] as $authorization) {
            self::assertNull((new Request(headers: ['Authorization' => $authorization]))->bearerToken());
        }
        self::assertSame('abc_123', (new Request(headers: ['Authorization' => 'bEaReR abc_123']))->bearerToken());

        foreach ([
            ['Authorization' => 'Bearer same', 'authorization' => 'Bearer same'],
            ['Authorization' => 'Bearer first', 'authorization' => 'Bearer second'],
        ] as $headers) {
            $request = new Request(headers: $headers);
            self::assertTrue($request->headerRepeated('Authorization'));
            self::assertNull($request->bearerToken());
        }
        self::assertNull((new Request('GET', '/api?access_token=from-url',
            form: ['access_token' => 'from-body'], cookies: ['access_token' => 'from-cookie']))->bearerToken());
    }

    public function testProxyHeadersAreNotTrustedForHostSchemeOrIp(): void
    {
        $request = new Request('GET', '/', [], [], [], [], ['X-Forwarded-Proto' => 'https'],
            ['HTTP_HOST' => 'direct.test', 'HTTPS' => 'off', 'REMOTE_ADDR' => '192.0.2.1',
                'HTTP_X_FORWARDED_HOST' => 'attacker.test', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']);
        self::assertSame('direct.test', $request->host());
        self::assertSame('http', $request->scheme());
        self::assertSame('192.0.2.1', $request->ip());
    }

    public function testCaptureCopiesSuperglobalsOnce(): void
    {
        $original = [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER];
        try {
            $_GET = ['page' => '3'];
            $_POST = ['name' => 'Before'];
            $_COOKIE = ['theme' => 'dark'];
            $_FILES = ['avatar' => ['name' => 'a.png']];
            $_SERVER = ['REQUEST_METHOD' => 'patch', 'REQUEST_URI' => '/users/1?page=3',
                'HTTP_AUTHORIZATION' => 'Bearer captured', 'CONTENT_TYPE' => 'text/plain',
                'REMOTE_ADDR' => '192.0.2.3'];
            $request = Request::capture();
            $_GET['page'] = '99';
            $_POST['name'] = 'After';
            $_SERVER['REQUEST_METHOD'] = 'DELETE';
            self::assertSame('PATCH', $request->method());
            self::assertSame('/users/1', $request->path());
            self::assertSame('3', $request->query('page'));
            self::assertSame('Before', $request->input('name'));
            self::assertSame('captured', $request->bearerToken());
            self::assertSame('dark', $request->cookie('theme'));
            self::assertSame('a.png', $request->file('avatar')['name']);
        } finally {
            [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER] = $original;
        }
    }
}
