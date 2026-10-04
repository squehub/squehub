<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\ApiVersionPolicy;
use App\Api\CorsPolicy;
use App\Config\Repository;
use App\Diagnostics\Diagnostics;
use App\Auth\AuthManager;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Http\Exception\HttpException;
use App\Http\Exception\NotFoundHttpException;
use App\Routing\RoutePattern;
use App\Support\RuntimeContext;
use App\Translation\TranslationManager;
use App\Validation\ValidatorFactory;
use Closure;
use LogicException;
use Throwable;

/**
 * Turns one request into one response. Global middleware surrounds dispatch,
 * so rejected requests never reach route middleware or controllers.
 */
final class Kernel
{
    /** @var list<object> */
    private array $globalMiddleware = [];
    private ?BrowserNavigation $browserNavigation = null;
    private ?Diagnostics $diagnostics = null;
    private ?AuthManager $auth = null;
    private ?Repository $apiConfig = null;
    private ?Repository $trustedProxyConfig = null;
    private ?Repository $browserSecurityConfig = null;
    /** @var ?Closure(Request, string):bool */
    private ?Closure $corsRouteAccepts = null;

    public function __construct(
        private Dispatcher $dispatcher,
        private ResponseNormalizer $normalizer,
        private ExceptionHandler $exceptions,
        private ?ValidatorFactory $validation = null,
        private ?Application $application = null
    ) {
    }

    /** Register a framework-wide request guard before handling begins. */
    public function addGlobalMiddleware(object $middleware): void
    {
        if (!method_exists($middleware, 'handle')) {
            throw new LogicException('Global middleware needs a handle() method.');
        }
        $this->globalMiddleware[] = $middleware;
    }

    /** Browser navigation is installed only when the session-backed form provider boots. */
    public function setBrowserNavigation(BrowserNavigation $navigation): void
    {
        $this->browserNavigation = $navigation;
    }

    /** The collector is optional for isolated Kernel use and CLI construction. */
    public function setDiagnostics(Diagnostics $diagnostics): void
    {
        $this->diagnostics = $diagnostics;
    }

    /** Clear identity objects between requests handled by one long-lived Application. */
    public function setAuthManager(AuthManager $auth): void
    {
        $this->auth = $auth;
    }

    /**
     * The route probe shares the canonical matcher but never dispatches its
     * result. Only a real browser preflight invokes it.
     *
     * @param callable(Request, string):bool $routeAccepts
     */
    public function setApiPolicies(Repository $config, callable $routeAccepts): void
    {
        $this->apiConfig = $config;
        $this->corsRouteAccepts = Closure::fromCallable($routeAccepts);
    }

    /** The Application's deployment policy is evaluated again for each request. */
    public function setTrustedProxyConfiguration(Repository $config): void
    {
        $this->trustedProxyConfig = $config;
    }

    /** Apply one Application's browser policy after every normal or error response. */
    public function setBrowserSecurityConfiguration(Repository $config): void
    {
        $this->browserSecurityConfig = $config;
    }

