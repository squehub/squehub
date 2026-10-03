<?php

declare(strict_types=1);

namespace SqueHub\Tests\Integration;

use App\Diagnostics\Diagnostics;
use App\Diagnostics\Diagnostic;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\Exception\HttpException;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\RedirectResponse;
use App\Http\Response;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Drivers\FileLogger;
use App\Logging\LogContextNormalizer;
use App\Logging\Logger;
use App\Logging\LoggingServiceProvider;
use App\Logging\Log;
use App\Http\ExceptionHandler;
use App\Routing\Route;
use App\Routing\RouteRegistry;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\CsrfException;
use App\Validation\ValidationException;
use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Exercises framework wiring, correlation IDs, and exception reporting. */
final class DiagnosticsHttpTest extends TestCase
{
    private TemporaryProject $project;
    private Application $app;
    private Kernel $kernel;
    private Diagnostics $diagnostics;
    private ArrayLogger $logs;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->project->write('Config/Logging.php', '<?php return ["driver" => "array", "level" => "debug"];');
        $this->app = new Application($this->project->path());
        foreach ([DiagnosticsServiceProvider::class, LoggingServiceProvider::class,
            HttpServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->app->bootstrap();
        $container = $this->app->container();
        $this->kernel = $container->make(Kernel::class);
        $this->diagnostics = $container->make(Diagnostics::class);
        $this->logs = $container->make(ArrayLogger::class);
        $routes = $container->make(RouteRegistry::class);
        $routes->add('GET', '/users/{id}', static fn (): Response =>
            new Response('found', 200, ['X-Request-ID' => 'controller-value']))->named('users.show');
        $routes->add('GET', '/boom', static function (): never {
            throw new RuntimeException('private failure');
        });
        $routes->add('GET', '/validation', static function (): never {
            throw new ValidationException(['email' => ['Required.']]);
        });
        $routes->add('GET', '/csrf', static function (): never {
            throw new CsrfException();
        });
        $routes->add('GET', '/forbidden', static function (): never {
            throw new HttpException(403, 'Forbidden');
        });
        $routes->add('GET', '/redirect', static fn (): RedirectResponse => new RedirectResponse('/users/1', 303));
    }

    protected function tearDown(): void
    {
        Route::setResolver(null);
        Log::setResolver(null);
        Diagnostic::setResolver(null);
        $this->project->remove();
    }

    public function testSequentialRequestsResetAndDoNotTrustClientCorrelationId(): void
    {
        $first = $this->kernel->handle(new Request('GET', '/users/secret-123', [], [], [], [],
            ['X-Request-ID' => 'client-value']));
        $id = $first->header('X-Request-ID');
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $id);
        self::assertNotSame('client-value', $id);
        self::assertNotSame('controller-value', $id);
        self::assertSame(200, $this->diagnostics->snapshot()['status']);
        self::assertSame(['name' => 'users.show', 'pattern' => '/users/{id}'],
            $this->diagnostics->snapshot()['route']);
        self::assertStringNotContainsString('secret-123', json_encode($this->diagnostics->snapshot()));
        $second = $this->kernel->handle(new Request('GET', '/missing'));
        self::assertSame(404, $second->status());
        self::assertNotSame($id, $second->header('X-Request-ID'));
        self::assertNull($this->diagnostics->snapshot()['route']);
        self::assertSame(0, $this->diagnostics->queryCount());
        self::assertCount(0, $this->logs->records()); // Routine 404 is quiet.
    }

    public function testUnhandledExceptionLogsOnceWithGeneratedRequestId(): void
    {
        $response = $this->kernel->handle(new Request('GET', '/boom'));
        self::assertSame(500, $response->status());
        self::assertCount(1, $this->logs->records());
        $record = $this->logs->records()[0];
        self::assertSame('error', $record->level);
        self::assertSame($response->header('X-Request-ID'), $record->context['request_id']);
        self::assertSame(['name' => null, 'pattern' => '/boom'], $record->context['route']);
        self::assertStringNotContainsString('private failure', json_encode($record->toArray()));
    }

    public function testExpectedResponsesKeepStatusAndSeverityPolicy(): void
    {
        $cases = [
            ['POST', '/users/1', 405, 'notice'],
            ['GET', '/validation', 422, null],
            ['GET', '/csrf', 403, 'notice'],
            ['GET', '/forbidden', 403, 'warning'],
            ['GET', '/redirect', 303, null],
        ];
        foreach ($cases as [$method, $path, $status, $level]) {
            $before = count($this->logs->records());
            $response = $this->kernel->handle(new Request($method, $path));
            self::assertSame($status, $response->status());
            self::assertSame($status, $this->diagnostics->snapshot()['status']);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $response->header('X-Request-ID'));
            if ($level === null) {
                self::assertCount($before, $this->logs->records());
            } else {
                self::assertCount($before + 1, $this->logs->records());
                self::assertSame($level, $this->logs->records()[$before]->level);
            }
        }
    }

    public function testHelpersUseApplicationServicesAndNeverAcceptSpoofedContext(): void
    {
        self::assertSame($this->diagnostics, \diagnostics());
        self::assertSame($this->app->container()->make(Logger::class), \logger());
        $request = new Request('GET', '/users/1?private-query=secret-query',
            ['private-query' => 'secret-query'], ['password' => 'body-secret'],
            ['session_id' => 'session-secret'], [], ['Authorization' => 'Bearer auth-secret']);
        $this->kernel->handle($request);
        \logger()->info('safe', ['request_id' => 'spoofed']);
        $record = $this->logs->records()[0];
        self::assertArrayNotHasKey('request_id', $record->context);
        self::assertArrayNotHasKey('correlation_id', $record->context);
        self::assertSame('spoofed', $record->context['data']['request_id']);
        $encoded = json_encode($this->diagnostics->snapshot(), JSON_THROW_ON_ERROR);
        foreach (['secret-query', 'body-secret', 'session-secret', 'auth-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        $snapshot = $this->diagnostics->snapshot();
        $snapshot['database']['queries'] = 900;
        self::assertSame(0, $this->diagnostics->queryCount());
    }

    public function testLoggingFailureCannotReplaceOriginalHttpException(): void
    {
        $normalizer = new LogContextNormalizer(new SecretRedactor($this->app->config()));
        $logger = new Logger(new FileLogger($this->project->path('config')),
            $normalizer, 'debug', true, $this->diagnostics);
        $this->app->container()->make(ExceptionHandler::class)->setLogger($logger);
        $previous = ini_get('error_log');
        ini_set('error_log', $this->project->path('fallback.log'));
        try {
            $response = $this->kernel->handle(new Request('GET', '/boom'));
            self::assertSame(500, $response->status());
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $response->header('X-Request-ID'));
            self::assertStringContainsString('SqueHub exception logger failure:',
                (string) file_get_contents($this->project->path('fallback.log')));
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testGeneratedIdsStayUniqueAcrossReusedKernelRequests(): void
    {
        $ids = [];
        for ($index = 0; $index < 24; ++$index) {
            $response = $this->kernel->handle(new Request('GET', '/users/1'));
            $id = (string) $response->header('X-Request-ID');
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $id);
            $ids[] = $id;
        }
        self::assertCount(24, array_unique($ids));
        self::assertSame(0, $this->diagnostics->queryCount());
    }
}
