<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Config\Repository;
use App\Database\ModelClock;
use App\Foundation\Application;
use App\Logging\Drivers\ArrayLogger;
use App\Logging\Drivers\FileLogger;
use App\Logging\LogContextNormalizer;
use App\Logging\Logger;
use App\Logging\Log;
use App\Logging\LoggingException;
use App\Logging\LoggingServiceProvider;
use App\Support\SecretRedactor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use SqueHub\Tests\Fixtures\TemporaryProject;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Verifies structured logging without involving an application log file. */
final class LoggingTest extends TestCase
{
    private function logger(ArrayLogger $sink, string $level = 'debug', bool $failFast = false): Logger
    {
        $normalizer = new LogContextNormalizer(new SecretRedactor(new Repository([
            'database' => ['password' => 'database-secret-value'],
        ])));
        $clock = new class implements ModelClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-23 10:20:30', new DateTimeZone('UTC'));
            }
        };
        return new Logger($sink, $normalizer, $level, $failFast, null, $clock);
    }

    public function testAllLevelsAndThresholdRetainOrderAndClock(): void
    {
        $sink = new ArrayLogger();
        $logger = $this->logger($sink, 'notice');
        $logger->debug('debug');
        $logger->info('info');
        foreach (['notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
            $logger->$level($level);
        }
        self::assertSame(['notice', 'warning', 'error', 'critical', 'alert', 'emergency'],
            array_map(static fn ($record): string => $record->level, $sink->records()));
        self::assertSame('2026-09-23T10:20:30+00:00', $sink->records()[0]->toArray()['timestamp']);
        self::assertSame('NOTICE', $sink->records()[0]->toArray()['level']);
        $this->expectException(InvalidArgumentException::class);
        $logger->log('verbose', 'invalid');
    }

    public function testSensitiveContextAndMessageAreRedactedWithoutObjectTraversal(): void
    {
        $sink = new ArrayLogger();
        $logger = $this->logger($sink);
        $logger->error('credential database-secret-value and token-value', [
            'nested' => ['api_token' => 'token-value', 'password' => 'private-value'],
            'exception' => new RuntimeException('exception-secret-value'),
            'object' => new \stdClass(),
            'long' => str_repeat('x', 5000),
        ]);
        $record = $sink->records()[0];
        $encoded = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        foreach (['database-secret-value', 'token-value', 'private-value', 'exception-secret-value'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertSame('[REDACTED]', $record->context['data']['nested']['api_token']);
        self::assertSame(RuntimeException::class, $record->context['data']['exception']['class']);
        self::assertSame(['class' => \stdClass::class], $record->context['data']['object']);
        self::assertStringContainsString('[truncated]', $record->context['data']['long']);
    }

    public function testShortConfiguredSecretsAndNonSerializingObjectsStayPrivate(): void
    {
        $sink = new ArrayLogger();
        $normalizer = new LogContextNormalizer(new SecretRedactor(new Repository([
            'database' => ['password' => 'xy'],
        ])));
        $logger = new Logger($sink, $normalizer, 'debug');
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('Object serialization must not run.');
            }
        };
        $logger->info('value xy', ['object' => $object]);
        $record = $sink->records()[0];
        self::assertSame('value [REDACTED]', $record->message);
        self::assertSame(['class' => $object::class], $record->context['data']['object']);
    }

    public function testNewPersonalAccessTokenIsRedactedBeforeItAppearsInARequest(): void
    {
        $sink = new ArrayLogger();
        $logger = $this->logger($sink);
        $raw = \App\Auth\Tokens\TokenFormat::generate()['token'];
        $logger->info('issued ' . $raw, ['note' => 'copied ' . $raw]);
        $encoded = json_encode($sink->records()[0]->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($raw, $encoded);
        self::assertStringContainsString('[REDACTED]', $sink->records()[0]->message);
    }

    public function testFileSinkAppendsIndependentJsonLinesAndFailFastIsOptional(): void
    {
        $project = new TemporaryProject();
        try {
            $path = $project->path('Storage/Logs/test.log');
            $recordSink = new ArrayLogger();
            $recordLogger = $this->logger($recordSink);
            $recordLogger->info("first\nline");
            $recordLogger->warning('second');
            $file = new FileLogger($path);
            foreach ($recordSink->records() as $record) $file->write($record);
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            self::assertCount(2, $lines);
            self::assertSame("first\nline", json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR)['message']);
            self::assertSame('second', json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR)['message']);
            $failedPath = $project->path('config'); // An existing directory cannot be opened as a log file.
            $normalizer = new LogContextNormalizer(new SecretRedactor(new Repository()));
            $previous = ini_get('error_log');
            ini_set('error_log', $project->path('fallback.log'));
            try {
                (new Logger(new FileLogger($failedPath), $normalizer))->info('safe fallback');
                self::assertStringContainsString('SqueHub logger failure: App\\Logging\\LoggingException',
                    (string) file_get_contents($project->path('fallback.log')));
                $this->expectException(LoggingException::class);
                (new Logger(new FileLogger($failedPath), $normalizer, 'debug', true))->info('fail fast');
            } finally {
                ini_set('error_log', (string) $previous);
            }
        } finally {
            $project->remove();
        }
    }

    public function testApplicationLoggerWorksWithoutHttpSessionOrDatabase(): void
    {
        $project = new TemporaryProject();
        try {
            $app = new Application($project->path());
            $app->register(LoggingServiceProvider::class);
            $app->bootstrap();
            self::assertSame($app->container()->make(Logger::class), \logger());
            self::assertSame(\logger(), $app->container()->make(\Psr\Log\LoggerInterface::class));
            $path = $project->path('Storage/Logs/squehub.log');
            self::assertFileDoesNotExist($path);
            \logger()->info('CLI-safe record');
            self::assertFileExists($path);
            $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('CLI-safe record', $record['message']);
            self::assertSame(['data' => []], $record['context']);
        } finally {
            Log::setResolver(null);
            $project->remove();
        }
    }

    public function testConfiguredWindowsAbsoluteLogPathIsAccepted(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Drive-qualified log paths require a Windows host.');
        }
        $project = new TemporaryProject();
        try {
            $path = str_replace('/', '\\', $project->path('Storage/Logs/custom.log'));
            $project->write('Config/Logging.php', '<?php return ["driver" => "file", "path" => '
                . var_export($path, true) . '];');
            $app = new Application($project->path());
            $app->register(LoggingServiceProvider::class);
            $app->bootstrap();
            \logger()->info('custom location');
            self::assertFileExists($path);
        } finally {
            Log::setResolver(null);
            $project->remove();
        }
    }

    public function testConfiguredPosixAbsoluteLogPathIsAccepted(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX log path behavior requires a POSIX host.');
        }
        $project = new TemporaryProject();
        try {
            $path = $project->path('Storage/Logs/posix.log');
            $project->write('Config/Logging.php', '<?php return ["driver" => "file", "path" => '
                . var_export($path, true) . '];');
            $app = new Application($project->path());
            $app->register(LoggingServiceProvider::class);
            $app->bootstrap();
            \logger()->info('POSIX custom location');
            self::assertFileExists($path);
        } finally {
            Log::setResolver(null);
            $project->remove();
        }
    }

    public function testRelativeAndForeignLogPathsAreRejected(): void
    {
        $paths = ['relative/custom.log'];
        if (PHP_OS_FAMILY !== 'Windows') {
            $paths[] = 'C:\\logs\\app.log';
            $paths[] = '\\\\server\\share\\app.log';
        }
        foreach ($paths as $path) {
            $project = new TemporaryProject();
            try {
                $project->write('Config/Logging.php', '<?php return ["driver" => "file", "path" => '
                    . var_export($path, true) . '];');
                $app = new Application($project->path());
                $app->register(LoggingServiceProvider::class);
                try {
                    $app->bootstrap();
                    self::fail('Non-native or relative log path was accepted.');
                } catch (\InvalidArgumentException $exception) {
                    self::assertSame('Logging path must be absolute.', $exception->getMessage());
                }
            } finally {
                Log::setResolver(null);
                $project->remove();
            }
        }
    }
}
