<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Core\ViewEscaper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** Covers the small fixed-fragment helpers without booting a Session or App. */
final class FormHelperTest extends TestCase
{
    public function testMethodFieldUsesTheSameAllowedMethodsAsRequest(): void
    {
        self::assertSame('<input type="hidden" name="_method" value="PUT">',
            \method_field('put'));
        self::assertSame('<input type="hidden" name="_method" value="PATCH">',
            \method_field('PATCH'));
        self::assertSame('<input type="hidden" name="_method" value="DELETE">',
            \method_field('delete'));
    }

    /** @dataProvider invalidMethods */
    public function testMethodFieldRejectsInvalidValuesWithoutEchoingThem(mixed $value): void
    {
        try {
            \method_field($value);
            self::fail('Expected an invalid browser-form method.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Form method must be PUT, PATCH, or DELETE.',
                $exception->getMessage());
            self::assertStringNotContainsString('FORM_SECRET', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'POST' => ['POST'];
        yield 'HEAD' => ['HEAD'];
        yield 'header-like input' => ["DELETE\r\nFORM_SECRET"];
        yield 'secret text' => ['FORM_SECRET'];
        yield 'array' => [['DELETE']];
        yield 'number' => [1];
        yield 'object' => [new \stdClass()];
    }

    public function testCheckedAndSelectedProduceOnlyFixedSafeAttributes(): void
    {
        self::assertSame('checked', \checked(true));
        self::assertSame('', \checked(false));
        self::assertSame('selected', \selected(true));
        self::assertSame('', \selected(false));

        // Ordinary escaped template echo preserves fixed attribute names.
        self::assertSame('checked', ViewEscaper::escape(\checked(true)));
        self::assertSame('selected', ViewEscaper::escape(\selected(true)));
    }

    /** @dataProvider invalidConditions */
    public function testStateHelpersRejectNonBooleanConditions(mixed $condition): void
    {
        foreach (['checked', 'selected'] as $helper) {
            try {
                $helper($condition);
                self::fail('Expected a boolean-only helper failure.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('requires a boolean condition',
                    $exception->getMessage());
                self::assertStringNotContainsString('FORM_SECRET', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidConditions(): iterable
    {
        yield 'string true' => ['true'];
        yield 'string false' => ['false'];
        yield 'secret' => ['FORM_SECRET'];
        yield 'numeric' => [1];
        yield 'array' => [[1]];
        yield 'object' => [new \stdClass()];
        yield 'null' => [null];
    }
}
