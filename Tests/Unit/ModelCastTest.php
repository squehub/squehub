<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Database\Casts\AttributeCaster as Cast;
use App\Database\Casts\CastException;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Locks down the model cast boundaries without opening a database connection. */
final class ModelCastTest extends TestCase
{
    private const MODEL = 'Project\\Models\\Account';

    public function testScalarCastsPreserveNullAndUseExplicitInputRules(): void
    {
        foreach (['integer', 'float', 'boolean', 'string', 'array', 'date', 'datetime'] as $type) {
            self::assertNull(Cast::fromInput(self::MODEL, 'value', $type, null));
            self::assertNull(Cast::fromStorage(self::MODEL, 'value', $type, null));
            self::assertNull(Cast::toStorage(self::MODEL, 'value', $type, null));
        }

        self::assertSame(10, Cast::fromStorage(self::MODEL, 'value', 'integer', '00010'));
        self::assertSame(PHP_INT_MAX, Cast::fromInput(self::MODEL, 'value', 'integer', (string) PHP_INT_MAX));
        self::assertSame(PHP_INT_MIN, Cast::fromInput(self::MODEL, 'value', 'integer', (string) PHP_INT_MIN));
        self::assertSame(125.0, Cast::fromInput(self::MODEL, 'value', 'float', '1.25e2'));
        self::assertFalse(Cast::fromStorage(self::MODEL, 'value', 'boolean', 'FALSE'));
        self::assertTrue(Cast::fromInput(self::MODEL, 'value', 'boolean', 1));
        self::assertSame(0, Cast::toStorage(self::MODEL, 'value', 'boolean', false));
        self::assertSame('0', Cast::fromInput(self::MODEL, 'value', 'string', false));
        self::assertSame('3.5', Cast::fromInput(self::MODEL, 'value', 'string', 3.5));
        self::assertTrue(Cast::same(self::MODEL, 'value', 'integer', '10', 10));
        self::assertTrue(Cast::same(self::MODEL, 'value', 'boolean', 0, false));
        self::assertFalse(Cast::same(self::MODEL, 'value', 'boolean', false, true));
    }

    public function testInvalidScalarValuesAndOverflowDoNotLeakSubmittedValues(): void
    {
        $invalid = [
            ['integer', '92233720368547758081234567890'],
            ['integer', '1.5'],
            ['integer', 1.0],
            ['integer', ' 2'],
            ['float', INF],
            ['float', NAN],
            ['float', '1x-PRIVATE'],
            ['boolean', 'false-PRIVATE'],
            ['boolean', 2],
            ['string', ['PRIVATE']],
        ];
        foreach ($invalid as [$type, $value]) {
            try {
                Cast::fromInput(self::MODEL, 'secret_value', $type, $value);
                self::fail("{$type} should reject unsupported input.");
            } catch (CastException $exception) {
                self::assertStringContainsString(self::MODEL, $exception->getMessage());
                self::assertStringContainsString('secret_value', $exception->getMessage());
                self::assertStringContainsString($type, $exception->getMessage());
                self::assertStringNotContainsString('PRIVATE', $exception->getMessage());
                self::assertStringNotContainsString('92233720368547758081234567890', $exception->getMessage());
            }
        }

        $this->expectException(CastException::class);
        Cast::assertSupported(self::MODEL, 'value', 'currency');
    }

    public function testJsonArrayRoundTripsUnicodeNestedValuesAndLargeIntegers(): void
    {
        $array = ['theme' => 'étoile', 'items' => [['id' => '001'], true, null], 'number' => '12345678901234567890'];
        $value = Cast::fromInput(self::MODEL, 'settings', 'array', $array);
        $encoded = Cast::toStorage(self::MODEL, 'settings', 'array', $value);
        self::assertIsString($encoded);
        self::assertSame($array, Cast::fromStorage(self::MODEL, 'settings', 'array', $encoded));
        self::assertSame($array, Cast::serialize(self::MODEL, 'settings', 'array', $value));
        self::assertSame('12345678901234567890', Cast::fromStorage(self::MODEL, 'settings', 'array', '{"id":12345678901234567890}')['id']);
        self::assertSame('["001"]', Cast::toStorage(self::MODEL, 'settings', 'array', ['001']));
        self::assertNull(Cast::fromStorage(self::MODEL, 'settings', 'array', 'null'));
        self::assertSame([], Cast::fromStorage(self::MODEL, 'settings', 'array', '{}'));
        self::assertSame([], Cast::fromStorage(self::MODEL, 'settings', 'array', '[]'));
        self::assertTrue(Cast::same(self::MODEL, 'settings', 'array', ['b' => 2, 'a' => 1], ['a' => 1, 'b' => 2]));
        self::assertFalse(Cast::same(self::MODEL, 'settings', 'array', [1, 2], [2, 1]));
    }

