<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Container\Container;
use App\Validation\ValidationRule;
use App\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

final class BoundReferenceRule implements ValidationRule
{
    public function __construct(private ReferencePolicy $policy) {}
    public function validate(string $field, mixed $value, array $data): ?string
    {
        return $this->policy->accepts($value) ? null : 'Reference rejected.';
    }
}

final class ReferencePolicy
{
    public function accepts(mixed $value): bool { return $value === 'pass'; }
}

final class ValidatorTest extends TestCase
{
    public function testValidatedDataIsFilteredNestedAndUnchanged(): void
    {
        $input = ['profile' => ['name' => 'Ada', 'admin' => true], 'items' => [
            0 => ['name' => 'A', 'secret' => 'x'], 2 => ['name' => 'B', 'secret' => 'y'],
        ], 'extra' => 'discard'];
        $result = (new Validator($input))->check([
            'profile' => 'array', 'profile.name' => 'required|string',
            'items' => 'array', 'items.*.name' => 'required|string',
        ]);
        self::assertTrue($result->passes());
        self::assertSame(['profile' => ['name' => 'Ada'], 'items' => [
            0 => ['name' => 'A'], 2 => ['name' => 'B'],
        ]], $result->validated());
        self::assertSame('x', $input['items'][0]['secret']);
    }

    public function testStandaloneHelperUsesTheSameValidator(): void
    {
        $result = \validator(['name' => 'Ada', 'admin' => true])->check(['name' => 'required|string']);
        self::assertSame(['name' => 'Ada'], $result->validated());
    }

    public function testPresenceNullabilityAndMultipleMessages(): void
    {
        $result = (new Validator(['zero' => 0, 'off' => false, 'null' => null, 'age' => 'abc']))->check([
            'zero' => 'required|integer', 'off' => 'required|boolean', 'null' => 'present|nullable|integer',
            'missing' => 'present|required', 'age' => 'integer|min:18',
        ]);
        self::assertTrue($result->fails());
        self::assertSame(2, count($result->errors()['missing']));
        self::assertCount(1, $result->errors()['age']);
        self::assertSame(['zero' => 0, 'off' => false, 'null' => null], $result->validated());
        self::assertNull($result->first('other'));
        self::assertTrue((new Validator(['empty' => []]))->check(['empty' => 'required'])->fails());
    }

    public function testScalarAndComparisonRules(): void
    {
        $result = (new Validator([
            'age' => '25', 'score' => 2.5, 'name' => 'éé', 'roles' => ['a', 'b'],
            'email' => 'a@example.test', 'url' => 'https://example.test', 'password' => 'abc',
            'password_confirmation' => 'abc', 'start' => '2024-01-01', 'end' => '2024-02-01',
        ]))->check([
            'age' => 'integer|min:18|max:30', 'score' => 'numeric|between:2,3',
            'name' => 'string|size:2', 'roles' => 'array|size:2',
            'email' => 'email', 'url' => 'url', 'password' => 'confirmed',
            'start' => 'date|before:end', 'end' => 'date|after:start',
        ]);
        self::assertTrue($result->passes(), json_encode($result->errors()));
        self::assertSame('25', $result->validated()['age']);
        self::assertTrue((new Validator(['temperature' => '-2']))->check([
            'temperature' => 'numeric|between:-5,5',
        ])->passes());
    }

    public function testWildcardErrorsMessagesLabelsAndIsolation(): void
    {
        $input = ['items' => [['quantity' => 0], ['quantity' => 2]], 'first_name' => ''];
        $validator = (new Validator($input))->labels(['first_name' => 'given name']);
        $result = $validator->check([
            'items.*.quantity' => 'required|integer|min:1', 'first_name' => 'required',
        ], ['items.*.quantity.min' => 'Quantity must be positive.']);
        self::assertSame(['Quantity must be positive.'], $result->errors()['items.0.quantity']);
        self::assertSame('The given name field is required.', $result->first('first_name'));
        self::assertSame(['items' => [1 => ['quantity' => 2]]], $result->validated());
        $exact = (new Validator(['email' => 'bad']))->check(['email' => 'email'], [
            'email.email' => 'Enter a valid address.', 'email' => 'Generic.',
        ]);
        self::assertSame('Enter a valid address.', $exact->first('email'));
    }

