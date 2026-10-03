<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\TypedData;

use App\Data\DataMapper;
use App\Data\DataMappingException;
use App\Database\Model;
use App\Plugins\NestedData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Verifies constructor-only hydration and strict, non-leaking type conversion. */
final class DataMapperTest extends TestCase
{
    private const SECRET = 'SQUEHUB_TYPED_DATA_SECRET';

    public function testReadonlyAndOrdinaryClassesUseDeclaredFieldsAndDefaultsOnly(): void
    {
        $input = ['name' => 'Ada', 'age' => '28', 'is_admin' => true, 'internal_id' => 40];
        $value = DataMapper::map(ReadonlyPersonData::class, $input);
        self::assertInstanceOf(ReadonlyPersonData::class, $value);
        self::assertSame('Ada', $value->name);
        self::assertSame(28, $value->age);
        self::assertNull($value->nickname);
        self::assertSame('user', $value->role);
        self::assertEquals(new ReadonlyPersonData('Ada', 28), $value);
        self::assertSame($input, ['name' => 'Ada', 'age' => '28', 'is_admin' => true, 'internal_id' => 40]);

        $ordinary = DataMapper::map(OrdinaryPersonData::class, ['name' => 'Lin', 'enabled' => 'false']);
        self::assertSame('Lin', $ordinary->name());
        self::assertFalse($ordinary->enabled());
        self::assertNotSame($ordinary, DataMapper::map(OrdinaryPersonData::class,
            ['name' => 'Lin', 'enabled' => 'false']));
    }

    public function testSupportedScalarConversionsAreExactAndBounded(): void
    {
        $value = DataMapper::map(ScalarData::class, [
            'text' => '', 'integer' => '-42', 'decimal' => '1.25e2',
            'enabled' => '0', 'items' => ['one', 'two'],
        ]);
        self::assertSame('', $value->text);
        self::assertSame(-42, $value->integer);
        self::assertSame(125.0, $value->decimal);
        self::assertFalse($value->enabled);
        self::assertSame(['one', 'two'], $value->items);

        foreach ([true, 1, '1', 'true'] as $yes) {
            self::assertTrue(DataMapper::map(BooleanData::class, ['enabled' => $yes])->enabled);
        }
        foreach ([false, 0, '0', 'false'] as $no) {
            self::assertFalse(DataMapper::map(BooleanData::class, ['enabled' => $no])->enabled);
        }
        self::assertSame(0, DataMapper::map(IntegerData::class, ['number' => '0'])->number);
        self::assertSame(PHP_INT_MAX,
            DataMapper::map(IntegerData::class, ['number' => (string) PHP_INT_MAX])->number);
        self::assertSame(1.0, DataMapper::map(FloatData::class, ['number' => 1])->number);
        self::assertSame(0.0, DataMapper::map(FloatData::class, ['number' => '0'])->number);
    }

    public function testAmbiguousOrInvalidScalarsFailWithoutExposingValues(): void
    {
        $cases = [
            [IntegerData::class, ['number' => '001']],
            [IntegerData::class, ['number' => '+1']],
            [IntegerData::class, ['number' => '1.0']],
            [IntegerData::class, ['number' => 1.5]],
            [IntegerData::class, ['number' => (string) PHP_INT_MAX . '0']],
            [IntegerData::class, ['number' => '']],
            [FloatData::class, ['number' => '01.2']],
            [FloatData::class, ['number' => 'INF']],
            [FloatData::class, ['number' => INF]],
            [FloatData::class, ['number' => PHP_INT_MAX]],
            [FloatData::class, ['number' => (string) PHP_INT_MAX]],
            [BooleanData::class, ['enabled' => 'yes']],
            [BooleanData::class, ['enabled' => 'FALSE']],
            [BooleanData::class, ['enabled' => 2]],
            [StringData::class, ['text' => 1]],
            [StringData::class, ['text' => null]],
            [ArrayData::class, ['items' => self::SECRET]],
        ];
        foreach ($cases as [$class, $input]) {
            try {
                DataMapper::map($class, $input);
                self::fail('Invalid conversion must not reach the constructor.');
            } catch (DataMappingException $exception) {
                self::assertSame('invalid_type', $exception->reason());
                self::assertTrue($exception->isInputFailure());
                self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            }
        }
    }

    public function testBackedEnumsRequireAValidBackingValue(): void
    {
        $string = DataMapper::map(EnumData::class, ['status' => 'active', 'rank' => '2']);
        self::assertSame(PersonStatus::Active, $string->status);
        self::assertSame(PersonRank::Senior, $string->rank);
        self::assertSame(PersonRank::Junior,
            DataMapper::map(EnumData::class, ['status' => 'active', 'rank' => 1])->rank);
        $this->assertFailure('invalid_enum', 'status', static fn () =>
            DataMapper::map(EnumData::class, ['status' => self::SECRET, 'rank' => 1]));
        $this->assertFailure('invalid_type', 'rank', static fn () =>
            DataMapper::map(EnumData::class, ['status' => 'active', 'rank' => '02']));
    }

