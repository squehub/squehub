<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Api;

use App\Api\Contract\ContractException;
use App\Api\Contract\ResponseSchemaValidator;
use App\Api\Contract\Schema;
use PHPUnit\Framework\TestCase;

/** Response schemas validate real JSON types without retaining body values. */
final class ResponseSchemaValidatorTest extends TestCase
{
    private ResponseSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ResponseSchemaValidator();
    }

    public function testValidNestedResponseAndWrongPrimitiveType(): void
    {
        $schema = Schema::object([
            'data' => Schema::object([
                'id' => Schema::integer(),
                'name' => Schema::string(),
            ])->required(['id', 'name']),
        ])->required(['data']);

        self::assertNull($this->validator->validate(
            self::decode('{"data":{"id":42,"name":"Ada"}}'), $schema->toArray()));
        self::assertSame([
            'path' => '$.data.id', 'expected' => 'integer', 'actual' => 'string',
        ], $this->validator->validate(
            self::decode('{"data":{"id":"42","name":"Ada"}}'), $schema->toArray()));
    }

    public function testMissingRequiredPropertyHasSafeStructuralPath(): void
    {
        $schema = Schema::object([
            'data' => Schema::object([
                'email' => Schema::string(),
            ])->required(['email']),
        ])->required(['data']);

        self::assertSame([
            'path' => '$.data.email', 'expected' => 'required property', 'actual' => 'missing',
        ], $this->validator->validate(self::decode('{"data":{}}'), $schema->toArray()));
    }

    public function testNullableEnumAndObjectArrayDistinction(): void
    {
        $schema = Schema::object([
            'status' => Schema::enum(['open', 'closed'])->nullable(),
        ])->required(['status']);
        self::assertNull($this->validator->validate(
            self::decode('{"status":null}'), $schema->toArray()));
        self::assertNull($this->validator->validate(
            self::decode('{"status":"open"}'), $schema->toArray()));
        self::assertSame('enum', $this->validator->validate(
            self::decode('{"status":"unknown"}'), $schema->toArray())['expected']);

        self::assertNull($this->validator->validate(self::decode('{}'), Schema::object()->toArray()));
        self::assertSame('array', $this->validator->validate(
            self::decode('[]'), Schema::object()->toArray())['actual']);
        self::assertSame('object', $this->validator->validate(
            self::decode('{}'), Schema::array(Schema::true())->toArray())['actual']);
    }

    public function testArrayItemsAndComposition(): void
    {
        $items = Schema::array(Schema::integer())->minItems(2)->maxItems(3)->uniqueItems(true);
        self::assertNull($this->validator->validate(self::decode('[1,2]'), $items->toArray()));
        self::assertSame('minItems', $this->validator->validate(
            self::decode('[1]'), $items->toArray())['expected']);
        self::assertSame('integer', $this->validator->validate(
            self::decode('[1,"x"]'), $items->toArray())['expected']);
        self::assertSame('uniqueItems', $this->validator->validate(
            self::decode('[1,1]'), $items->toArray())['expected']);

        $one = Schema::oneOf([Schema::integer(), Schema::string()]);
        self::assertNull($this->validator->validate(self::decode('3'), $one->toArray()));
        self::assertSame('oneOf', $this->validator->validate(
            self::decode('false'), $one->toArray())['expected']);
        $any = Schema::anyOf([Schema::boolean(), Schema::string()]);
        self::assertNull($this->validator->validate(self::decode('true'), $any->toArray()));
        self::assertSame('anyOf', $this->validator->validate(
            self::decode('3'), $any->toArray())['expected']);
        $all = Schema::allOf([Schema::string()->minLength(3), Schema::string()->pattern('^a')]);
        self::assertNull($this->validator->validate(self::decode('"abc"'), $all->toArray()));
        self::assertSame('pattern', $this->validator->validate(
            self::decode('"bcd"'), $all->toArray())['expected']);
    }

    public function testLocalAndRecursiveComponentReferences(): void
    {
        $node = Schema::object([
            'value' => Schema::integer(),
            'child' => Schema::ref('Node')->nullable(),
        ])->required(['value', 'child']);
        $components = ['Node' => $node->toArray()];
        $reference = Schema::ref('Node')->toArray();

        self::assertNull($this->validator->validate(
            self::decode('{"value":1,"child":{"value":2,"child":null}}'),
            $reference, $components));
        self::assertSame([
            'path' => '$.child', 'expected' => 'anyOf', 'actual' => 'object',
        ], $this->validator->validate(
            self::decode('{"value":1,"child":{"value":"bad","child":null}}'),
            $reference, $components));
    }

    public function testAdditionalPropertiesFollowTheDeclaredPolicyWithoutLeakingFields(): void
    {
        $open = Schema::object(['id' => Schema::integer()])->required(['id']);
        $closed = $open->additionalProperties(false);
        $body = self::decode('{"id":1,"secret":"SQUEHUB_RESPONSE_SECRET_DO_NOT_LEAK"}');

        self::assertNull($this->validator->validate($body, $open->toArray()));
        $finding = $this->validator->validate($body, $closed->toArray());
        self::assertSame([
            'path' => '$', 'expected' => 'declared properties', 'actual' => 'additional property',
        ], $finding);
        self::assertStringNotContainsString('SQUEHUB_RESPONSE_SECRET_DO_NOT_LEAK', json_encode($finding));

        $typedExtras = Schema::object()->additionalProperties(Schema::integer());
        self::assertNull($this->validator->validate(
            self::decode('{"extra":1}'), $typedExtras->toArray()));
        self::assertSame('$', $this->validator->validate(
            self::decode('{"SQUEHUB_RESPONSE_SECRET_DO_NOT_LEAK":"not-an-integer"}'),
            $typedExtras->toArray())['path']);
        // A short, identifier-shaped response key is still private data when
        // it was never declared as a property by the public contract.
        $shortSecret = $this->validator->validate(
            self::decode('{"privateToken":"not-an-integer"}'), $typedExtras->toArray());
        self::assertSame('$', $shortSecret['path']);
        self::assertStringNotContainsString('privateToken', json_encode($shortSecret));
    }

    public function testBoundsPatternsFormatsAndAnnotations(): void
    {
        $email = Schema::string()->format('email')->minLength(3)->maxLength(80);
        self::assertNull($this->validator->validate(self::decode('"a@example.test"'), $email->toArray()));
        self::assertSame('format', $this->validator->validate(
            self::decode('"invalid"'), $email->toArray())['expected']);

        $number = Schema::number()->exclusiveMinimum(1)->maximum(5);
        self::assertNull($this->validator->validate(self::decode('2.5'), $number->toArray()));
        self::assertSame('exclusiveMinimum', $this->validator->validate(
            self::decode('1'), $number->toArray())['expected']);

        $annotated = Schema::object([
            'name' => Schema::string()->description('Public')->default('Ada')
                ->examples([['$ref' => 'illustrative, not a schema reference']]),
        ])->deprecated();
        self::assertNull($this->validator->validate(self::decode('{"name":"Ada"}'), $annotated->toArray()));
    }

    public function testExternalAndUnresolvedReferencesFailBeforeOpisCanResolveThem(): void
    {
        foreach ([
            ['$ref' => 'file:///private/schema.json'],
            ['$ref' => 'https://example.test/schema.json'],
            Schema::ref('Missing')->toArray(),
            ['$id' => 'https://example.test/schema.json', 'type' => 'object'],
        ] as $schema) {
            try {
                $this->validator->validate(self::decode('{}'), $schema);
                self::fail('Unsafe schema reference was accepted.');
            } catch (ContractException $exception) {
                self::assertStringNotContainsString('example.test', $exception->getMessage());
                self::assertStringNotContainsString('/private/', $exception->getMessage());
            }
        }
    }

    public function testRejectedValuesAndRepeatRunsHaveSafeStableFindings(): void
    {
        $schema = Schema::object([
            'name' => Schema::string(),
        ])->required(['name']);
        $body = self::decode('{"name":123,"token":"SQUEHUB_RESPONSE_SECRET_DO_NOT_LEAK"}');
        $first = $this->validator->validate($body, $schema->toArray());
        self::assertSame($first, $this->validator->validate($body, $schema->toArray()));
        self::assertStringNotContainsString('SQUEHUB_RESPONSE_SECRET_DO_NOT_LEAK', json_encode($first));

        $this->expectException(ContractException::class);
        $this->validator->validate(['name' => 'Ada'], $schema->toArray());
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }
}
