<?php

declare(strict_types=1);

namespace App\Routing;

use App\Api\ApiVersionPolicy;
use App\Contributions\ContributionOwner;
use App\Contributions\ContributionRegistry;
use App\Foundation\UrlBasePath;
use Closure;
use InvalidArgumentException;
use LogicException;

/** Ordered route and name registry shared by modern and legacy registration. */
final class RouteRegistry
{
    public function __construct(private ?ContributionRegistry $contributions = null,
        private ?UrlBasePath $urlBasePath = null)
    {
    }

    /** @var list<RouteDefinition> */
    private array $routes = [];
    /** @var array<string, RouteDefinition> */
    private array $byMethodUri = [];
    /** @var array<string, RouteDefinition> Fallbacks never shadow ordinary route identities. */
    private array $fallbackByScope = [];
    /** @var array<string, RouteDefinition> */
    private array $byName = [];
    /** @var list<array{prefix:string,middleware:array,api_version:?string,host:?string}> */
    private array $groups = [];
    /** @var array<int, callable> */
    private array $errorHandlers = [];
    private ?string $packageContext = null;
    /** @var array<int, array{owner:ContributionOwner,source:?string}> */
    private array $routeOwners = [];
    private bool $sourceFilesLoaded = false;

    /** Route files are loaded once per Application, regardless of cache state. */
    public function sourceFilesLoaded(): bool
    {
        return $this->sourceFilesLoaded;
    }

    /** @internal Set only after the normal route loader completes successfully. */
    public function markSourceFilesLoaded(): void
    {
        $this->sourceFilesLoaded = true;
    }

    /** Require Package route files to keep their identities distinct. */
    public function beginPackageContext(string $name): void
    {
        if ($name === '' || $this->packageContext !== null) {
            throw new LogicException('Package route context is already active or invalid.');
        }
        $this->packageContext = $name;
    }

    public function endPackageContext(): void
    {
        $this->packageContext = null;
    }

    public function add(string|array $methods, string $uri, mixed $action, ?string $host = null): RouteDefinition
    {
        return $this->register($methods, $uri, $action, null, [], false, $host);
    }

    /** Register an explicit path-prefix fallback through the normal route pipeline. */
    public function addFallback(string $uri, mixed $action, ?string $host = null): RouteDefinition
    {
        return $this->register('*', $uri, $action, null, [], false, $host, true);
    }

    public function addLegacy(string|array $methods, string $uri, mixed $action, ?string $name, array $middleware): void
    {
        $this->register($methods, $uri, $action, $name, $middleware, true);
    }

    public function get(string $uri, mixed $action, ?string $host = null): RouteDefinition { return $this->add('GET', $uri, $action, $host); }
    public function post(string $uri, mixed $action, ?string $host = null): RouteDefinition { return $this->add('POST', $uri, $action, $host); }
    public function put(string $uri, mixed $action, ?string $host = null): RouteDefinition { return $this->add('PUT', $uri, $action, $host); }
    public function patch(string $uri, mixed $action, ?string $host = null): RouteDefinition { return $this->add('PATCH', $uri, $action, $host); }
    public function delete(string $uri, mixed $action, ?string $host = null): RouteDefinition { return $this->add('DELETE', $uri, $action, $host); }
    public function options(string $uri, mixed $action, ?string $host = null): RouteDefinition { return $this->add('OPTIONS', $uri, $action, $host); }

    public function groupBuilder(): RouteGroup { return new RouteGroup($this); }

    /** Instance API for providers and tests; the static Route API uses RouteGroup. */
    public function group(array $attributes, Closure $callback): void
    {
        $prefix = $attributes['prefix'] ?? '';
        $middleware = $attributes['through'] ?? $attributes['middleware'] ?? [];
        $version = $attributes['api_version'] ?? null;
        $host = $attributes['host'] ?? null;
        if (!is_string($prefix) || (!is_string($middleware) && !is_array($middleware))) {
            throw new InvalidArgumentException('Route group attributes are invalid.');
        }
        if ($version !== null && !is_string($version)) {
            throw new InvalidArgumentException('Route group api_version must be a string.');
        }
        if ($host !== null && !is_string($host)) {
            throw new InvalidArgumentException('Route group host must be a string.');
        }
        $items = is_array($middleware) ? $middleware : [$middleware];
        $builder = $this->groupBuilder()->prefix($prefix);
        if ($items !== []) {
            $builder->through($items);
        }
        if ($version !== null) {
            $builder->apiVersion($version);
        }
        if ($host !== null) {
            $builder->host($host);
        }
        $builder->routes(fn () => $callback($this));
    }

