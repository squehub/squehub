<?php

declare(strict_types=1);

namespace App\Authorization;

/** Evaluates one explicit identity without changing Auth or Session state. */
final readonly class AuthorizationContext
{
    public function __construct(private AuthorizationManager $authorization, private ?object $identity)
    {
    }

    public function allows(string $ability, object|string|null $subject = null): bool
    {
        return $this->authorization->decideFor($this->identity, $ability, $subject)->allowed();
    }

    public function denies(string $ability, object|string|null $subject = null): bool
    {
        return !$this->allows($ability, $subject);
    }

    public function require(string $ability, object|string|null $subject = null): void
    {
        $decision = $this->authorization->decideFor($this->identity, $ability, $subject);
        if ($decision->denied()) throw new AuthorizationException($decision->message() ?? 'This action is not authorized.');
    }
}
