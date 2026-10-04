<?php

declare(strict_types=1);

namespace App\Routing;

use App\Api\ApiVersionPolicy;
use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Frontend\SpaFallbackPolicy;
use App\Http\DispatchResult;
use App\Http\Dispatcher;
use App\Http\Exception\NotFoundHttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseNormalizer;
use Throwable;

/** Primary HTTP dispatcher for modern and mirrored legacy routes. */
final class RouteDispatcher implements Dispatcher
{
    private ?Diagnostics $diagnostics = null;

    public function __construct(
        private RouteRegistry $routes,
        private RouteMatcher $matcher,
        private MiddlewarePipeline $middleware,
        private ControllerDispatcher $controllers,
        private ResponseNormalizer $normalizer,
        private Repository $apiConfig
    ) {
    }

    /** The matched route template is safe to record; parameter values are not. */
    public function setDiagnostics(Diagnostics $diagnostics): void
    {
        $this->diagnostics = $diagnostics;
    }

    public function dispatch(Request $request): DispatchResult
    {
        try {
            $matched = $this->diagnostics === null
                ? $this->matcher->match($this->routes, $request)
                : $this->diagnostics->span('http.match',
                    fn () => $this->matcher->match($this->routes, $request));
        } catch (NotFoundHttpException $exception) {
            // Generic route fallbacks have already run. An SPA shell is only a
            // browser-navigation response for an otherwise unmatched request.
            $spa = (new SpaFallbackPolicy($this->apiConfig))->responseFor($request);
            if ($spa !== null) {
                return new DispatchResult($spa);
            }
            $handler = $this->routes->legacyNotFoundHandler();
            if ($handler === null || $request->expectsJson()
                || $request->attribute('_squehub.api_scope') === true) {
                throw $exception;
            }
            $level = ob_get_level();
            ob_start();
            try {
                $value = $handler();
                $output = '';
                while (ob_get_level() > $level) {
                    $output = ob_get_clean() . $output;
                }
                $response = $this->normalizer->normalize(new DispatchResult($value, $output, false));
                // A custom 404 Response may supply headers, but cannot turn an
                // unmatched route into a successful HTTP status accidentally.
                if ($response->status() !== 404) {
                    $response = $response->withStatus(404);
                }
                return new DispatchResult($response);
            } catch (Throwable $failure) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                throw $failure;
            }
        }
        if ($matched->route->apiVersionValue() !== null) {
            (new ApiVersionPolicy($this->apiConfig))->resolve($request, $matched->route);
        }
        $request->setAttribute('route', $matched->route);
        $request->setAttribute('route.params', $matched->parameters);
        $this->diagnostics?->matched($matched->route->nameValue(), $matched->route->uri());
        $destination = fn (Request $current): Response => $this->normalizer->normalize(
            $this->controllers->dispatch($matched->route, $current)
        );
        $response = $this->diagnostics === null
            ? $this->middleware->run($request, $matched->route, $destination)
            : $this->diagnostics->span('http.route',
                fn () => $this->middleware->run($request, $matched->route, $destination),
                ['route_pattern' => $matched->route->uri(),
                    'route_name' => $matched->route->nameValue()]);
        return new DispatchResult($response);
    }
}
