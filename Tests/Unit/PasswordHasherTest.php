<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Auth\AuthException;
use App\Auth\PasswordHasher;
use App\Config\Repository;
use PHPUnit\Framework\TestCase;

/** Native hashing behavior and technical input limits. */
final class PasswordHasherTest extends TestCase
{
    private function hasher(array $settings = []): PasswordHasher
    {
        return new PasswordHasher(new Repository(['auth' => ['passwords' => array_replace([
            'algorithm' => 'bcrypt', 'options' => ['cost' => 4], 'max_bytes' => 4096,
        ], $settings)]]));
    }

    public function testHashVerifyAndRehash(): void
    {
        $hasher = $this->hasher();
        $hash = $hasher->hash('secret-value');
        self::assertTrue($hasher->verify('secret-value', $hash));
        self::assertFalse($hasher->verify('wrong', $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue($this->hasher(['options' => ['cost' => 5]])->needsRehash($hash));
        $hasher->verifyDummy('wrong');
        $hasher->verifyDummy('wrong');
    }

    public function testBcryptNeverTruncatesPastSeventyTwoBytes(): void
    {
        $hasher = $this->hasher();
        self::assertFalse($hasher->accepts(str_repeat('a', 73)));
        self::assertFalse($hasher->verify(str_repeat('a', 73), $hasher->hash(str_repeat('a', 72))));
        $this->expectException(AuthException::class);
        $hasher->hash(str_repeat('a', 73));
    }

    public function testMaximumAndInvalidOptions(): void
    {
        self::assertFalse($this->hasher(['max_bytes' => 10])->accepts(str_repeat('a', 11)));
        $this->hasher(['max_bytes' => 1])->verifyDummy('x');
        foreach ([
            ['algorithm' => 'sha1'], ['options' => ['unknown' => 1]],
            ['options' => ['cost' => 2]], ['max_bytes' => 0], ['rehash_on_login' => 'yes'],
        ] as $settings) {
            try {
                $this->hasher($settings);
                self::fail('Invalid hashing configuration was accepted.');
            } catch (AuthException) {
                self::assertTrue(true);
            }
        }
    }

    public function testArgon2idIsExplicitlyAvailableOrRejected(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->expectException(AuthException::class);
            $this->hasher(['algorithm' => 'argon2id']);
            return;
        }
        $hasher = $this->hasher(['algorithm' => 'argon2id',
            'options' => ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]]);
        $hash = $hasher->hash(str_repeat('x', 73));
        self::assertTrue($hasher->verify(str_repeat('x', 73), $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }
}
