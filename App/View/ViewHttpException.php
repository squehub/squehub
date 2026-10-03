<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Exception\HttpException;

/**
 * Preserves an intentional HTTP status thrown inside a compiled View without
 * exposing the generated PHP location. The original exception remains the
 * cause; the public message contains only the logical View identity.
 * @internal
 */
final class ViewHttpException extends HttpException
{
    public function __construct(private readonly string $view, HttpException $cause)
    {
        parent::__construct($cause->status(),
            sprintf('View "%s" raised an HTTP error while rendering.', $view),
            $cause->headers(), $cause);
    }

    public function view(): string { return $this->view; }
}