    public function testJsonRejectsInvalidStorageAndUnsupportedAssignment(): void
    {
        foreach (['', '{bad}', 'false', '123', '"string"'] as $json) {
            try {
                Cast::fromStorage(self::MODEL, 'settings', 'array', $json);
                self::fail('Invalid JSON-backed array must fail.');
            } catch (CastException $exception) {
                self::assertStringContainsString('settings', $exception->getMessage());
                if ($json !== '') {
                    self::assertStringNotContainsString($json, $exception->getMessage());
                }
            }
        }
        try {
            Cast::fromStorage(self::MODEL, 'settings', 'array', '{bad}');
            self::fail('Malformed JSON must fail.');
        } catch (CastException $exception) {
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
        try {
            Cast::fromInput(self::MODEL, 'settings', 'array', ["\xB1"]);
            self::fail('Invalid UTF-8 must fail.');
        } catch (CastException $exception) {
            self::assertStringNotContainsString("\xB1", $exception->getMessage());
        }
        $recursive = [];
        $recursive['loop'] = &$recursive;
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        foreach ([['object' => new \stdClass()], ['resource' => $resource], $recursive] as $value) {
            try {
                Cast::fromInput(self::MODEL, 'settings', 'array', $value);
                self::fail('Unsupported JSON value must fail.');
            } catch (CastException $exception) {
                self::assertStringContainsString('array', $exception->getMessage());
            }
        }
        fclose($resource);
    }

    public function testDateAndDatetimeAreImmutableStrictAndUseUtcSecondPrecision(): void
    {
        $date = Cast::fromInput(self::MODEL, 'birthday', 'date', '2024-02-29');
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2024-02-29', Cast::toStorage(self::MODEL, 'birthday', 'date', $date));
        self::assertSame('2024-02-29', Cast::serialize(self::MODEL, 'birthday', 'date', $date));

        $localDate = new DateTime('2024-02-29 23:59:59', new DateTimeZone('Pacific/Auckland'));
        self::assertSame('2024-02-29', Cast::toStorage(self::MODEL, 'birthday', 'date', $localDate));

        $local = new DateTime('2024-01-01 23:00:00.987654', new DateTimeZone('America/Los_Angeles'));
        $datetime = Cast::fromInput(self::MODEL, 'seen_at', 'datetime', $local);
        self::assertInstanceOf(DateTimeImmutable::class, $datetime);
        self::assertSame('2024-01-02 07:00:00', Cast::toStorage(self::MODEL, 'seen_at', 'datetime', $datetime));
        self::assertSame('2024-01-02T07:00:00Z', Cast::serialize(self::MODEL, 'seen_at', 'datetime', $datetime));
        $local->modify('+1 year');
        self::assertSame('2024-01-02 07:00:00', Cast::toStorage(self::MODEL, 'seen_at', 'datetime', $datetime));
        self::assertTrue(Cast::same(self::MODEL, 'seen_at', 'datetime', $datetime, '2024-01-02T08:00:00+01:00'));
        self::assertSame('2024-01-02 07:00:00', Cast::toStorage(self::MODEL, 'seen_at', 'datetime', '2024-01-02 07:00:00'));
    }

    public function testStrictDatesRejectOverflowNaturalLanguageAndBadOffsets(): void
    {
        foreach ([
            ['date', '2023-02-29'],
            ['date', 'next Tuesday'],
            ['date', '2024-02-29tail'],
            ['datetime', '2024-02-30 00:00:00'],
            ['datetime', '2024-01-01 25:00:00'],
            ['datetime', '2024-01-01T12:00:00+14:01'],
            ['datetime', '2024-01-01T12:00:00+00:00junk'],
        ] as [$type, $input]) {
            try {
                Cast::fromInput(self::MODEL, 'date_value', $type, $input);
                self::fail("Invalid {$type} should fail.");
            } catch (CastException $exception) {
                self::assertStringContainsString('date_value', $exception->getMessage());
                self::assertStringNotContainsString($input, $exception->getMessage());
            }
        }
    }
}
