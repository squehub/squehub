<?php

declare(strict_types=1);

namespace App\Http;

use Router;
use Throwable;

/** Isolates the old Router's echo behavior until routing is modernized. */
final class LegacyRouterDispatcher implements Dispatcher
{
    public function __construct(private Router $router)
    {
    }

    public function dispatch(Request $request): DispatchResult
    {
        // Legacy handlers may echo directly or leave nested buffers open.
        $level = ob_get_level();
        ob_start();
        $value = null;
        try {
            $this->router->dispatch(
                $request->method(),
                $request->path(),
                static function (mixed $result) use (&$value): void { $value = $result; }
            );
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
            return new DispatchResult($value, $output, $this->router->lastRouteMatched());
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