    public function handle(Request $request): Response
    {
        // A Request may be reused by tests or a long-lived host. No effective
        // metadata from a previous Application may survive into this handling.
        $request->resetTrustedProxyMetadata();
        $request->resetUrlBasePath();
        $this->auth?->prepareRequest();
        if ($this->application !== null) {
            RuntimeContext::select($this->application);
        }
        $views = $this->application?->views();
        $urlBasePath = $this->application?->container()->make(UrlBasePath::class) ?? new UrlBasePath();
        $viewRequestStarted = false;
        $translation = null;
        $translationScopeStarted = false;
        $observationScope = null;
        $response = null;
        try {
            $views?->beginRequest($request);
            $viewRequestStarted = $views !== null;
            if ($this->diagnostics !== null) $this->diagnostics->begin($request);
            else $request->renewRequestId();
            try {
                $observationScope = $this->diagnostics?->observability()?->begin('http.request',
                    ['method' => $request->method()], $this->diagnostics?->correlation()->current());
            } catch (Throwable) {
                // Telemetry cannot replace the normal HTTP/error boundary.
            }
            $request->setAttribute('_squehub.api_scope', null);
            $request->setAttribute('_squehub.api_version', null);
            $request->setAttribute('_squehub.api_version_vary', null);
            $api = false;
            $cors = null;
            $versions = null;
            try {
                if ($this->application?->container()->has(TranslationManager::class)) {
                    $translation = $this->application->container()->make(TranslationManager::class);
                    // A request starts from this Application's selected locale;
                    // middleware may select another locale without leaking it.
                    $translation->beginScope();
                    $translationScopeStarted = true;
                }
                $proxyPolicy = null;
                if ($this->trustedProxyConfig !== null) {
                    $proxyPolicy = new TrustedProxyPolicy($this->trustedProxyConfig);
                    $request->applyTrustedProxyPolicy($proxyPolicy);
                }
                // The URL mount is independent of proxy host/scheme. Validate
                // raw segments before fallbacks can match an encoded traversal.
                if (($urlBasePath->value() !== '' && !RoutePattern::safeRequestPath($request->rawPath()))
                    || !$request->applyUrlBasePath($urlBasePath)) {
                    throw new NotFoundHttpException();
                }
                // Classification and all later guards use the same effective
                // authority that host routing will see. The host allowlist is
                // separate from which network peers may supply forwarding data.
                $api = $this->exceptions->isApiRequest($request);
                $request->setAttribute('_squehub.api_scope', $api);
                if ($proxyPolicy !== null && !$proxyPolicy->allowsHost(RoutePattern::requestHost($request))) {
                    throw new HttpException(400, 'Bad Request');
                }
                // Bind only after proxy policy and host validation. Reused
                // Applications must not carry a previous token identity forward.
                $this->auth?->beginRequest($request);
                if ($this->apiConfig !== null) {
                    $versions = new ApiVersionPolicy($this->apiConfig);
                    $cors = new CorsPolicy($this->apiConfig);
                }
                // Classification precedes global CSRF and matching, including 404s.
                if ($this->validation !== null) {
                    $request->setValidatorFactory($this->validation);
                }
                $preflight = $cors !== null && $this->corsRouteAccepts !== null
                    ? $cors->preflight($request,
                        fn (string $method): bool => ($this->corsRouteAccepts)($request, $method))
                    : null;
                if ($preflight !== null) {
                    // Valid preflight stops before CSRF, Auth, route middleware,
                    // and controllers; ordinary OPTIONS keeps normal dispatch.
                    $response = $preflight;
                } else {
                    $next = fn (Request $current): Response => $this->normalizer->normalize(
                        $this->dispatcher->dispatch($current)
                    );
                    $diagnostics = $this->diagnostics;
                    $observeGlobalMiddleware = $diagnostics?->observability()?->enabled() === true;
                    foreach (array_reverse($this->globalMiddleware) as $middleware) {
                        $downstream = $next;
                        $label = $observeGlobalMiddleware ? $middleware::class : null;
                        $next = fn (Request $current): Response => !$observeGlobalMiddleware
                            ? $middleware->handle($current, $downstream)
                            : $diagnostics->span('http.middleware',
                                fn (): Response => $middleware->handle($current, $downstream),
                                ['middleware' => $label, 'mode' => 'global']);
                    }
                    $response = $next($request);
                }
                if (!$api) $this->browserNavigation?->remember($request, $response);
            } catch (Throwable $exception) {
                $response = $this->exceptions->render($exception, $request);
            }
            try {
                $response = $versions?->applyVary($response, $request) ?? $response;
                $response = $cors?->decorate($request, $response) ?? $response;
            } catch (Throwable $exception) {
                // Decoration failures are infrastructure errors. Do not retry a
                // broken policy recursively or return an undecorated success.
                $response = $this->exceptions->render($exception, $request);
            }
            // Redirects are constructed throughout the framework. Normalize
            // only internal root targets at the common response boundary.
            $location = $response->header('Location');
            if ($location !== null && $response->status() >= 300 && $response->status() < 400) {
                $response = $response->withHeader('Location', $urlBasePath->publicLocation($location));
            }
            $response = $this->auth?->decorateResponse($response) ?? $response;
            if ($this->browserSecurityConfig !== null) {
                try {
                    $response = (new BrowserSecurityPolicy($this->browserSecurityConfig))
                        ->decorate($request, $response, $api);
                } catch (Throwable $exception) {
                    // A broken policy cannot turn a failed decoration into an
                    // undecorated success, including on long-lived hosts.
                    $response = $this->exceptions->render($exception, $request);
                }
            }
            $response = $this->diagnostics?->finish($response) ?? $response;
            return $api ? $response->withHeader('X-Request-ID', $request->requestId())
                ->withHeader('X-Correlation-ID', $request->requestId()) : $response;
        } finally {
            try {
                $observationScope?->finish(['status' => $response?->status() ?? 500,
                    'status_class' => (string) intdiv($response?->status() ?? 500, 100) . 'xx'],
                    $response === null || $response->status() >= 500);
            } catch (Throwable) {
                // The response and any application exception remain authoritative.
            }
            try {
                if ($viewRequestStarted && $views !== null) $views->endRequest();
            } finally {
                // One cleanup failure must not leave the next request in a
                // previous user's locale or correlation scope.
                try {
                    if ($translationScopeStarted) $translation?->endScope();
                } finally {
                    $this->diagnostics?->correlation()->clear();
                }
            }
        }
    }
}