    public function testNestedMappingNeedsExplicitAttributeAndRetainsFieldPaths(): void
    {
        $value = DataMapper::map(NestedPersonData::class, [
            'name' => 'Ada', 'address' => ['city' => 'Lagos', 'postcode' => '100001',
                'password_hash' => self::SECRET],
            'password_hash' => self::SECRET,
        ]);
        self::assertSame('Lagos', $value->address->city);
        self::assertSame(100001, $value->address->postcode);
        self::assertEquals(new AddressData('Lagos', 100001), $value->address);
        $this->assertFailure('invalid_type', 'address.postcode', static fn () =>
            DataMapper::map(NestedPersonData::class,
                ['name' => 'Ada', 'address' => ['city' => 'Lagos', 'postcode' => self::SECRET]]));
        $this->assertFailure('invalid_type', 'address', static fn () =>
            DataMapper::map(NestedPersonData::class, ['name' => 'Ada', 'address' => self::SECRET]));
        $this->assertFailure('unsupported_type', 'address', static fn () =>
            DataMapper::map(UnmarkedNestedData::class,
                ['address' => ['city' => 'Lagos', 'postcode' => '100001']]));
    }

    public function testMissingFieldAndUnsupportedDefinitionsFailClearly(): void
    {
        $this->assertFailure('missing_field', 'age', static fn () =>
            DataMapper::map(ReadonlyPersonData::class, ['name' => 'Ada']));
        $this->assertFailure('unsupported_type', 'dependency', static fn () =>
            DataMapper::map(ServiceDependencyData::class, ['email' => 'ada@example.test']));
        $this->assertFailure('unsupported_type', 'value', static fn () =>
            DataMapper::map(UnionData::class, ['value' => self::SECRET]));
        $this->assertFailure('invalid_nested', 'value', static fn () =>
            DataMapper::map(InvalidNestedData::class, ['value' => self::SECRET]));
        $this->assertFailure('invalid_class', null, static fn () =>
            DataMapper::map(PersonModel::class, ['name' => 'Ada']));
        $this->assertFailure('invalid_class', null, static fn () =>
            DataMapper::map(\DateTimeImmutable::class, ['datetime' => 'now']));
        /** @var class-string<object> $missing */
        $missing = implode('\\', ['SqueHub', 'MissingDataClass']);
        $this->assertFailure('invalid_class', null, static fn () =>
            DataMapper::map($missing, []));
    }

    public function testConstructorInvariantsPreserveCauseWithoutPublishingItsMessage(): void
    {
        try {
            DataMapper::map(InvariantData::class, ['token' => self::SECRET]);
            self::fail('Constructor must reject the domain invariant.');
        } catch (DataMappingException $exception) {
            self::assertSame('constructor_failure', $exception->reason());
            self::assertFalse($exception->isInputFailure());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame(self::SECRET, $exception->getPrevious()->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    private function assertFailure(string $reason, ?string $field, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a data mapping failure.');
        } catch (DataMappingException $exception) {
            self::assertSame($reason, $exception->reason());
            self::assertSame($field, $exception->field());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }
}

final readonly class ReadonlyPersonData
{
    public function __construct(public string $name, public int $age,
        public ?string $nickname = null, public string $role = 'user') {}
}

final class OrdinaryPersonData
{
    public function __construct(private string $name, private bool $enabled) {}
    public function name(): string { return $this->name; }
    public function enabled(): bool { return $this->enabled; }
}

final readonly class ScalarData
{
    public function __construct(public string $text, public int $integer,
        public float $decimal, public bool $enabled, public array $items) {}
}

final readonly class BooleanData
{
    public function __construct(public bool $enabled) {}
}

final readonly class IntegerData
{
    public function __construct(public int $number) {}
}

final readonly class FloatData
{
    public function __construct(public float $number) {}
}

final readonly class StringData
{
    public function __construct(public string $text) {}
}

final readonly class ArrayData
{
    public function __construct(public array $items) {}
}

enum PersonStatus: string { case Active = 'active'; case Inactive = 'inactive'; }
enum PersonRank: int { case Junior = 1; case Senior = 2; }

final readonly class EnumData
{
    public function __construct(public PersonStatus $status, public PersonRank $rank) {}
}

final readonly class AddressData
{
    public function __construct(public string $city, public int $postcode) {}
}

final readonly class NestedPersonData
{
    public function __construct(public string $name,
        #[NestedData] public AddressData $address) {}
}

final readonly class UnmarkedNestedData
{
    public function __construct(public AddressData $address) {}
}

final readonly class InvalidNestedData
{
    public function __construct(#[NestedData] public string $value) {}
}

final readonly class ServiceDependencyData
{
    public function __construct(public string $email, public \stdClass $dependency) {}
}

final readonly class UnionData
{
    public function __construct(public string|int $value) {}
}

final class PersonModel extends Model
{
}

final readonly class InvariantData
{
    public function __construct(public string $token)
    {
        throw new RuntimeException($token);
    }
}
