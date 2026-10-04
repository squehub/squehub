<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit\TypedData;

use App\Data\DataMappingException;
use App\Plugins\NestedData;
use App\Plugins\Request;
use App\Plugins\ValidatedData;
use App\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Typed Request mapping preserves the existing Validator's input boundaries. */
final class RequestTypedMappingTest extends TestCase
{
    private const SECRET = 'SQUEHUB_TYPED_REQUEST_SECRET';

    public function testExplicitRulesMapPlainReadonlyDataWithoutAdmittingExtraSources(): void
    {
        $request = new Request('POST', '/people',
            ['role' => 'admin', 'age' => '99'],
            ['name' => 'Ada', 'age' => '28', 'enabled' => 'false',
                'is_admin' => true, 'password_hash' => self::SECRET],
            ['role' => 'owner']);
        $request->setAttribute('route.params', ['role' => 'admin']);

        $rules = ['name' => 'required|string', 'age' => 'required|integer',
            'enabled' => 'required|boolean'];
        self::assertSame(['name' => 'Ada', 'age' => '28', 'enabled' => 'false'],
            $request->validate($rules));
        $value = $request->validatedAs(PersonInput::class, $rules);
        self::assertSame('Ada', $value->name);
        self::assertSame(28, $value->age);
        self::assertFalse($value->enabled);
        self::assertSame('member', $value->role);
    }

    public function testClassOwnedRulesValidateJsonBeforeConstructorRuns(): void
    {
        $request = new Request('POST', '/api/people', [], ['name' => 'ignored'], [], [],
            ['Content-Type' => 'application/json'], [],
            '{"name":"Lin","age":31,"enabled":true,"role":"admin"}');
        $value = $request->validatedAs(RegisteredInput::class);
        self::assertSame('Lin', $value->name);
        self::assertSame(31, $value->age);
        self::assertTrue($value->enabled);
        self::assertSame(1, RegisteredInput::$constructors);

        $invalid = new Request('POST', '/api/people', [], [], [], [],
            ['Content-Type' => 'application/json'], [], '{"name":"Lin","age":"bad"}');
        try {
            $invalid->validatedAs(RegisteredInput::class);
            self::fail('Validation must reject before constructor execution.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('age', $exception->errors());
            self::assertSame(1, RegisteredInput::$constructors);
        }
    }

    public function testMissingRulesAreConfigurationFailureRatherThanUncheckedMapping(): void
    {
        $request = new Request('POST', '/', form: ['name' => 'Ada', 'age' => '28']);
        try {
            $request->validatedAs(PersonInput::class);
            self::fail('A plain class cannot receive unchecked request input.');
        } catch (DataMappingException $exception) {
            self::assertSame('rules_missing', $exception->reason());
            self::assertFalse($exception->isInputFailure());
        }
    }

    public function testMapperInputFailureBecomesSafeFieldValidationErrorWithCause(): void
    {
        foreach ([
            [['age' => self::SECRET], ['age' => 'required']],
            [['age' => ''], ['age' => 'nullable|string']],
            [[], ['age' => 'nullable|integer']],
        ] as [$form, $rules]) {
            $request = new Request('POST', '/', form: $form);
            try {
                $request->validatedAs(AgeInput::class, $rules);
                self::fail('Invalid or missing age must be rejected.');
            } catch (ValidationException $exception) {
                self::assertSame(['age'], array_keys($exception->errors()));
                self::assertInstanceOf(DataMappingException::class, $exception->getPrevious());
                self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
                self::assertStringNotContainsString(self::SECRET,
                    implode(' ', $exception->errors()['age']));
            }
        }
    }

    public function testNestedInputUsesValidatedChildrenAndExplicitAttribute(): void
    {
        $request = new Request('POST', '/', form: [
            'name' => 'Ada',
            'profile' => ['city' => 'Lagos', 'level' => '2', 'secret' => self::SECRET],
            'permissions' => ['root'],
        ]);
        $value = $request->validatedAs(NestedRequestInput::class, [
            'name' => 'required|string',
            'profile' => 'required|array',
            'profile.city' => 'required|string',
            'profile.level' => 'required|integer',
        ]);
        self::assertSame('Ada', $value->name);
        self::assertSame('Lagos', $value->profile->city);
        self::assertSame(2, $value->profile->level);
        self::assertSame(['name' => 'Ada', 'profile' => ['city' => 'Lagos', 'level' => '2']],
            $request->validate([
                'name' => 'required|string', 'profile' => 'required|array',
                'profile.city' => 'required|string', 'profile.level' => 'required|integer',
            ]));
    }

    public function testConstructorFailureRemainsSeparateFromValidation(): void
    {
        $request = new Request('POST', '/', form: ['secret' => self::SECRET]);
        try {
            $request->validatedAs(RejectingInput::class, ['secret' => 'required|string']);
            self::fail('The constructor must reject the value.');
        } catch (DataMappingException $exception) {
            self::assertSame('constructor_failure', $exception->reason());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function testPluginSymbolsAliasCanonicalTypes(): void
    {
        self::assertTrue(class_exists(\App\Plugins\DataMapper::class));
        self::assertTrue(class_exists(\App\Plugins\DataMappingException::class));
        self::assertTrue(class_exists(NestedData::class));
        self::assertTrue(interface_exists(ValidatedData::class));
        $value = \App\Plugins\DataMapper::map(PersonInput::class,
            ['name' => 'Ada', 'age' => '28', 'enabled' => '0']);
        self::assertInstanceOf(PersonInput::class, $value);
        self::assertTrue(is_a(DataMappingException::class,
            \App\Plugins\DataMappingException::class, true));
    }
}

final readonly class PersonInput
{
    public function __construct(public string $name, public int $age,
        public bool $enabled, public string $role = 'member') {}
}

final class RegisteredInput implements ValidatedData
{
    public static int $constructors = 0;

    public function __construct(public string $name, public int $age, public bool $enabled)
    {
        ++self::$constructors;
    }

    public static function rules(): array
    {
        return ['name' => 'required|string', 'age' => 'required|integer',
            'enabled' => 'required|boolean'];
    }
}

final readonly class AgeInput
{
    public function __construct(public int $age) {}
}

final readonly class ProfileInput
{
    public function __construct(public string $city, public int $level) {}
}

final readonly class NestedRequestInput
{
    public function __construct(public string $name,
        #[NestedData] public ProfileInput $profile) {}
}

final readonly class RejectingInput
{
    public function __construct(public string $secret)
    {
        throw new RuntimeException($secret);
    }
}
