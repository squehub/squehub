<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\Api;

use App\Api\Contract\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The SqueHub declaration vocabulary emits bounded Draft 2020-12 schemas. */
final class ContractSchemaTest extends TestCase
{
    public function testPrimitiveAndBooleanSchemasRetainJsonTypes(): void
    {
        self::assertSame(['type' => 'string'], Schema::string()->toArray());
        self::assertSame(['type' => 'integer'], Schema::integer()->toArray());
        self::assertSame(['type' => 'number'], Schema::number()->toArray());
        self::assertSame(['type' => 'boolean'], Schema::boolean()->toArray());
        self::assertSame(['type' => 'null'], Schema::null()->toArray());
        self::assertTrue(Schema::true()->toArray());
        self::assertFalse(Schema::false()->toArray());
    }

    public function testObjectIsSortedAndRequiredIsIndependentOfNullable(): void
    {
        $base = Schema::object([
            'name' => Schema::string(),
            'id' => Schema::integer(),
        ]);
        $required = $base->required(['name', 'id'])->nullable();
        self::assertSame(['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
        ]], $base->toArray());
        self::assertSame(['object', 'null'], $required->toArray()['type']);
        self::assertSame(['id', 'name'], $required->toArray()['required']);
        self::assertArrayNotHasKey('required', $base->toArray());
    }

    public function testNullablePrimitivesAndReferencesUseRealJsonSchemaSemantics(): void
    {
        self::assertSame(['type' => ['string', 'null']], Schema::string()->nullable()->toArray());
        self::assertSame(['type' => 'null'], Schema::null()->nullable()->toArray());
        self::assertSame(['enum' => ['open', null]], Schema::enum(['open'])->nullable()->toArray());
        self::assertSame(['anyOf' => [
            ['$ref' => '#/components/schemas/User'],
            ['type' => 'null'],
        ]], Schema::ref('User')->nullable()->toArray());
        self::assertArrayNotHasKey('nullable', Schema::string()->nullable()->toArray());
    }

    public function testArrayConstraintsAndNestedBooleanSchemas(): void
    {
        self::assertSame([
            'type' => 'array',
            'items' => false,
            'minItems' => 1,
            'maxItems' => 3,
            'uniqueItems' => true,
        ], Schema::array(Schema::false())->minItems(1)->maxItems(3)->uniqueItems(true)->toArray());
        self::assertSame(['type' => 'object', 'additionalProperties' => false],
            Schema::object()->additionalProperties(false)->toArray());
        self::assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
            Schema::object()->additionalProperties(Schema::integer())->toArray());
    }

    public function testStringNumericAndAnnotationKeywords(): void
    {
        self::assertSame([
            'type' => 'string',
            'description' => 'Public display name',
            'format' => 'email',
            'examples' => ['person@example.test'],
            'default' => 'unknown@example.test',
            'deprecated' => true,
            'readOnly' => true,
            'minLength' => 1,
            'maxLength' => 80,
            'pattern' => '^[^@]+@[^@]+$',
        ], Schema::string()->description('Public display name')->format('email')
            ->examples(['person@example.test'])->default('unknown@example.test')
            ->deprecated()->readOnly()->minLength(1)->maxLength(80)
            ->pattern('^[^@]+@[^@]+$')->toArray());

        self::assertSame([
            'type' => 'number', 'minimum' => 1, 'maximum' => 10,
            'exclusiveMinimum' => 0, 'exclusiveMaximum' => 11,
        ], Schema::number()->minimum(1)->maximum(10)->exclusiveMinimum(0)
            ->exclusiveMaximum(11)->toArray());
    }

    public function testEnumPreservesScalarTypesAndCompositionRetainsReferences(): void
    {
        self::assertSame(['enum' => ['draft', 1, 1.5, true, null]],
            Schema::enum(['draft', 1, 1.5, true, null])->toArray());
        self::assertSame(['oneOf' => [
            ['$ref' => '#/components/schemas/Cat'],
            ['$ref' => '#/components/schemas/Dog'],
        ]], Schema::oneOf([Schema::ref('Cat'), Schema::ref('Dog')])->toArray());
        self::assertSame(['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            Schema::anyOf([Schema::string(), Schema::integer()])->toArray());
        self::assertSame(['allOf' => [['type' => 'object'], ['type' => 'object']]],
            Schema::allOf([Schema::object(), Schema::object()])->toArray());
    }

    public function testNumericBoundsRejectOnlyAnActuallyEmptyDomain(): void
    {
        self::assertSame([
            'type' => 'number', 'minimum' => 5, 'exclusiveMinimum' => 3, 'maximum' => 5,
        ], Schema::number()->minimum(5)->exclusiveMinimum(3)->maximum(5)->toArray());
        self::assertSame([
            'type' => 'integer', 'minimum' => 0.2, 'maximum' => 1.8,
        ], Schema::integer()->minimum(0.2)->maximum(1.8)->toArray());
        $this->expectInvalidDeclaration(static fn () => Schema::integer()->minimum(0.2)->maximum(0.8));
        $this->expectInvalidDeclaration(static fn () => Schema::integer()->exclusiveMinimum(1)->maximum(1));
    }

    public function testDeclarationsAreImmutableAndInputArraysAreCaptured(): void
    {
        $property = Schema::string();
        $properties = ['name' => $property];
        $schema = Schema::object($properties);
        $property = $property->minLength(3);
        $properties['other'] = Schema::boolean();
        self::assertSame(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
        ]], $schema->toArray());
        self::assertSame(['type' => 'string', 'minLength' => 3], $property->toArray());

        $example = ['details' => ['x']];
        $annotated = $schema->examples([$example]);
        $example['details'][0] = 'changed';
        self::assertSame([['details' => ['x']]], $annotated->toArray()['examples']);
        self::assertArrayNotHasKey('examples', $schema->toArray());
    }

    public function testInvalidSchemaCombinationsFailClearly(): void
    {
        foreach ([
            static fn () => Schema::string()->minimum(1),
            static fn () => Schema::array(Schema::string())->required(['x']),
            static fn () => Schema::object(['x' => Schema::string()])->required(['y']),
            static fn () => Schema::integer()->minLength(1),
            static fn () => Schema::string()->readOnly()->writeOnly(),
            static fn () => Schema::boolean()->additionalProperties(true),
            static fn () => Schema::true()->description('impossible'),
            static fn () => Schema::array(Schema::string())->maxItems(1)->minItems(2),
            static fn () => Schema::string()->maxLength(1)->minLength(2),
            static fn () => Schema::number()->minimum(3)->maximum(2),
            static fn () => Schema::number()->exclusiveMinimum(3)->maximum(3),
        ] as $declaration) $this->expectInvalidDeclaration($declaration);
    }

    public function testReferenceAndCompositionInputsAreBounded(): void
    {
        foreach ([
            static fn () => Schema::ref('../secret'),
            static fn () => Schema::ref(''),
            static fn () => Schema::ref(str_repeat('x', 129)),
            static fn () => Schema::object(['' => Schema::string()]),
            static fn () => Schema::enum([]),
            static fn () => Schema::enum(['same', 'same']),
            static fn () => Schema::oneOf([]),
        ] as $declaration) {
            $this->expectInvalidDeclaration($declaration);
        }
    }

    public function testArbitraryObjectsAndNonFiniteNumbersNeverEnterArtifacts(): void
    {
        foreach ([
            static fn () => Schema::string()->default(new \stdClass()),
            static fn () => Schema::string()->examples([INF]),
            static fn () => Schema::enum([NAN]),
            static fn () => Schema::number()->minimum(INF),
        ] as $declaration) {
            $this->expectInvalidDeclaration($declaration);
        }
    }

    /** @param callable():mixed $declaration */
    private function expectInvalidDeclaration(callable $declaration): void
    {
        try {
            $declaration();
            self::fail('An invalid schema declaration was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