    public function runGroup(string $prefix, array $middleware, Closure $callback, ?string $apiVersion = null,
        ?string $host = null): void
    {
        $parent = $this->groups[count($this->groups) - 1]
            ?? ['prefix' => '', 'middleware' => [], 'api_version' => null, 'host' => null];
        if ($apiVersion !== null) {
            $apiVersion = ApiVersionPolicy::normalizeIdentifier($apiVersion);
        }
        if ($parent['api_version'] !== null && $apiVersion !== null
            && $parent['api_version'] !== $apiVersion) {
            throw new LogicException('Nested route groups cannot declare conflicting API versions.');
        }
        $host = $host === null ? null : (new RoutePattern('/', $host))->host();
        if ($parent['host'] !== null && $host !== null && $parent['host'] !== $host) {
            throw new LogicException('Nested route groups cannot declare conflicting hosts.');
        }
        $this->groups[] = [
            'prefix' => self::joinPaths($parent['prefix'], $prefix),
            'middleware' => [...$parent['middleware'], ...$middleware],
            'api_version' => $apiVersion ?? $parent['api_version'],
            'host' => $host ?? $parent['host'],
        ];
        try {
            $callback();
        } finally {
            array_pop($this->groups);
        }
    }

    public function resource(string $uri, string $controller): array
    {
        if (trim($controller) === '') {
            throw new InvalidArgumentException('Resource controller cannot be empty.');
        }
        $base = self::normalizeUri($uri);
        if ($base === '/') {
            throw new InvalidArgumentException('Resource route needs a path.');
        }
        $name = str_replace('/', '.', trim($base, '/'));
        $item = $base . '/{id}';
        return [
            $this->get($base, [$controller, 'index'])->named($name . '.index'),
            $this->get($item, [$controller, 'show'])->named($name . '.show'),
            $this->post($base, [$controller, 'store'])->named($name . '.store'),
            $this->add(['PUT', 'PATCH'], $item, [$controller, 'update'])->named($name . '.update'),
            $this->delete($item, [$controller, 'destroy'])->named($name . '.destroy'),
        ];
    }

    /** @return list<RouteDefinition> */
    public function all(): array
    {
        return array_values(array_filter($this->routes, static fn (RouteDefinition $route): bool => $route->methods() !== []));
    }

