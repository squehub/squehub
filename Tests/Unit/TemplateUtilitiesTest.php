<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Core\View;
use App\Core\ViewEscaper;
use App\Foundation\Application;
use App\Support\RuntimeContext;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;
use LogicException;
use PHPUnit\Framework\TestCase;
use SqueHub\Tests\Fixtures\TemporaryProject;
use stdClass;
use Stringable;

require_once dirname(__DIR__) . '/Fixtures/TemporaryProject.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Covers the small, global Template Utilities without a compiler dependency. */
final class TemplateUtilitiesTest extends TestCase
{
    public function testJsonPreservesPhpJsonShapesAndDecodesBackToTheOriginalValues(): void
    {
        foreach ([null, true, false, 42, 1.5, 'hello', [1, 'two'],
            ['name' => 'Ada']] as $value) {
            self::assertSame($value, json_decode(\json($value), true, 512, JSON_THROW_ON_ERROR));
        }

        $object = new stdClass();
        $object->name = 'Ada';
        self::assertSame(['name' => 'Ada'],
            json_decode(\json($object), true, 512, JSON_THROW_ON_ERROR));

        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['role' => 'editor'];
            }
        };
        self::assertSame(['role' => 'editor'],
            json_decode(\json($serializable), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJsonKeepsUnicodeValidAndEncodesScriptDangerousCharacters(): void
    {
        $value = 'Ẹ káàbọ̀ こんにちは مرحبا 😀 " \' & < > </script><script>alert(1)</script>';
        $encoded = \json($value);

        self::assertSame($value, json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Ẹ káàbọ̀ こんにちは مرحبا 😀', $encoded);
        self::assertStringContainsString('\\u003C/script\\u003E', $encoded);
        self::assertStringContainsString('\\u0022', $encoded);
        self::assertStringContainsString('\\u0027', $encoded);
        self::assertStringContainsString('\\u0026', $encoded);
        self::assertStringNotContainsString('</script>', $encoded);
        self::assertStringNotContainsString('<script>', $encoded);
    }

    /** @dataProvider unencodableValues */
    public function testJsonFailuresThrowWithoutDumpingTheValue(mixed $value): void
    {
        try {
            \json($value);
            self::fail('Expected JSON encoding to fail.');
        } catch (JsonException $exception) {
            self::assertStringNotContainsString('PRIVATE_SECRET', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function unencodableValues(): iterable
    {
        $recursive = ['secret' => 'PRIVATE_SECRET'];
        $recursive['self'] = &$recursive;
        yield 'recursive' => [$recursive];
        yield 'infinity' => [INF];
        yield 'nan' => [NAN];
        yield 'invalid UTF-8' => ["PRIVATE_SECRET\xB1"];
    }

    public function testClassesPreserveOrderAndWholeEntriesWhileFlatteningNestedValues(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return ' rounded ';
            }
        };

        self::assertSame('button button-primary rounded grid  gap-[var(--x)]',
            \classes([
                ' button ',
                'button-primary' => true,
                'button-disabled' => false,
                'missing' => null,
                ['button', $stringable, ' grid  gap-[var(--x)] '],
                null,
                false,
                '',
            ]));
        self::assertSame('', \classes([]));
        self::assertSame('btn btn-primary btn', \classes(['btn btn-primary', 'btn']));
    }

    public function testClassesRemainOrdinaryTextForViewEscaping(): void
    {
        $entry = '" onmouseover="PRIVATE_SECRET';
        self::assertSame($entry, \classes([$entry]));
        self::assertSame('&quot; onmouseover=&quot;PRIVATE_SECRET',
            ViewEscaper::escape(\classes([$entry])));
    }

    /** @dataProvider invalidClassEntries */
    public function testClassesRejectUnsupportedValuesWithoutDisplayingThem(array $entries): void
    {
        try {
            \classes($entries);
            self::fail('Expected an invalid class entry.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('PRIVATE_SECRET', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidClassEntries(): iterable
    {
        yield 'integer' => [[42]];
        yield 'float' => [[1.5]];
        yield 'true numeric entry' => [[true]];
        yield 'object' => [[new stdClass()]];
        yield 'associative string condition' => [['PRIVATE_SECRET' => 'true']];
        yield 'associative array condition' => [['PRIVATE_SECRET' => []]];
    }

    public function testClassesRejectResourcesWithoutDisplayingTheirContents(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        try {
            fwrite($stream, 'PRIVATE_SECRET');
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('A class entry has an unsupported value type.');
            \classes([$stream]);
        } finally {
            fclose($stream);
        }
    }

    public function testEnvironmentAndDebugReadTheSelectedApplicationWithoutCaching(): void
    {
        $projectA = new TemporaryProject();
        $projectB = new TemporaryProject();
        try {
            $appA = new Application($projectA->path());
            $appA->config()->set('app.env', 'local');
            $appA->config()->set('app.debug', true);
            $appB = new Application($projectB->path());
            $appB->config()->set('app.env', 'production');
            $appB->config()->set('app.debug', false);

            RuntimeContext::select($appA);
            self::assertTrue(\environment('local'));
            self::assertTrue(\environment('testing', 'local'));
            self::assertFalse(\environment('production'));
            self::assertTrue(\debugging());
            self::assertSame($appA, View::application());

            RuntimeContext::select($appB);
            self::assertFalse(\environment('local'));
            self::assertTrue(\environment('production'));
            self::assertFalse(\debugging());
            self::assertSame($appB, View::application());

            RuntimeContext::select($appA);
            self::assertTrue(\environment('local'));
            self::assertTrue(\debugging());
        } finally {
            View::selectContextManager(null);
            $projectA->remove();
            $projectB->remove();
        }
    }

    public function testEnvironmentRejectsNoNamesAndMissingApplicationIsSafe(): void
    {
        try {
            \environment();
            self::fail('Expected at least one environment name.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('environment() requires at least one name.',
                $exception->getMessage());
        }

        View::selectContextManager(null);
        foreach ([static fn (): bool => \environment('local'),
            static fn (): bool => \debugging()] as $check) {
            try {
                $check();
                self::fail('Expected selected Application requirement.');
            } catch (LogicException $exception) {
                self::assertSame('Shared View Context requires a selected Application.',
                    $exception->getMessage());
            }
        }
    }
}
