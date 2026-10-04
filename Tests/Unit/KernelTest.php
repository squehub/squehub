<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Foundation\Application;
use App\Http\DispatchResult;
use App\Http\Dispatcher;
use App\Http\ExceptionHandler;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Throwable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

final class FakeDispatcher implements Dispatcher
{
    public ?Request $seen = null;

    public function __construct(private DispatchResult|Throwable $result)
    {
    }

    public function dispatch(Request $request): DispatchResult
    {
        $this->seen = $request;
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        return $this->result;
    }
}

final class KernelTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->app = new Application($this->project->path());
        $this->app->config()->set('app.debug', false);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testKernelReceivesRequestAndNormalizesDispatcherResult(): void
    {
        $dispatcher = new FakeDispatcher(new DispatchResult('result', 'echo:'));
        $kernel = $this->kernel($dispatcher);
        $request = new Request('post', '/submit');
        $response = $kernel->handle($request);
        self::assertSame($request, $dispatcher->seen);
        self::assertSame('echo:result', $response->content());
        self::assertSame(200, $response->status());
    }

    public function testNormalizerHandlesResponseArrayNullNumbersAndNotFound(): void
    {
        $normalizer = new ResponseNormalizer();
        $explicit = new Response('explicit', 202);
        self::assertSame($explicit, $normalizer->normalize(new DispatchResult($explicit, 'ignored')));
        $array = $normalizer->normalize(new DispatchResult(['ok' => true], 'ignored'));
        self::assertInstanceOf(JsonResponse::class, $array);
        self::assertSame(['ok' => true], json_decode($array->content(), true));
        self::assertSame('printed', $normalizer->normalize(new DispatchResult(null, 'printed'))->content());
        self::assertSame('', $normalizer->normalize(new DispatchResult())->content());
        self::assertSame('3.5', $normalizer->normalize(new DispatchResult(3.5))->content());
        $missing = $normalizer->normalize(new DispatchResult('missing', '', false));
        self::assertSame(404, $missing->status());
        self::assertSame('missing', $missing->content());
    }

    public function testDispatcherExceptionBecomesSafeResponse(): void
    {
        $kernel = $this->kernel(new FakeDispatcher(new RuntimeException('internal secret')));
        $response = $kernel->handle(new Request());
        self::assertSame(500, $response->status());
        self::assertStringContainsString('Internal Server Error', $response->content());
        self::assertStringNotContainsString('internal secret', $response->content());
    }

    public function testJsonRequestGetsJsonExceptionResponse(): void
    {
        $kernel = $this->kernel(new FakeDispatcher(new RuntimeException('internal secret')));
        $response = $kernel->handle(new Request('GET', '/', [], [], [], [], ['Accept' => 'application/json']));
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(['error' => 'Internal Server Error'], json_decode($response->content(), true));
    }

    private function kernel(Dispatcher $dispatcher): Kernel
    {
        return new Kernel($dispatcher, new ResponseNormalizer(), new ExceptionHandler($this->app));
    }
}