    /**
     * Project registered declarations into passive, scalar inspection data.
     * Route actions, middleware, contracts, and model bindings are described;
     * none is resolved, serialized, dispatched, or invoked here. The same
     * projection serves CLI and Studio, including Closure and uncached routes.
     *
     * @return list<array<string,mixed>>
     */
    public function inspection(): array
    {
        $items = [];
        foreach ($this->all() as $route) {
            $action = $route->action();
            if ($action instanceof Closure) {
                $handlerType = 'Closure';
                $handler = 'Closure';
            } elseif (is_array($action) && count($action) === 2
                && isset($action[0], $action[1])) {
                $handlerType = 'Controller';
                $target = is_object($action[0]) ? $action[0]::class : $action[0];
                $handler = is_string($target) && is_string($action[1])
                    ? $target . '@' . $action[1] : 'unavailable';
            } elseif (is_string($action)) {
                $handlerType = str_contains($action, '@') ? 'Controller' : 'Invokable';
                $handler = $action;
            } elseif (is_object($action)) {
                $handlerType = 'Callable';
                $handler = $action::class;
            } else {
                $handlerType = 'unavailable';
                $handler = 'unavailable';
            }
            // Inspection labels are bounded before they reach a terminal,
            // browser page, or a future machine-readable inspector.
            $handler = self::inspectionLabel($handler);
            $middleware = [];
            foreach ($route->middlewares() as $entry) {
                $middleware[] = self::inspectionLabel($entry instanceof Closure ? 'Closure'
                    : (is_string($entry) ? $entry
                        : (is_object($entry) ? $entry::class : 'unavailable')));
            }
            $optional = [];
            if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\?\}/', $route->uri(), $matches) > 0) {
                $optional = $matches[1];
            }
            $bindings = [];
            foreach ($route->modelBindings() as $parameter => $binding) {
                $bindings[] = ['parameter' => $parameter, 'model' => $binding['model'],
                    'key' => $binding['key']];
            }
            $provenance = $this->routeOwners[spl_object_id($route)] ?? null;
            $items[] = [
                'methods' => $route->methods(), 'path' => $route->uri(),
                'name' => $route->nameValue(), 'handler' => $handler,
                'handler_type' => $handlerType, 'middleware' => $middleware,
                'host' => $route->hostPattern(), 'fallback' => $route->isFallback(),
                'constraints' => $route->pattern()->rawConstraints(),
                'optional_parameters' => $optional, 'model_bindings' => $bindings,
                'api_version' => $route->apiVersionValue(),
                'contract' => $route->contractValue() !== null,
                'owner' => $provenance === null ? null : $provenance['owner']->toArray(),
                'source' => $provenance['source'] ?? null,
            ];
        }
        return $items;
    }

    private static function inspectionLabel(string $label): string
    {
        return $label !== '' && strlen($label) <= 512
            && preg_match('/[\x00-\x1F\x7F]/', $label) !== 1
            ? $label : 'unavailable';
    }

    public function hasName(string $name): bool { return isset($this->byName[$name]); }

    /** Look up a named declaration without generating a mounted URL. */
    public function namedRoute(string $name): ?RouteDefinition { return $this->byName[$name] ?? null; }

    public function setLegacyNotFoundHandler(?callable $handler): void
    {
        $this->setErrorHandler(404, $handler);
    }

    public function legacyNotFoundHandler(): ?callable
    {
        return $this->errorHandler(404);
    }

    /** Register one HTML error callback per HTTP status; the latest wins. */
    public function setErrorHandler(int $status, ?callable $handler): void
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('Error handler status must be between 400 and 599.');
        }
        if ($handler === null) {
            unset($this->errorHandlers[$status]);
        } else {
            $this->errorHandlers[$status] = $handler;
        }
    }

    public function errorHandler(int $status): ?callable
    {
        return $this->errorHandlers[$status] ?? null;
    }

    public function nameRoute(RouteDefinition $route, string $name, bool $replace = false): void
    {
        if ($name === '' || preg_match('/[\x00-\x20\x7F]/', $name)) {
            throw new InvalidArgumentException('Route name must be nonempty and contain no whitespace or controls.');
        }
        if ((!$replace || $this->packageContext !== null)
            && isset($this->byName[$name]) && $this->byName[$name] !== $route) {
            throw new LogicException("Duplicate route name '{$name}'.");
        }
        if ($replace && isset($this->byName[$name]) && $this->byName[$name] !== $route) {
            $this->byName[$name]->setName(null);
        }
        $previous = $route->nameValue();
        if ($previous !== null && $previous !== $name && ($this->byName[$previous] ?? null) === $route) {
            unset($this->byName[$previous]);
        }
        $route->setName($name);
        $this->byName[$name] = $route;
        $this->refreshContribution($route);
    }

    /** Refresh safe metadata after fluent route naming, middleware, or contract changes. */
    public function refreshContribution(RouteDefinition $route): void
    {
        $provenance = $this->routeOwners[spl_object_id($route)] ?? null;
        if ($this->contributions === null || $provenance === null
            || !in_array($route, $this->routes, true)) {
            return;
        }
        if (strlen($route->uri()) > 500) {
            return;
        }
        $controller = null;
        $action = $route->action();
        if (is_string($action)) {
            $controller = explode('@', $action, 2)[0];
        } elseif (is_array($action) && is_string($action[0] ?? null)) {
            $controller = $action[0];
        }
        if ($controller !== null
            && (strlen($controller) > 512
                || preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\z/D', $controller) !== 1)) {
            $controller = null;
        }
        $middleware = [];
        foreach ($route->middlewares() as $item) {
            if (is_string($item) && $item !== '' && strlen($item) <= 128
                && !preg_match('/[\x00-\x1F\x7F]/', $item)) {
                $middleware[] = $item;
            }
        }
        foreach ($route->methods() as $method) {
            $identity = self::identity($method, $route->uri(), $route->hostPattern());
            $registered = $route->isFallback() ? $this->fallbackByScope : $this->byMethodUri;
            if (($registered[$identity] ?? null) !== $route) {
                continue;
            }
            $metadata = ['method' => $method, 'path' => $route->uri()];
            if ($route->hostPattern() !== null) {
                $metadata['host'] = $route->hostPattern();
            }
            if ($route->isFallback()) {
                $metadata['fallback'] = true;
            }
            if ($route->nameValue() !== null && strlen($route->nameValue()) <= 512) {
                $metadata['name'] = $route->nameValue();
            }
            if ($controller !== null) { $metadata['controller'] = $controller; }
            if ($middleware !== [] && strlen(implode(' > ', $middleware)) <= 512) {
                $metadata['middleware'] = implode(' > ', $middleware);
            }
            if ($route->apiVersionValue() !== null && strlen($route->apiVersionValue()) <= 512) {
                $metadata['api_version'] = $route->apiVersionValue();
            }
            if ($route->contractValue()?->operationId() !== null) {
                $operationId = $route->contractValue()->operationId();
                if (strlen($operationId) <= 512) { $metadata['operation_id'] = $operationId; }
            }
            $this->contributions->record('route', $identity,
                $provenance['source'], $metadata, $provenance['owner']);
        }
    }

    public function url(string $name, array $params = []): string
    {
        $route = $this->byName[$name] ?? null;
        if ($route === null) {
            throw new InvalidArgumentException("Named route '{$name}' was not found.");
        }
        $segments = $route->uri() === '/' ? [] : explode('/', trim($route->uri(), '/'));
        $built = [];
        $omittedOptional = false;
        foreach ($segments as $segment) {
            if (preg_match('/\A\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}\z/D', $segment, $match) !== 1) {
                $built[] = $segment;
                continue;
            }
            $key = $match[1];
            $optional = isset($match[2]) && $match[2] === '?';
            if (!array_key_exists($key, $params) || $params[$key] === null) {
                if (!$optional) {
                    throw new InvalidArgumentException("Named route '{$name}' needs parameter '{$key}'.");
                }
                $omittedOptional = true;
                continue;
            }
            if ($omittedOptional) {
                throw new InvalidArgumentException('A later optional route parameter requires the preceding optional parameter.');
            }
            $text = RoutePattern::safePathValue($params[$key]);
            if ($text === null || !$route->pattern()->satisfies($key, $text)) {
                throw new InvalidArgumentException("Route parameter '{$key}' does not satisfy its path constraint or contains an unsafe value.");
            }
            $built[] = rawurlencode($text);
        }
        $path = '/' . implode('/', $built);
        return $this->urlBasePath?->publicPath($path) ?? $path;
    }

    public static function joinPaths(string $prefix, string $uri): string
    {
        if ($prefix === '') {
            return self::normalizeUri($uri);
        }
        return self::normalizeUri(rtrim($prefix, '/') . '/' . ltrim($uri, '/'));
    }

    private function register(string|array $methods, string $uri, mixed $action, ?string $name, array $middleware,
        bool $legacy, ?string $host = null, bool $fallback = false): RouteDefinition
    {
        $methods = is_array($methods) ? $methods : [$methods];
        if ($methods === []) {
            throw new InvalidArgumentException('Route needs at least one HTTP method.');
        }
        $normalizedMethods = [];
        foreach ($methods as $method) {
            if (!is_string($method) || !in_array(strtoupper($method),
                $fallback ? ['*'] : ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                throw new InvalidArgumentException('Unsupported HTTP route method.');
            }
            $normalizedMethods[] = strtoupper($method);
        }
        $normalizedMethods = array_values(array_unique($normalizedMethods));
        $group = !$legacy && $this->groups !== []
            ? $this->groups[count($this->groups) - 1]
            : ['prefix' => '', 'middleware' => [], 'api_version' => null, 'host' => null];
        $uri = self::joinPaths($group['prefix'], $uri);
        $this->validateUri($uri);
        $this->validateAction($action, $legacy);
        $host = $host === null ? null : (new RoutePattern('/', $host))->host();
        if ($group['host'] !== null && $host !== null && $group['host'] !== $host) {
            throw new LogicException('Route host conflicts with its group host.');
        }
        $host ??= $group['host'];
        new RoutePattern($uri, $host, $fallback);

        if ($this->packageContext !== null && $name !== null && $name !== ''
            && isset($this->byName[$name])) {
            throw new LogicException("Duplicate route name '{$name}' while loading Package '{$this->packageContext}'.");
        }

        foreach ($normalizedMethods as $method) {
            $key = self::identity($method, $uri, $host);
            if (($host !== null || $fallback) && strlen($key) > 512) {
                throw new InvalidArgumentException('Route path and host combination is too long for static route identity.');
            }
            $registered = $fallback ? $this->fallbackByScope : $this->byMethodUri;
            if ((!$legacy || $this->packageContext !== null || $fallback)
                && isset($registered[$key])) {
                $suffix = $this->packageContext === null
                    ? '' : " while loading Package '{$this->packageContext}'";
                throw new LogicException("Duplicate route '{$key}'{$suffix}.");
            }
            if ($legacy && isset($this->byMethodUri[$key]) && $this->byMethodUri[$key]->isProtected()) {
                throw new LogicException("Route '{$key}' is reserved by the framework.");
            }
        }
        $route = new RouteDefinition($this, $normalizedMethods, $uri, $action,
            $legacy ? $middleware : [...$group['middleware'], ...$middleware], $legacy,
            $legacy ? null : $group['api_version'], $host, $fallback);
        $owner = $this->contributions?->currentOwner();
        if ($owner !== null) {
            $this->routeOwners[spl_object_id($route)] = [
                'owner' => $owner,
                'source' => $this->contributions?->currentSource(),
            ];
        }
        foreach ($normalizedMethods as $method) {
            $key = self::identity($method, $uri, $host);
            if ($fallback) {
                $this->fallbackByScope[$key] = $route;
                continue;
            }
            if ($legacy && isset($this->byMethodUri[$key])) {
                $old = $this->byMethodUri[$key];
                $old->removeMethod($method);
                if ($old->methods() === [] && $old->nameValue() !== null && ($this->byName[$old->nameValue()] ?? null) === $old) {
                    unset($this->byName[$old->nameValue()]);
                }
            }
            $this->byMethodUri[$key] = $route;
        }
        $this->routes[] = $route;
        if ($owner === null && $this->contributions !== null) {
            foreach ($normalizedMethods as $method) {
                $this->contributions->forget('route', self::identity($method, $uri, $host));
            }
        }
        if ($name !== null && $name !== '') {
            $this->nameRoute($route, $name, $legacy);
        }
        $this->refreshContribution($route);
        return $route;
    }

    private static function normalizeUri(string $uri): string
    {
        return '/' . trim($uri, '/');
    }

    /** A # separator is unambiguous because route URIs and hosts exclude it. */
    private static function identity(string $method, string $uri, ?string $host): string
    {
        return $method . ' ' . $uri . ($host === null ? '' : '#' . $host);
    }

    private function validateUri(string $uri): void
    {
        if (preg_match('/[\x00-\x1F\x7F#]/', $uri)) {
            throw new InvalidArgumentException('Route URI contains an invalid character.');
        }
        $names = [];
        $optional = false;
        foreach (explode('/', trim($uri, '/')) as $segment) {
            if (!str_contains($segment, '{') && !str_contains($segment, '}')) {
                if ($optional || str_contains($segment, '?')) {
                    throw new InvalidArgumentException('Optional route parameters must be trailing and query markers are not route paths.');
                }
                continue;
            }
            if (!preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)(\?)?\}$/', $segment, $match)) {
                throw new InvalidArgumentException('Route parameters must occupy one segment and use a valid name.');
            }
            $isOptional = isset($match[2]) && $match[2] === '?';
            if ($optional && !$isOptional) {
                throw new InvalidArgumentException('Optional route parameters must be trailing.');
            }
            $optional = $optional || $isOptional;
            if (isset($names[$match[1]])) {
                throw new InvalidArgumentException("Duplicate route parameter '{$match[1]}'.");
            }
            $names[$match[1]] = true;
        }
    }

    private function validateAction(mixed $action, bool $legacy): void
    {
        if ($legacy) {
            return; // Preserve the legacy Router's request-time invalid-handler result.
        }
        if ($action instanceof Closure || is_callable($action)) {
            return;
        }
        if (is_array($action) && count($action) === 2 && isset($action[0], $action[1])
            && is_string($action[0]) && $action[0] !== '' && is_string($action[1]) && $action[1] !== '') {
            return;
        }
        if (is_string($action) && trim($action) !== '') {
            if (!str_contains($action, '@') || preg_match('/^[^@]+@[^@]+$/', $action)) {
                return;
            }
        }
        throw new InvalidArgumentException('Route action must be a closure, controller action, or invokable class.');
    }
}
