<?php

declare(strict_types=1);

namespace App\OAuth;

/** A verified external principal, without provider access or refresh tokens. */
final class ExternalIdentity
{
    /** @var array<string,mixed> */
    private readonly array $claims;

    /** @param array<string,mixed> $claims Verified ID-token claims. */
    public function __construct(
        private readonly string $provider,
        private readonly string $issuer,
        private readonly string $subject,
        array $claims
    ) {
        if ($provider === '' || $issuer === '' || $subject === ''
            || strlen($subject) > 255 || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1
            || ($claims['iss'] ?? null) !== $issuer || ($claims['sub'] ?? null) !== $subject) {
            throw new OAuthException('External identity is invalid.');
        }

        // JSON claims contain only scalars and arrays. Copy nested values so
        // callers cannot mutate a retained object through claims().
        $this->claims = self::copyClaims($claims, 0);
    }

    public function provider(): string { return $this->provider; }
    public function issuer(): string { return $this->issuer; }
    public function subject(): string { return $this->subject; }

    /** @return array<string,mixed> */
    public function claims(): array { return $this->claims; }

    public function claim(string $name): mixed { return $this->claims[$name] ?? null; }

    public function email(): ?string { return $this->stringClaim('email'); }
    public function emailVerified(): bool { return ($this->claims['email_verified'] ?? null) === true; }
    public function name(): ?string { return $this->stringClaim('name'); }
    public function preferredUsername(): ?string { return $this->stringClaim('preferred_username'); }
    public function picture(): ?string { return $this->stringClaim('picture'); }

    private function stringClaim(string $name): ?string
    {
        $value = $this->claims[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<mixed> $claims @return array<mixed> */
    private static function copyClaims(array $claims, int $depth): array
    {
        if ($depth > 32) throw new OAuthException('External identity claims are invalid.');
        $copy = [];
        foreach ($claims as $name => $value) {
            if (is_array($value)) $copy[$name] = self::copyClaims($value, $depth + 1);
            elseif (is_scalar($value) || $value === null) $copy[$name] = $value;
            else throw new OAuthException('External identity claims are invalid.');
        }
        return $copy;
    }
}
