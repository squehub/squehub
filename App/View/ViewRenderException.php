<?php

declare(strict_types=1);

namespace App\View;

use App\Core\ViewException;
use App\View\Assets\AssetException;
use RuntimeException;
use Throwable;

/**
 * Associates a runtime failure with the active logical View. Generated PHP
 * lines are not source lines, so this exception deliberately carries none.
 * The original Throwable remains available to logging and direct tests; its
 * message is not copied when it may contain application data.
 */
final class ViewRenderException extends RuntimeException
{
    private readonly string $category;

    private readonly string $reason;

    public function __construct(private readonly string $view, Throwable $cause)
    {
        [$this->category, $this->reason] = match (true) {
            $cause instanceof ComponentException => ['component', $cause->getMessage()],
            $cause instanceof AssetException => ['asset', $cause->getMessage()],
            $cause instanceof ViewException => ['output', $cause->getMessage()],
            default => ['runtime', 'A PHP expression failed while rendering.'],
        };
        parent::__construct(sprintf('View "%s": %s', $view, $this->reason), 0, $cause);
    }

    public function view(): string { return $this->view; }

    public function category(): string { return $this->category; }

    public function reason(): string { return $this->reason; }
}
