<?php

declare(strict_types=1);

namespace App\View;

use RuntimeException;

/**
 * Identifies a missing root View without inventing a source line for a file
 * that was never resolved. In-template dependencies use their caller's
 * CompilerException source line instead.
 */
final class ViewNotFoundException extends RuntimeException
{
    public function __construct(
        private readonly string $view,
        private readonly bool $unsafe = false
    ) {
        parent::__construct(sprintf('View "%s" %s.', $view,
            $unsafe ? 'is unsafe or unavailable' : 'was not found'));
    }

    public function view(): string { return $this->view; }

    public function unsafe(): bool { return $this->unsafe; }
}
