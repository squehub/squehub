<?php

declare(strict_types=1);

namespace App\View;

use InvalidArgumentException;

/**
 * Rejects a caller-supplied Fragment selection before reading any View. The
 * invalid name is deliberately omitted because it may contain request data.
 */
final class InvalidFragmentNameException extends InvalidArgumentException
{
    public function __construct(private readonly string $view)
    {
        parent::__construct(sprintf('View "%s": Fragment name must be a safe logical name.',
            $view));
    }

    public function view(): string { return $this->view; }
}
