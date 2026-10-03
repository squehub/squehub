<?php

use App\Routing\RouteRegistry;
use App\Packages\PackageManager;

/** Legacy router for v1 route files and standalone dispatch. */
class Router
{
    private $routes = [];

    private $namedRoutes = [];

    private $notFoundHandler = null;

    private $middleware = [];

    private $attributes = [];

    private bool $lastRouteMatched = false;

    public function __construct(
        private ?RouteRegistry $registry = null,
        private ?PackageManager $packages = null
    )
    {
    }

    /** Register a v1 route and mirror it into the v2 registry when available. */
    public function add($methods, $uri, $handler, $name = null, $middlewares = [])
    {
        if (isset($this->attributes['prefix'])) {
            $uri = $this->attributes['prefix'] . $uri;
        }

        if (isset($this->attributes['middleware'])) {
            $middlewares = array_merge($middlewares, $this->attributes['middleware']);
        }

        // Standalone instances keep their own matching and dispatch behavior.
        $this->registry?->addLegacy($methods, $uri, $handler, $name, $middlewares);

        if (is_array($methods)) {
            foreach ($methods as $method) {
                $this->routes[strtoupper($method)][$uri] = ['handler' => $handler, 'middlewares' => $middlewares];
            }
        } else {
            $this->routes[strtoupper($methods)][$uri] = ['handler' => $handler, 'middlewares' => $middlewares];
        }

        if ($name) {
            $this->namedRoutes[$name] = $uri;
        }
    }

    /** Apply shared legacy attributes while the callback registers routes. */
    public function group(array $attributes, callable $callback)
    {
        $previousAttributes = $this->attributes;
        $this->attributes = array_merge($this->attributes, $attributes);

        $callback($this);

        $this->attributes = $previousAttributes;
    }

    public function route($name, $params = [])
    {
        if (!isset($this->namedRoutes[$name])) {
            return '#';
        }

        $url = $this->namedRoutes[$name];

        foreach ($params as $key => $value) {
            $url = str_replace("{" . $key . "}", $value, $url);
        }

        return $url;
    }

    public function setNotFoundHandler($callback)
    {
        $this->notFoundHandler = $callback;
        $this->registry?->setLegacyNotFoundHandler(is_callable($callback) ? $callback : null);
    }

    /** Register a status page in the shared web kernel; 404 keeps its legacy API. */
    public function setErrorHandler(int $status, callable $callback): void
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('Error handler status must be between 400 and 599.');
        }
        if ($status === 404) {
            $this->setNotFoundHandler($callback);
            return;
        }
        if ($this->registry === null) {
            throw new LogicException('Error handlers other than 404 require the Application route registry.');
        }
        $this->registry->setErrorHandler($status, $callback);
    }

    public function dispatch($method, $uri, $emit = null)
    {
        $method = strtoupper($method);
        $normalizedUri = $this->normalizeUri($uri);
        $this->lastRouteMatched = false;

        if (isset($this->routes[$method][$normalizedUri])) {
            $route = $this->routes[$method][$normalizedUri];
            $this->lastRouteMatched = true;
            $this->handleRouteWithMiddleware($route['handler'], $route['middlewares'], [], $emit);
        } else {
            foreach ($this->routes[$method] ?? [] as $pattern => $route) {
                $matches = [];
                if (preg_match($this->convertToRegex($pattern), $normalizedUri, $matches)) {
                    array_shift($matches);
                    $this->lastRouteMatched = true;
                    $this->handleRouteWithMiddleware($route['handler'], $route['middlewares'], $matches, $emit);
                    return;
                }
            }

            if (is_callable($this->notFoundHandler)) {
                $this->emit(call_user_func($this->notFoundHandler), $emit);
            } else {
                include BASE_DIR . '/Project/Views/Default/Error/404.php';
            }
        }
    }

    public function lastRouteMatched(): bool
    {
        return $this->lastRouteMatched;
    }

    private function handleRouteWithMiddleware($handler, $middlewares, $params = [], $emit = null)
    {
        // Legacy middleware short-circuits on a truthy response; otherwise the handler runs once.
        foreach ($middlewares as $middleware) {
            if (is_callable($middleware)) {
                $response = call_user_func($middleware, $params, function () {
                    return null;
                });

                if ($response) {
                    $this->emit($response, $emit);
                    return;
                }
            }

            elseif (class_exists($middleware)) {
                $middlewareInstance = new $middleware();

                $response = $middlewareInstance->handle($params, function () {
                    return null;
                });

                if ($response) {
                    $this->emit($response, $emit);
                    return;
                }
            }
        }

        $this->emit($this->callHandlerWithParams($handler, $params), $emit);
    }

    private function emit($value, $emit): void
    {
        if ($emit !== null) {
            $emit($value);
        } else {
            echo $value;
        }
    }

    private function normalizeUri($uri)
    {
        return '/' . trim($uri, '/');
    }

    private function convertToRegex($pattern)
    {
        return '#^' . preg_replace('/{([a-zA-Z0-9_]+)}/', '([^/]+)', $pattern) . '$#';
    }

    private function callHandler($handler)
    {
        if (is_callable($handler)) {
            return call_user_func($handler);
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            list($controller, $action) = explode('@', $handler);
            return $this->callControllerMethod($controller, $action);
        }
        return 'Handler not valid.';
    }

    private function callControllerMethod($controller, $action, $params = [])
    {
        $controllerPath = str_replace(['/', '\\'], '\\', $controller);

        $namespaces = [
            'Project\\Controllers\\' . $controllerPath,
        ];

        foreach ($this->packages?->active() ?? [] as $package) {
            $packageName = $package->name();
            $namespaces[] = 'Project\\Packages\\' . $packageName . '\\Controllers\\' . $controllerPath;
            $namespaces[] = 'Packages\\' . $packageName . '\\Controllers\\' . $controllerPath;
        }
        foreach ($namespaces as $controllerClass) {

            if (class_exists($controllerClass)) {
                $controllerInstance = new $controllerClass();
                if (method_exists($controllerInstance, $action)) {
                    return call_user_func_array([$controllerInstance, $action], $params);
                } else {
                    return "Action '$action' not found in '$controllerClass'.";
                }
            }
        }

        return "Controller '$controller' not found in any known namespaces.";
    }

    private function callHandlerWithParams($handler, $params)
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, $params);
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            list($controller, $action) = explode('@', $handler);
            return $this->callControllerMethod($controller, $action, $params);
        }
        return 'Handler not valid.';
    }
}