    public function testCustomRuleObjectAndContainerResolvedClass(): void
    {
        $container = new Container();
        $rule = new class implements ValidationRule {
            public function validate(string $field, mixed $value, array $data): ?string
            {
                return $value === 'pass' ? null : 'Reference rejected.';
            }
        };
        $result = (new Validator(['reference' => 'fail'], $container))->check(['reference' => [$rule]]);
        self::assertSame('Reference rejected.', $result->first('reference'));
        $container->instance(ReferencePolicy::class, new ReferencePolicy());
        $classRule = (new Validator(['reference' => 'pass'], $container))->check([
            'reference' => [BoundReferenceRule::class],
        ]);
        self::assertTrue($classRule->passes());
    }

    public function testInvalidDefinitionsFailBeforeAnyValidation(): void
    {
        foreach (['unknown', 'between:1', 'between:5,1', 'regex:[', 'unique:users,evil-name'] as $definition) {
            try {
                (new Validator(['value' => 'x']))->check(['value' => $definition]);
                self::fail("Expected rejection for {$definition}");
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testRegexArrayFormPreservesPipe(): void
    {
        $result = (new Validator(['code' => 'abc']))->check(['code' => ['string', 'regex:/^(abc|def)$/']]);
        self::assertTrue($result->passes());
    }

    public function testRuleFailuresAndEmptyValues(): void
    {
        $result = (new Validator([
            'required' => '', 'present' => null, 'integer' => '12.5', 'numeric' => INF,
            'boolean' => 'yes', 'array' => 'x', 'email' => 'bad', 'url' => 'ftp://example.test',
            'size' => 'long', 'choice' => 'guest', 'excluded' => 'admin',
            'same' => 'x', 'other' => 'y', 'different' => 'x',
            'password' => 'secret', 'password_confirmation' => 'wrong',
            'date' => '2024-02-30', 'before' => '2024-03-01', 'after' => '2024-01-01',
        ]))->check([
            'required' => 'required', 'present' => 'present', 'integer' => 'integer',
            'numeric' => 'numeric', 'boolean' => 'boolean', 'array' => 'array',
            'email' => 'email', 'url' => 'url', 'size' => 'max:2',
            'choice' => 'in:admin,user', 'excluded' => 'not_in:admin,user',
            'same' => 'same:other', 'different' => 'different:same',
            'password' => 'confirmed', 'date' => 'date',
            'before' => 'date|before:2024-02-01', 'after' => 'date|after:2024-02-01',
        ]);
        foreach (['required', 'integer', 'numeric', 'boolean', 'array', 'email', 'url', 'size',
            'choice', 'excluded', 'same', 'different', 'password', 'date', 'before', 'after'] as $field) {
            self::assertArrayHasKey($field, $result->errors());
        }
        self::assertArrayNotHasKey('present', $result->errors());
        self::assertTrue((new Validator(['choice' => ['admin']]))->check([
            'choice' => 'not_in:admin',
        ])->fails());
    }

    public function testMissingNestedParentAndEmptyWildcard(): void
    {
        $missing = (new Validator([]))->check(['profile.email' => 'required|email']);
        self::assertArrayHasKey('profile.email', $missing->errors());
        $empty = (new Validator(['items' => []]))->check([
            'items' => 'array', 'items.*.name' => 'required|string',
        ]);
        self::assertTrue($empty->passes());
        self::assertSame(['items' => []], $empty->validated());
    }

    public function testCustomRuleExceptionsPropagate(): void
    {
        $rule = new class implements ValidationRule {
            public function validate(string $field, mixed $value, array $data): ?string
            {
                throw new \RuntimeException('Custom rule infrastructure failed.');
            }
        };
        $this->expectException(\RuntimeException::class);
        (new Validator(['value' => 'x']))->check(['value' => [$rule]]);
    }
}
