<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Foundation\Application;
use App\Http\Request;

/** Read-only context passed to Shared View Context providers and composers. */
interface ViewContext
{
    public function application(): Application;

    public function request(): ?Request;

    public function view(): ?string;
}
