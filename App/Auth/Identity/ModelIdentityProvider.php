<?php

declare(strict_types=1);

namespace App\Auth\Identity;

use App\Auth\AuthException;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Database\Model;
use App\Database\Identifier;
use App\AccountSecurity\Contracts\EmailVerificationIdentityProvider;
use DateTimeImmutable;
use DateTimeZone;

/** Resolves one configured modern Model while retaining ModelQuery's soft-delete policy. */
final class ModelIdentityProvider implements IdentityProvider, EmailVerificationIdentityProvider
{
    /** @param class-string<Model&Authenticatable> $modelClass
     *  @param list<string> $allowedCredentials
     */
    public function __construct(
        private string $modelClass,
        private string $identifierColumn,
        private string $passwordColumn,
        private array $allowedCredentials,
        private ?string $verificationAddressColumn = null,
        private ?string $verifiedAtColumn = null
    ) {
    }

    public function retrieveById(int|string $identifier): ?Authenticatable
    {
        $model = $this->modelClass;
        return $model::query()->filter($this->identifierColumn, $identifier)->first();
    }

    /** @param array<string, int|string> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if ($credentials === []) return null;
        $model = $this->modelClass;
        $query = $model::query();
        // Normal ModelQuery filters combine with AND and retain soft-delete rules.
        foreach ($credentials as $column => $value) {
            if (!is_string($column) || !in_array($column, $this->allowedCredentials, true)
                || (!is_int($value) && !is_string($value))) {
                throw new AuthException('Identity lookup credential is not configured or has an invalid value.');
            }
            Identifier::simple($column);
            $query->filter($column, $value);
        }
        return $query->first();
    }

    public function supports(Authenticatable $identity): bool
    {
        if (!$identity instanceof $this->modelClass || !$identity->exists() || $identity->isDeleted()) return false;
        $key = $identity->getAttribute($this->identifierColumn);
        $id = $identity->authIdentifier();
        return (is_int($key) || is_string($key)) && (string) $key !== ''
            && (string) $key === (string) $id;
    }

    /** A credential rehash writes only the configured hash column, not pending edits or timestamps. */
    public function updatePassword(Authenticatable $identity, #[\SensitiveParameter] string $passwordHash): void
    {
        if (!$this->supports($identity)) throw new AuthException('Identity is not supported by this provider.');
        /** @var Model $identity */
        if (!$identity->persistAttributeOnly($this->passwordColumn, $passwordHash)) {
            throw new AuthException('Password rehash could not update the identity.');
        }
    }

    /** Optional capability is configured per identity source, not required by Authenticatable. */
    private function requireVerification(): void
    {
        if ($this->verificationAddressColumn === null || $this->verifiedAtColumn === null) {
            throw new \App\AccountSecurity\AccountSecurityConfigurationException('Email verification is not configured for this identity provider.');
        }
    }

    public function verificationAddress(Authenticatable $identity): string
    {
        $this->requireVerification();
        if (!$this->supports($identity)) throw new AuthException('Identity is not supported by this provider.');
        /** @var Model $identity */
        $value = $identity->getAttribute($this->verificationAddressColumn);
        if (!is_string($value) || $value === '') {
            throw new \App\AccountSecurity\AccountSecurityConfigurationException('Verification address is missing.');
        }
        return $value;
    }

    public function emailVerified(Authenticatable $identity): bool
    {
        $this->requireVerification();
        if (!$this->supports($identity)) throw new AuthException('Identity is not supported by this provider.');
        /** @var Model $identity */
        return $identity->getAttribute($this->verifiedAtColumn) !== null;
    }

    /** Targeted write leaves unrelated pending Model attributes untouched. */
    public function markEmailVerified(Authenticatable $identity, DateTimeImmutable $verifiedAt): void
    {
        $this->requireVerification();
        if (!$this->supports($identity)) throw new AuthException('Identity is not supported by this provider.');
        /** @var Model $identity */
        $value = $verifiedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if (!$identity->persistAttributeOnly($this->verifiedAtColumn, $value)) {
            throw new AuthException('Email verification could not update the identity.');
        }
    }
}
