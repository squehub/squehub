<?php

declare(strict_types=1);

namespace App\View;

use RuntimeException;

/**
 * A requested Fragment has no declaration in the existing root View. Its
 * absence has no source location, so structural CompilerException metadata
 * must not invent a line for it.
 */
final class FragmentNotFoundException extends RuntimeException
{
    public function __construct(
        private readonly string $view,
        private readonly string $fragment
    ) {
        parent::__construct(sprintf('Fragment "%s" was not found in View "%s".',
            $fragment, $view));
    }

    public function view(): string { return $this->view; }

    public function fragment(): string { return $this->fragment; }
}
