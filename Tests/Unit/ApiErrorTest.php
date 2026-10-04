<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Api;

use App\Api\ApiError;
use App\Api\ApiRequestPolicy;
use App\Config\Repository;
use App\Database\Model;
use App\Foundation\Application;
use App\Http\Exception\HttpException;
use App\Http\ExceptionHandler;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Validation\ValidationException;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';

/** API errors use the ordinary exception boundary and strict HTTP JSON response. */
final class ApiErrorTest extends TestCase
{
    private const SECRET = 'SQUEHUB_API_ERROR_PRIVATE_VALUE';

    private TemporaryProject $project;
    private Application $app;

    protected function setUp(): void
    {
        $this->project = new TemporaryProject();
        $this->app = new Application($this->project->path());
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testFactoryCarriesPublicDataSeparatelyFromTheDiagnosticMessage(): void
    {
        $error = ApiError::make('order_conflict', 'The order cannot be changed.', 409,
            ['reason' => self::SECRET], ['X-Application' => 'orders']);

        self::assertInstanceOf(HttpException::class, $error);
        self::assertSame('order_conflict', $error->errorCode());
        self::assertSame('The order cannot be changed.', $error->publicMessage());
        self::assertSame(['reason' => self::SECRET], $error->details());
        self::assertSame(409, $error->status());
        self::assertSame(['X-Application' => 'orders'], $error->headers());
        self::assertStringNotContainsString('The order cannot be changed.', $error->getMessage());
        self::assertStringNotContainsString(self::SECRET, $error->getMessage());
    }

    public function testFactoryDefaultsAndBoundaryValuesAreAccepted(): void
    {
        $error = ApiError::make('bad_request', 'A valid public message.');
        self::assertSame(400, $error->status());
        self::assertNull($error->details());
        self::assertSame([], $error->headers());
        foreach (['a', 'x_123', 'a' . str_repeat('b', 63)] as $code) {
            self::assertSame($code, ApiError::make($code, 'Public message.', 599)->errorCode());
        }
    }

    /** @dataProvider invalidCodes */
    public function testInvalidCodesFailWithoutEchoingInput(string $code): void
    {
        try {
            ApiError::make($code, self::SECRET);
            self::fail('An invalid application error code was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public static function invalidCodes(): array
    {
        return [
            'empty' => [''], 'uppercase' => ['Order_conflict'],
            'number first' => ['1order'], 'underscore first' => ['_order'],
            'space' => ['order conflict'], 'hyphen' => ['order-conflict'],
            'dot' => ['order.conflict'], 'unicode' => ['ordér'],
            'over limit' => [str_repeat('a', 65)],
            'newline' => ["order\n"], 'null byte' => ["order\0"],
            'secret' => [self::SECRET],
        ];
    }

    /** @dataProvider invalidStatuses */
    public function testOnlyErrorStatusesAreAccepted(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiError::make('application_error', self::SECRET, $status);
    }

    public static function invalidStatuses(): array
    {
        return array_map(static fn (int $status): array => [$status], [-1, 0, 99, 200, 204, 205, 304, 399, 600]);
    }

    /** @dataProvider invalidHeaders */
    public function testHttpHeaderValidationIsPreserved(array $headers): void
    {
        try {
            ApiError::make('invalid_input', 'Public message.', 400, null, $headers);
            self::fail('An unsafe HTTP header was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public static function invalidHeaders(): array
    {
        return [
            'newline value' => [['X-Test' => "safe\r\n" . self::SECRET]],
            'newline name' => [["Bad\nName" => self::SECRET]],
            'null value' => [['X-Test' => "\0" . self::SECRET]],
            'control value' => [['X-Test' => "\x7f" . self::SECRET]],
        ];
    }

    public function testPluginAliasKeepsTheCanonicalExceptionIdentity(): void
    {
        $error = \App\Plugins\ApiError::make('order_conflict', 'The order cannot be changed.', 409);
        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame(ApiError::class, (new \ReflectionClass(\App\Plugins\ApiError::class))->getName());
        self::assertFalse(class_exists('App\\Plugins\\ApiRequestPolicy'));
        self::assertFalse(class_exists('App\\Plugins\\ApiErrorRenderer'));
    }

    public function testInvalidErrorsHidePublicArgumentsFromDiagnosticTraces(): void
    {
        // Explicitly enable trace arguments in a child process to exercise the
        // sensitive parameter boundary regardless of the host PHP setting.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'ini_set("zend.exception_ignore_args", "0");'
            . 'try { \\App\\Api\\ApiError::make("application_error", '
            . var_export(self::SECRET, true) . ', 200, ["private" => '
            . var_export(self::SECRET, true) . '], ["X-Private" => '
            . var_export(self::SECRET, true) . ']); }'
            . 'catch (\\InvalidArgumentException $error) {'
            . '$masked = 0; foreach ($error->getTrace() as $frame) {'
            . 'if (($frame["class"] ?? "") !== \\App\\Api\\ApiError::class) continue;'
            . 'foreach ([1, 3, 4] as $index) {'
            . 'if (($frame["args"][$index] ?? null) instanceof \\SensitiveParameterValue) ++$masked; }}'
            . 'echo "MASKED=" . $masked; fwrite(STDERR, $error->getMessage() . $error->getTraceAsString()); }';
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('MASKED=6', $process->getOutput());
        self::assertStringNotContainsString(self::SECRET, $process->getErrorOutput());
    }

    public function testExplicitErrorWorksOutsideConfiguredApiScope(): void
    {
        $request = new Request('POST', '/orders');
        $response = (new ExceptionHandler($this->app))->render(
            ApiError::make('order_conflict', 'The order cannot be changed.', 409,
                ['state' => 'closed'], ['X-Application' => 'orders']), $request
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('orders', $response->header('X-Application'));
        self::assertSame([
            'error' => ['code' => 'order_conflict', 'message' => 'The order cannot be changed.',
                'details' => ['state' => 'closed']],
            'request_id' => $request->requestId(),
        ], json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($request->requestId(), $response->header('X-Request-ID'));
    }

    public function testDetailsOmissionDiffersFromAnExplicitEmptyArray(): void
    {
        $handler = new ExceptionHandler($this->app);
        foreach ([null, []] as $details) {
            $request = new Request();
            $response = $handler->render(ApiError::make('bad_request', 'Public message.', 400, $details), $request);
            $expected = ['code' => 'bad_request', 'message' => 'Public message.'];
            if ($details !== null) $expected['details'] = [];
            self::assertSame(['error' => $expected, 'request_id' => $request->requestId()],
                json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testStrictDetailsPreserveSafeScalarAndNestedArrayTypes(): void
    {
        $details = ['string' => 'Text', 'integer' => 12, 'float' => 12.5, 'true' => true,
            'false' => false, 'null' => null, 'list' => [1, 'two', ['three' => 3]], 'empty' => []];
        $response = (new ExceptionHandler($this->app))->render(
            ApiError::make('invalid_input', 'Public message.', 400, $details), new Request()
        );
        self::assertSame(400, $response->status());
        self::assertSame($details, json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR)['error']['details']);
    }

    public function testErrorRenderingRemovesStaleBodyHeadersAndUsesTrustedCorrelation(): void
    {
        $request = new Request('GET', '/orders', [], [], [], [], ['X-Request-ID' => self::SECRET]);
        $response = (new ExceptionHandler($this->app))->render(ApiError::make(
            'order_conflict', 'Public message.', 409, null,
            ['Content-Length' => '1', 'Content-Type' => 'text/html', 'X-Request-ID' => self::SECRET,
                'Allow' => 'GET, HEAD', 'Retry-After' => '15']
        ), $request);

        self::assertNull($response->header('Content-Length'));
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('GET, HEAD', $response->header('Allow'));
        self::assertSame('15', $response->header('Retry-After'));
        self::assertSame($request->requestId(), $response->header('X-Request-ID'));
        self::assertStringNotContainsString(self::SECRET, $response->content());
    }

    /** @dataProvider malformedScalarDetails */
    public function testMalformedScalarDetailsProduceAnEntireGenericFallback(array $details): void
    {
        $this->assertGenericFallback($details);
    }

    public static function malformedScalarDetails(): array
    {
        return [
            'invalid UTF-8 value' => [['safe' => 'partial-data', 'invalid' => "\xB1\x31"]],
            'invalid UTF-8 key' => [["\xB1\x31" => 'partial-data']],
            'infinity' => [['invalid' => INF]],
            'negative infinity' => [['invalid' => -INF]],
            'not a number' => [['invalid' => NAN]],
        ];
    }

    public function testInvalidUtf8PublicMessageProducesAnEntireGenericFallback(): void
    {
        $request = new Request();
        $response = (new ExceptionHandler($this->app))->render(
            ApiError::make('application_error', "\xB1\x31", 409, ['safe' => 'partial-data']), $request
        );
        $this->assertFallbackResponse($response, $request);
    }

    public function testArbitraryObjectsAreRejectedWithoutInvokingSerialization(): void
    {
        $object = new class implements JsonSerializable {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                ++$this->calls;
                return ['private' => 'SQUEHUB_API_ERROR_PRIVATE_VALUE'];
            }
        };
        $this->assertGenericFallback(['safe' => 'partial-data', 'invalid' => $object]);
        self::assertSame(0, $object->calls);
        $this->assertGenericFallback(['invalid' => (object) ['private' => self::SECRET]]);
    }

    public function testModelDetailsCannotSerializePrivateAttributes(): void
    {
        $model = ApiErrorPrivateModel::hydrate(['id' => 42, 'name' => 'Private person', 'password_hash' => self::SECRET]);
        $this->assertGenericFallback(['record' => $model]);
        self::assertSame(self::SECRET, $model->getAttribute('password_hash'));
    }

    public function testStreamDetailsProduceAnEntireGenericFallback(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        try {
            fwrite($stream, self::SECRET);
            $this->assertGenericFallback(['safe' => 'partial-data', 'invalid' => $stream]);
        } finally {
            fclose($stream);
        }
    }

    public function testExcessiveNestingTerminatesWithAGenericFallback(): void
    {
        $details = ['value' => self::SECRET];
        for ($depth = 0; $depth < 64; ++$depth) $details = ['child' => $details];
        $this->assertGenericFallback($details);
    }

    public function testCircularArraysTerminateWithAGenericFallback(): void
    {
        $details = ['value' => self::SECRET];
        $details['cycle'] = &$details;
        $this->assertGenericFallback($details);
        unset($details['cycle']);
    }

    public function testExcessiveItemCountsTerminateWithAGenericFallback(): void
    {
        $this->assertGenericFallback(array_fill(0, 10001, 'partial-data'));
    }

    public function testSensitiveDetailsAndKnownRequestSecretsAreRedacted(): void
    {
        $this->app->config()->set('database.password', 'mysql-password-private');
        $this->app->config()->set('mail.password', 'SMTP-password-private');
        $this->app->config()->set('crypt.keys', ['primary' => 'APP_KEY=fake-secret-private']);
        $this->app->config()->set('redis.connections.default.url', 'redis://user:password-private@host');
        $secrets = ['mysql-password-private', 'SMTP-password-private', 'APP_KEY=fake-secret-private',
            'redis://user:password-private@host', 'fake-bearer-token-private', 'cookie-value-private',
            'session-id-private', 'query-token-private', 'form-password-private'];
        $request = new Request('POST', '/orders', ['token' => 'query-token-private'],
            ['password' => 'form-password-private'], ['session' => 'session-id-private'], [],
            ['Authorization' => 'Bearer fake-bearer-token-private', 'Cookie' => 'demo=cookie-value-private']);
        $response = (new ExceptionHandler($this->app))->render(ApiError::make(
            'order_conflict', implode(' ', $secrets), 409,
            ['password' => self::SECRET, 'nested' => ['api_key' => self::SECRET],
                'explanation' => implode(' ', $secrets), 'safe' => 'Visible reason.']
        ), $request);

        self::assertSame(409, $response->status());
        foreach ([...$secrets, self::SECRET] as $secret) {
            self::assertStringNotContainsString($secret, $response->content());
        }
        $body = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['error', 'request_id'], array_keys($body));
        self::assertSame(['code', 'message', 'details'], array_keys($body['error']));
        self::assertSame('order_conflict', $body['error']['code']);
        self::assertSame('Visible reason.', $body['error']['details']['safe']);
        self::assertSame($request->requestId(), $body['request_id']);
    }

    public function testPrivacyInspectionExhaustionFailsClosedForQueryAndBody(): void
    {
        $this->app->config()->set('api.enabled', true);
        $requests = [
            new Request('POST', '/api', array_fill(0, 10000, 'padding'), ['password' => self::SECRET]),
            new Request('POST', '/api', [], [...array_fill(0, 10000, 'padding'), 'password' => self::SECRET]),
        ];
        foreach ($requests as $request) {
            $response = (new ExceptionHandler($this->app))->render(
                new ValidationException(['password' => ['Rejected ' . self::SECRET . '.']]), $request
            );
            $this->assertFallbackResponse($response, $request);
        }
    }

    public function testPrivacyInspectionDepthLimitFailsClosedBeforeRenderingValidationMessages(): void
    {
        $this->app->config()->set('api.enabled', true);
        $input = ['password' => self::SECRET];
        for ($depth = 0; $depth < 40; ++$depth) $input = ['child' => $input];
        $request = new Request('POST', '/api', [], $input);
        $response = (new ExceptionHandler($this->app))->render(
            new ValidationException(['password' => ['Rejected ' . self::SECRET . '.']]), $request
        );
        $this->assertFallbackResponse($response, $request);
    }

    /** @dataProvider numericSensitiveInputs */
    public function testNumericSensitiveInputIsRedactedFromValidationMessages(int|float|bool $value): void
    {
        $this->app->config()->set('api.enabled', true);
        $request = new Request('POST', '/api', [], ['password' => $value]);
        $response = (new ExceptionHandler($this->app))->render(
            new ValidationException(['password' => ['Rejected ' . (string) $value . '.']]), $request
        );
        self::assertSame(422, $response->status());
        self::assertSame([
            'error' => ['code' => 'validation_failed', 'message' => 'The submitted data is invalid.',
                'details' => ['password' => ['Rejected [REDACTED].']]],
            'request_id' => $request->requestId(),
        ], json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function numericSensitiveInputs(): array
    {
        return ['integer' => [86753090], 'float' => [86753.25], 'boolean' => [true]];
    }

    public function testConfiguredCsrfFieldAndHeaderAreSensitiveRegardlessOfTheirNames(): void
    {
        $this->app->config()->set('api.enabled', true);
        $this->app->config()->set('csrf.field', 'request_guard');
        $this->app->config()->set('csrf.header', 'X-Guard');
        $request = new Request('POST', '/api', [], ['request_guard' => 'planted-csrf-field'], [], [],
            ['X-Guard' => 'planted-csrf-header']);
        $response = (new ExceptionHandler($this->app))->render(new ValidationException([
            'email' => ['Rejected planted-csrf-field and planted-csrf-header.'],
        ]), $request);

        self::assertSame(422, $response->status());
        self::assertSame([
            'error' => ['code' => 'validation_failed', 'message' => 'The submitted data is invalid.',
                'details' => ['email' => ['Rejected [REDACTED] and [REDACTED].']]],
            'request_id' => $request->requestId(),
        ], json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($request->requestId(), $response->header('X-Request-ID'));
    }

    /** @dataProvider malformedValidationMaps */
    public function testValidationDetailsRequireNamedFieldsWithStringMessageLists(array $errors): void
    {
        $this->app->config()->set('api.enabled', true);
        $request = new Request('POST', '/api');
        $response = (new ExceptionHandler($this->app))->render(new ValidationException($errors), $request);
        $this->assertFallbackResponse($response, $request);
    }

    public static function malformedValidationMaps(): array
    {
        return [
            'scalar message list' => [['email' => self::SECRET]],
            'nested message' => [['email' => [[self::SECRET]]]],
            'integer message' => [['email' => [42]]],
            'boolean message' => [['email' => [false]]],
            'null message' => [['email' => [null]]],
            'empty field' => [['' => [self::SECRET]]],
            'numeric field' => [[42 => [self::SECRET]]],
            'named message list' => [['email' => ['reason' => self::SECRET]]],
        ];
    }

    public function testApiPolicyDefaultsToDisabledRegardlessOfAcceptOrPath(): void
    {
        foreach ([new Repository(), new Repository(['api' => ['enabled' => false, 'paths' => ['/']]])] as $config) {
            $policy = new ApiRequestPolicy($config);
            foreach (['/', '/api', '/api/users'] as $uri) {
                self::assertFalse($policy->matches(new Request('GET', $uri, [], [], [], [],
                    ['Accept' => 'application/json'])));
            }
        }
    }

    /** @dataProvider scopedRequestPaths */
    public function testApiPolicyMatchesSegmentsAndIgnoresQueryAndAccept(string $uri, bool $matches): void
    {
        $policy = new ApiRequestPolicy(new Repository(['api' => ['enabled' => true]]));
        foreach (['text/html', 'application/json'] as $accept) {
            self::assertSame($matches, $policy->matches(new Request('GET', $uri, [], [], [], [], ['Accept' => $accept])));
        }
    }

    public static function scopedRequestPaths(): array
    {
        return [
            'root' => ['/', false], 'exact' => ['/api', true],
            'trailing slash' => ['/api/', true], 'descendant' => ['/api/users', true],
            'nested descendant' => ['/api/orders/123', true], 'adjacent prefix' => ['/apiary', false],
            'similar prefix' => ['/api-v2', false], 'case sensitive' => ['/API/users', false],
            'query is not scope' => ['/web?next=/api', false],
            'api query' => ['/api?next=/web', true], 'api descendant query' => ['/api/users?q=/web', true],
        ];
    }

    public function testPolicySupportsMultiplePrefixesTrailingSlashesAndExplicitRoot(): void
    {
        $policy = new ApiRequestPolicy(new Repository(['api' => ['enabled' => true, 'paths' => ['/services/', '/partner/api']]]));
        foreach (['/services', '/services/orders', '/partner/api', '/partner/api/orders'] as $path) {
            self::assertTrue($policy->matches(new Request('GET', $path)), $path);
        }
        foreach (['/api', '/services-other', '/partner/apiculture'] as $path) {
            self::assertFalse($policy->matches(new Request('GET', $path)), $path);
        }
        $root = new ApiRequestPolicy(new Repository(['api' => ['enabled' => true, 'paths' => ['/']]]));
        self::assertTrue($root->matches(new Request('GET', '/')));
        self::assertTrue($root->matches(new Request('GET', '/web')));
    }

    /** @dataProvider invalidPolicyConfigurations */
    public function testInvalidApiPolicyConfigurationFailsSafely(array $api): void
    {
        try {
            (new ApiRequestPolicy(new Repository(['api' => $api])))->matches(new Request('GET', '/api'));
            self::fail('Malformed API scope configuration was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public static function invalidPolicyConfigurations(): array
    {
        $cases = [
            'enabled string' => ['enabled' => 'true'], 'enabled integer' => ['enabled' => 1],
            'enabled null' => ['enabled' => null], 'paths string' => ['paths' => '/api'],
            'paths null' => ['paths' => null], 'paths named map' => ['paths' => ['main' => '/api']],
            'path integer' => ['paths' => [42]], 'path nested' => ['paths' => [['/api']]],
            'path empty' => ['paths' => ['']], 'path relative' => ['paths' => ['api']],
            'path URL' => ['paths' => ['https://example.test/api']],
            'path query' => ['paths' => ['/api?key=' . self::SECRET]],
            'path fragment' => ['paths' => ['/api#fragment']], 'path regex' => ['paths' => ['^/api/.*$']],
            'path wildcard' => ['paths' => ['/api/*']], 'path control' => ['paths' => ["/api/\n" . self::SECRET]],
            'path backslash' => ['paths' => ['/api\\users']], 'path dot segment' => ['paths' => ['/api/../admin']],
            'path current segment' => ['paths' => ['/api/./users']], 'path duplicate slash' => ['paths' => ['/api//users']],
            'too many paths' => ['paths' => array_fill(0, 65, '/api')],
        ];
        return array_map(static fn (array $api): array => [array_merge(['enabled' => true], $api)], $cases);
    }

    public function testEnabledHealthEndpointsAreExcludedExactly(): void
    {
        $config = new Repository(['api' => ['enabled' => true, 'paths' => ['/']],
            'health' => ['endpoints_enabled' => true]]);
        $policy = new ApiRequestPolicy($config);
        foreach (['/health/live', '/health/ready', '/health/live?check=1'] as $path) {
            self::assertFalse($policy->matches(new Request('GET', $path)), $path);
        }
        foreach (['/health', '/health/live/details', '/health/ready-other'] as $path) {
            self::assertTrue($policy->matches(new Request('GET', $path)), $path);
        }
        $config->set('health.endpoints_enabled', false);
        self::assertTrue((new ApiRequestPolicy($config))->matches(new Request('GET', '/health/live')));
    }

    public function testRequestIdsAreOpaqueStableIndependentAndIgnoreIncomingValues(): void
    {
        $first = new Request('GET', '/api', [], [], [], [], ['X-Request-ID' => self::SECRET]);
        $second = new Request('GET', '/api', [], [], [], [], ['X-Request-ID' => self::SECRET]);
        $id = $first->requestId();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
        self::assertSame($id, $first->requestId());
        self::assertNotSame($id, $second->requestId());
        self::assertNotSame(self::SECRET, $id);
        $first->renewRequestId();
        self::assertNotSame($id, $first->requestId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $first->requestId());
    }

    /** @dataProvider bodylessResponses */
    public function testResponseSendingSuppressesHeadAndBodylessStatuses(int $status, bool $head): void
    {
        $originalStatus = http_response_code();
        try {
            ob_start();
            (new JsonResponse(['private' => self::SECRET], $status))->send($head);
            self::assertSame('', ob_get_clean());
        } finally {
            if (is_int($originalStatus) && !headers_sent()) http_response_code($originalStatus);
        }
    }

    public static function bodylessResponses(): array
    {
        return ['HEAD success' => [200, true], 'HEAD error' => [500, true],
            '204' => [204, false], '205' => [205, false], '304' => [304, false]];
    }

    public function testManagedJsonErrorCanSendAfterHeadersWereSentWithoutChangingStatus(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = 'require ' . var_export($autoload, true) . ';'
            . 'http_response_code(209); echo "prior";'
            . '$response = new \\App\\Http\\JsonResponse(["error" => ["code" => "internal_error",'
            . '"message" => "An unexpected error occurred."], "request_id" => str_repeat("a", 32)], 500,'
            . '["Cache-Control" => "no-store", "X-Request-ID" => str_repeat("a", 32)]);'
            . '$response->send(); $response->send();'
            . 'fwrite(STDERR, "STATUS=" . http_response_code());';
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame('prior{"error":{"code":"internal_error","message":"An unexpected error occurred."},'
            . '"request_id":"' . str_repeat('a', 32) . '"}', $process->getOutput());
        self::assertSame('STATUS=209', $process->getErrorOutput());
    }

    private function assertGenericFallback(array $details): void
    {
        $this->app->config()->set('app.debug', true);
        $request = new Request('POST', '/orders');
        $response = (new ExceptionHandler($this->app))->render(
            ApiError::make('application_error', self::SECRET, 409, $details), $request
        );
        $this->assertFallbackResponse($response, $request);
    }

    private function assertFallbackResponse(Response $response, Request $request): void
    {
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->status());
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame($request->requestId(), $response->header('X-Request-ID'));
        self::assertSame([
            'error' => ['code' => 'internal_error', 'message' => 'An unexpected error occurred.'],
            'request_id' => $request->requestId(),
        ], json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::SECRET, $response->content());
        self::assertStringNotContainsString('partial-data', $response->content());
    }
}

final class ApiErrorPrivateModel extends Model
{
    protected string $table = 'api_error_private_models';
}
