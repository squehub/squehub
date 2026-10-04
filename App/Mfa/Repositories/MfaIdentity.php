<?php

declare(strict_types=1);

namespace App\Mfa\Repositories;

use App\Auth\Contracts\Authenticatable;
use InvalidArgumentException;

/** Exact guard, class, and typed identifier scope for persistent MFA state. */
final readonly class MfaIdentity
{
    private function __construct(
        public string $guard,
        public string $class,
        public string $kind,
        public string $identifier,
        public string $digest
    ) {
    }

    public static function from(string $guard, Authenticatable $identity): self
    {
        return self::fromIdentifier($guard, get_class($identity), $identity->authIdentifier());
    }

    public static function fromIdentifier(string $guard, string $class, int|string $identifier): self
    {
        if (strlen($guard) > 64 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $guard) !== 1
            || $class === '' || strlen($class) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $class) === 1 || preg_match('//u', $class) !== 1
            || (is_int($identifier) && $identifier < 0)
            || (is_string($identifier) && ($identifier === '' || strlen($identifier) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1
                || preg_match('//u', $identifier) !== 1))) {
            throw new InvalidArgumentException('MFA identity is invalid.');
        }
        // PDO may return a generated integer ID as its canonical decimal
        // string. Preserve noncanonical strings such as "007" as distinct.
        $canonical = is_string($identifier)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $identifier) === 1
            && (string) (int) $identifier === $identifier;
        $kind = is_int($identifier) || $canonical ? 'i' : 's';
        $value = (string) $identifier;
        $digest = hash('sha256', "squehub-mfa-v1\0"
            . pack('N', strlen($guard)) . $guard
            . pack('N', strlen($class)) . $class
            . $kind . pack('N', strlen($value)) . $value);
        return new self($guard, $class, $kind, $value, $digest);
    }

    public function sameAs(self $other): bool
    {
        return $this->guard === $other->guard && $this->class === $other->class
            && $this->kind === $other->kind && $this->identifier === $other->identifier;
    }
}
