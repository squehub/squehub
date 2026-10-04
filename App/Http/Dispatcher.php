<?php

declare(strict_types=1);

namespace App\Http;

/** Resolves a request while preserving handler return values and direct output. */
interface Dispatcher
{
    public function dispatch(Request $request): DispatchResult;
}
