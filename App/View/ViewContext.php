<?php

declare(strict_types=1);

namespace App\View;

use App\Foundation\Application;
use App\Http\Request;
use App\Plugins\ViewContext as PublicViewContext;

/** One immutable description of the current provider or composer invocation. */
final class ViewContext implements PublicViewContext
{
    public function __construct(
        private readonly Application $application,
        private readonly ?Request $request,
        private readonly ?string $view
    ) {
    }

    public function application(): Application
    {
        return $this->application;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function view(): ?string
    {
        return $this->view;
    }
}
