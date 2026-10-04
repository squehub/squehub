<?php

declare(strict_types=1);

namespace App\View\Compiler;

/**
 * Identifies a structural template or static dependency error at its original
 * logical View and source line. Structured fields let tests and diagnostics
 * inspect the error without parsing a formatted message or compiled PHP path.
 */
final class CompilerException extends \RuntimeException
{
    public function __construct(
        private readonly string $view,
        private readonly int $sourceLine,
        private readonly string $reason,
        private readonly ?string $directive = null,
        private readonly array $dependencyChain = []
    ) {
        parent::__construct(sprintf('View "%s", line %d: %s',
            $view, $sourceLine, $reason));
    }

    public function view(): string { return $this->view; }

    public function sourceLine(): int { return $this->sourceLine; }

    /** The explanation is available without parsing the formatted message. */
    public function reason(): string { return $this->reason; }

    public function directive(): ?string { return $this->directive; }

    /** @return list<string> Logical dependency labels in active order. */
    public function dependencyChain(): array { return $this->dependencyChain; }
}
