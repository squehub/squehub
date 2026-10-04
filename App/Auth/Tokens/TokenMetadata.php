<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use DateTimeImmutable;

/** Safe token listing value; it contains neither secret nor stored digest. */
final readonly class TokenMetadata
{
    /** @param list<string> $abilities */
    public function __construct(
        private string $identifier,
        private string $name,
        private array $abilities,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $lastUsedAt,
        private ?DateTimeImmutable $revokedAt
    ) {
    }

    public static function fromRecord(TokenRecord $record): self
    {
        return new self($record->identifier, $record->name, $record->abilities,
            $record->createdAt, $record->expiresAt, $record->lastUsedAt, $record->revokedAt);
    }

    public function identifier(): string { return $this->identifier; }
    public function name(): string { return $this->name; }
    /** @return list<string> */
    public function abilities(): array { return $this->abilities; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function expiresAt(): ?DateTimeImmutable { return $this->expiresAt; }
    public function lastUsedAt(): ?DateTimeImmutable { return $this->lastUsedAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }

    /** Wildcard grants only this token boundary; application Authorization remains separate. */
    public function allows(string $ability): bool
    {
        return TokenRecord::validAbility($ability)
            && (in_array($ability, $this->abilities, true) || in_array('*', $this->abilities, true));
    }
}
