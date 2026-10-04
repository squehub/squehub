<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Container\Container;
use App\Foundation\Application;
use App\Http\DispatchResult;
use App\Http\Dispatcher;
use App\Http\ExceptionHandler;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\ResponseNormalizer;
use App\Validation\ValidationException;
use App\Validation\ValidatorFactory;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class ValidationHttpTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRequestValidatesBodyWithoutOtherSources(): void
    {
        $request = new Request('POST', '/users', ['admin' => true],
            ['name' => 'Ada', 'password' => 'secret-value', 'extra' => 'drop'],
            ['email' => 'cookie@example.test'], [], ['Authorization' => 'Bearer token-value']);
        $request->setAttribute('route.params', ['role' => 'admin']);
        self::assertSame(['name' => 'Ada'], $request->validate(['name' => 'required|string']));
        try {
            $request->validate(['email' => 'required|email', 'role' => 'required', 'admin' => 'required']);
            self::fail('Validation should fail without merging other sources.');
        } catch (ValidationException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame(['email', 'role', 'admin'], array_keys($exception->errors()));
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }

    public function testJsonRequestValidationUsesDecodedBody(): void
    {
        $request = new Request('POST', '/', ['name' => 'query'], ['name' => 'form'], [], [],
            ['Content-Type' => 'application/json'], [], '{"name":"JSON","unused":true}');
        self::assertSame(['name' => 'JSON'], $request->validate(['name' => 'required|string']));
    }

    public function testKernelRendersJsonAndHtmlValidationErrorsWithoutSecrets(): void
    {
        $app = new Application($this->project->path());
        $app->config()->set('app.debug', true);
        $dispatcher = new class implements Dispatcher {
            public function dispatch(Request $request): DispatchResult
            {
                $request->validate(['email' => 'required|email']);
                return new DispatchResult('never');
            }
        };
        $kernel = new Kernel($dispatcher, new ResponseNormalizer(), new ExceptionHandler($app),
            new ValidatorFactory(new Container()));
        $json = $kernel->handle(new Request('POST', '/', [], ['email' => 'private-value'], [], [],
            ['Accept' => 'application/json']));
        self::assertSame(422, $json->status());
        self::assertSame(['message' => 'Validation failed.', 'errors' => [
            'email' => ['The email field must be a valid email address.'],
        ]], json_decode($json->content(), true));
        self::assertStringNotContainsString('private-value', $json->content());

        $app->config()->set('app.debug', false);
        $production = $kernel->handle(new Request('POST', '/', [], ['email' => 'private-value'], [], [],
            ['Accept' => 'application/json']));
        self::assertSame($json->content(), $production->content());

        $html = $kernel->handle(new Request('POST', '/', [], ['email' => '<script>']));
        self::assertSame(422, $html->status());
        self::assertStringContainsString('email', $html->content());
        self::assertStringNotContainsString('<script>', $html->content());
        $escaped = (new ExceptionHandler($app))->render(new ValidationException([
            'email' => ['<script>alert(1)</script>'],
        ]), new Request());
        self::assertStringContainsString('&lt;script&gt;', $escaped->content());
        self::assertStringNotContainsString('<script>', $escaped->content());
    }

    public function testRequestFileUsesUploadSourceOnly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'squehub-validation-');
        file_put_contents($path, 'sample text');
        try {
            $upload = ['name' => 'sample.txt', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 11];
            $request = new Request('POST', '/', [], ['document' => $path], [], ['document' => $upload]);
            $data = $request->validate(['document' => 'required|file|mimes:txt|max:1']);
            self::assertInstanceOf(\App\Validation\UploadedFile::class, $data['document']);
            self::assertSame($upload, $request->file('document'));

            $bad = new Request('POST', '/', [], ['document' => $path]);
            $this->expectException(ValidationException::class);
            $bad->validate(['document' => 'file']);
        } finally {
            unlink($path);
        }
    }

    public function testMissingFailedAndNullableUploads(): void
    {
        $missing = ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $request = new Request('POST', '/', [], [], [], ['avatar' => $missing]);
        self::assertSame([], $request->validate(['avatar' => 'nullable|file']));
        try {
            $request->validate(['avatar' => 'required|file']);
            self::fail('A missing upload must fail required.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('avatar', $exception->errors());
        }
        $failed = new Request('POST', '/', [], [], [], ['avatar' => [
            'name' => 'avatar.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0,
        ]]);
        $this->expectException(ValidationException::class);
        $failed->validate(['avatar' => 'nullable|file']);
    }

    public function testImageChecksContentAndFileSizeUsesKilobytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'squehub-image-');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
        file_put_contents($path, $bytes);
        try {
            $entry = ['name' => 'wrong-name.txt', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
            $request = new Request('POST', '/', [], [], [], ['image' => $entry]);
            self::assertArrayHasKey('image', $request->validate(['image' => 'required|file|image|mimes:png|max:1']));
            try {
                $request->validate(['image' => 'file|min:1']);
                self::fail('The image is smaller than one kilobyte.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('image', $exception->errors());
            }
            file_put_contents($path, 'not an image');
            $entry['size'] = strlen('not an image');
            $invalid = new Request('POST', '/', [], [], [], ['image' => $entry]);
            $this->expectException(ValidationException::class);
            $invalid->validate(['image' => 'file|image']);
        } finally {
            unlink($path);
        }
    }
}
