<?php

declare(strict_types=1);

namespace App\Routing;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Http\DispatchResult;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseNormalizer;
use Closure;
use LogicException;
use Throwable;

/** True nested pipeline for modern routes; legacy entries retain their old call contract. */
final class MiddlewarePipeline
{
    private ?Diagnostics $diagnostics = null;

    public function __construct(
        private Container $container,
        private MiddlewareRegistry $registry,
        private ResponseNormalizer $normalizer
    ) {
    }

    /** Per-entry scopes are inclusive; the profiler can derive self time. */
    public function setDiagnostics(Diagnostics $diagnostics): void
    {
        $this->diagnostics = $diagnostics;
    }

    /** @param Closure(Request):Response $destination */
    public function run(Request $request, RouteDefinition $route, Closure $destination): Response
    {
        $next = $destination;
        foreach (array_reverse($route->middlewares()) as $middleware) {
            $downstream = $next;
            $invoke = $route->isLegacy()
                ? fn (Request $current): Response => $this->legacy($middleware, $current, $downstream)
                : fn (Request $current): Response => $this->modern($middleware, $current, $downstream);
            $label = is_object($middleware) ? $middleware::class
                : (is_string($middleware) ? $middleware : 'callable');
            $next = fn (Request $current): Response => $this->diagnostics === null
                ? $invoke($current)
                : $this->diagnostics->span('http.middleware',
                    fn (): Response => $invoke($current), ['middleware' => $label]);
        }
        return $next($request);
    }

    /** Modern entries may be registry names or explicit handler objects. */
    private function modern(string|object $entry, Request $request, Closure $next): Response
    {
        $middleware = is_string($entry)
            ? $this->container->make($this->registry->resolve($entry)) : $entry;
        if (method_exists($middleware, 'handle') && is_callable([$middleware, 'handle'])) {
            $handler = [$middleware, 'handle'];
        } elseif (is_callable($middleware)) {
            $handler = $middleware;
        } else {
            throw new LogicException('Modern middleware needs a handle() method or callable object.');
        }
        [$value, $output] = $this->capture(static fn (): mixed => $handler($request, $next));
        return $this->normalizer->normalize(new DispatchResult($value, $output));
    }

    private function legacy(mixed $middleware, Request $request, Closure $next): Response
    {
        $callable = null;
        if (is_callable($middleware)) {
            $callable = $middleware;
        } elseif (is_string($middleware)) {
            $class = $this->registry->has($middleware) ? $this->registry->resolve($middleware) : $middleware;
            if (class_exists($class)) {
                $instance = $this->container->make($class);
                if (method_exists($instance, 'handle')) {
                    $callable = [$instance, 'handle'];
                }
            }
        } elseif (is_object($middleware) && method_exists($middleware, 'handle')) {
            $callable = [$middleware, 'handle'];
        }
        if ($callable === null) {
            return $next($request); // The old Router ignores unknown legacy middleware.
        }
        $params = array_values($request->route());
        [$value, $output] = $this->capture(static fn (): mixed => $callable($params, static fn (...$ignored): null => null));
        if ($value) {
            return $this->normalizer->normalize(new DispatchResult($value, $output));
        }
        $response = $next($request);
        // A deferred body owns its emitted bytes. Legacy echoed output cannot
        // be prepended without turning a stream or file into a string response.
        return $output === '' || $response->hasDeferredBody()
            ? $response : $response->withContent($output . $response->content());
    }

    /** @return array{mixed,string} */
    private function capture(Closure $callback): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $value = $callback();
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
            return [$value, $output];
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }
}
