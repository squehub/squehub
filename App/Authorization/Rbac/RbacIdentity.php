<?php

declare(strict_types=1);

namespace App\Authorization\Rbac;

use App\Auth\Contracts\Authenticatable;
use InvalidArgumentException;

/** A class-scoped, typed identity key shared by both RBAC repositories. */
final readonly class RbacIdentity
{
    private function __construct(
        public string $class,
        public string $kind,
        public string $identifier,
        public string $digest
    ) {
    }

    public static function from(Authenticatable $identity): self
    {
        $class = get_class($identity);
        $id = $identity->authIdentifier();
        if (strlen($class) > 255 || (is_int($id) && $id < 0)
            || (is_string($id) && ($id === '' || strlen($id) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $id) === 1
                || preg_match('//u', $id) !== 1))) {
            throw new InvalidArgumentException('RBAC identity identifier is invalid.');
        }

        // Database-generated IDs may return as int or canonical decimal string.
        // Noncanonical strings such as "007" retain their distinct string type.
        $canonical = is_string($id)
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $id) === 1
            && (string) (int) $id === $id;
        $kind = is_int($id) || $canonical ? 'i' : 's';
        $identifier = (string) $id;
        $digest = hash('sha256', pack('N', strlen($class)) . $class
            . $kind . pack('N', strlen($identifier)) . $identifier);
        return new self($class, $kind, $identifier, $digest);
    }

    public function sameAs(self $other): bool
    {
        return $this->class === $other->class && $this->kind === $other->kind
            && $this->identifier === $other->identifier;
    }
}
