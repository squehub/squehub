<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Session\Drivers\ArraySessionDriver;
use App\Session\SessionDriver;
use App\Session\SessionException;
use App\Session\SessionStore;
use App\Validation\UploadedFile;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    private function store(): SessionStore
    {
        return new SessionStore(new ArraySessionDriver());
    }

    public function testDataOperationsPreserveNullAndUseLiteralKeys(): void
    {
        $store = $this->store();
        $store->put('profile.theme', null);
        $store->put('enabled', false);
        self::assertTrue($store->has('profile.theme'));
        self::assertNull($store->get('profile.theme', 'fallback'));
        self::assertFalse($store->pull('enabled'));
        self::assertFalse($store->has('enabled'));
        self::assertSame(['profile.theme' => null], $store->all());
        $store->forget('profile.theme');
        self::assertSame('fallback', $store->get('profile.theme', 'fallback'));
        self::assertSame([], $store->all());
    }

    public function testFlashSurvivesExactlyTheNextRequestAndCanBeReadTwice(): void
    {
        $store = $this->store();
        $store->put('ordinary', 1);
        $store->flash('status', 'saved');
        $store->flash('nullable', null);
        self::assertSame('saved', $store->get('status'));
        $store->close();
        self::assertSame('saved', $store->get('status'));
        self::assertSame('saved', $store->get('status'));
        self::assertTrue($store->has('nullable'));
        $store->close();
        self::assertFalse($store->has('status'));
        self::assertFalse($store->has('nullable'));
        self::assertSame(1, $store->get('ordinary'));
    }

    public function testFlashOverwriteAndMutationsKeepExplicitLifetime(): void
    {
        $store = $this->store();
        $store->put('status', 'ordinary');
        $store->flash('status', 'flash');
        $store->close();
        $store->flash('status', 'again');
        $store->close();
        self::assertSame('again', $store->get('status'));
        $store->put('status', 'permanent');
        $store->close();
        self::assertSame('permanent', $store->get('status'));
        $store->flash('gone', 'x');
        self::assertSame('x', $store->pull('gone'));
        $store->flash('forgotten', 'x');
        $store->forget('forgotten');
        $store->close();
        self::assertFalse($store->has('gone'));
        self::assertFalse($store->has('forgotten'));
    }

    public function testOldInputIsNestedAndExpiresAfterOneNextRequest(): void
    {
        $store = $this->store();
        $store->flashInput(['profile' => ['email' => 'v@example.com'], 'tags' => ['a', 'b'], 'zero' => 0, 'false' => false, 'null' => null]);
        $store->close();
        self::assertSame('v@example.com', $store->old('profile.email'));
        self::assertSame(['a', 'b'], $store->old('tags'));
        self::assertSame(0, $store->old('zero', 3));
        self::assertFalse($store->old('false', true));
        self::assertNull($store->old('null', 'default'));
        self::assertSame('default', $store->old('missing', 'default'));
        self::assertArrayNotHasKey('_squehub', $store->all());
        $store->close();
        self::assertSame([], $store->old());
    }

    public function testOldInputFiltersSecretsAndUploadsWithoutChangingCallerData(): void
    {
        $store = $this->store();
        $input = [
            'email' => 'v@example.com', 'Password_Confirmation' => 'hidden',
            'user' => ['name' => 'Val', 'access_token' => 'hidden', 'API_SECRET' => 'hidden'],
            'authorization' => 'hidden', 'api_key' => 'hidden',
            'avatar' => UploadedFile::fromPhpEntry(['tmp_name' => 'private-path', 'error' => UPLOAD_ERR_NO_FILE]),
            'php_upload' => ['tmp_name' => null, 'error' => UPLOAD_ERR_NO_FILE, 'name' => 'private-path'],
        ];
        $store->flashInput($input);
        $store->close();
        self::assertSame(['email' => 'v@example.com', 'user' => ['name' => 'Val']], $store->old());
        self::assertSame('hidden', $input['user']['access_token']);
        self::assertStringNotContainsString('private-path', serialize($store->all()));
        self::assertStringNotContainsString('hidden', serialize($store->all()));
    }

    public function testUnexpectedInputAndUnserializableValuesFailBeforeMutation(): void
    {
        $store = $this->store();
        $store->flashInput(['safe' => 'original']);
        try {
            $store->flashInput(['safe' => 'changed', 'object' => new \stdClass()]);
            self::fail('Unexpected object was accepted.');
        } catch (SessionException) {
            self::assertSame('original', $store->old('safe'));
        }
        $resource = fopen('php://memory', 'r');
        try {
            $store->put('bad', ['nested' => $resource]);
            self::fail('Nested resource was accepted.');
        } catch (SessionException) {
            self::assertFalse($store->has('bad'));
        } finally {
            fclose($resource);
        }
    }

    public function testRegenerationPreservesStateAndInvalidationClearsEverything(): void
    {
        $store = $this->store();
        $store->put('user_id', 8);
        $store->flash('status', 'saved');
        $store->flashInput(['email' => 'v@example.com']);
        $first = $store->id();
        $store->regenerate();
        self::assertNotSame($first, $store->id());
        self::assertSame(8, $store->get('user_id'));
        self::assertSame('saved', $store->get('status'));
        self::assertSame('v@example.com', $store->old('email'));
        $second = $store->id();
        $store->invalidate();
        self::assertNotSame($second, $store->id());
        self::assertSame([], $store->all());
        self::assertSame([], $store->old());
    }

    public function testStartIsIdempotentWithinRequest(): void
    {
        $driver = new class implements SessionDriver {
            public int $starts = 0;
            private ArraySessionDriver $inner;
            public function __construct() { $this->inner = new ArraySessionDriver(); }
            public function start(): void { ++$this->starts; $this->inner->start(); }
            public function data(): array { return $this->inner->data(); }
            public function replace(array $data): void { $this->inner->replace($data); }
            public function id(): string { return $this->inner->id(); }
            public function regenerate(): void { $this->inner->regenerate(); }
            public function invalidate(): void { $this->inner->invalidate(); }
            public function close(): void { $this->inner->close(); }
        };
        $store = new SessionStore($driver);
        $store->start();
        $store->get('x');
        $store->start();
        self::assertSame(1, $driver->starts);
        $store->close();
        $store->start();
        self::assertSame(2, $driver->starts);
    }

    public function testReservedKeyIsRejected(): void
    {
        $this->expectException(SessionException::class);
        $this->store()->put('_squehub', 'collision');
    }

    public function testSerializableRecursiveArrayDoesNotLoopDuringSafetyCheck(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;
        $store = $this->store();
        $store->put('graph', $recursive);
        self::assertTrue($store->has('graph'));
    }
}
