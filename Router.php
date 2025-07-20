<?php
// Router.php

/**
 * Router class for managing application routes.
 */
class Router
{
    /**
     * @var array $routes The collection of defined routes.
     */
    private $routes = [];

    /**
     * @var array $namedRoutes The collection of named routes.
     */
    private $namedRoutes = [];

    /**
     * @var callable|null $notFoundHandler Custom 404 handler.
     */
    private $notFoundHandler = null;

    /**
     * @var array $middleware The collection of middleware handlers for routes.
     */
    private $middleware = [];

    /**
     * @var array $attributes The current group attributes (prefix, middleware, etc.).
     */
    private $attributes = [];

    /**
     * Add a new route to the router.
     * Supports both callback and controller-based routes.
     *
     * @param string|array $methods The HTTP method(s) (e.g., 'GET', 'POST' or ['GET', 'POST']). 
     * @param string $uri The URI path (e.g., '/about', '/contact').
     * @param callable|string $handler A callback function or controller@method to handle the route.
     * @param string|null $name An optional name for the route.
     * @param array $middlewares Optional list of middleware for the route.
     */
    public function add($methods, $uri, $handler, $name = null, $middlewares = [])
    {
        // Apply group attributes (prefix, middleware) to the route
        if (isset($this->attributes['prefix'])) {
            $uri = $this->attributes['prefix'] . $uri;
        }

        if (isset($this->attributes['middleware'])) {
            $middlewares = array_merge($middlewares, $this->attributes['middleware']);
        }

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

    /**
     * Group routes with shared attributes (e.g., prefix, middleware).
     *
     * @param array $attributes The group attributes (e.g., 'prefix', 'middleware').
     * @param callable $callback The function to define the routes within the group.
     */
    public function group(array $attributes, callable $callback)
    {
        // Store the current attributes to apply to all routes in the group
        $previousAttributes = $this->attributes;
        $this->attributes = array_merge($this->attributes, $attributes);

        // Call the callback to define the routes
        $callback($this);

        // Restore the previous attributes
        $this->attributes = $previousAttributes;
    }

    /**
     * Get the URL for a named route.
     *
     * @param string $name The name of the route.
     * @param array $params Optional parameters for dynamic segments.
     * @return string|null The generated URL or null if not found.
     */
    public function route($name, $params = [])
    {
        if (!isset($this->namedRoutes[$name])) {
            return '#'; // Fallback if the route is not found
        }

        $url = $this->namedRoutes[$name];

        // Replace any placeholders like {id}
        foreach ($params as $key => $value) {
            $url = str_replace("{" . $key . "}", $value, $url);
        }

        return $url;
    }

    /**
     * Set a custom handler for unmatched routes (404 handler).
     *
     * @param callable $callback The callback function for handling 404 responses.
     */
    public function setNotFoundHandler($callback)
    {
        $this->notFoundHandler = $callback;
    }

    /**
     * Dispatch the incoming request to the correct route handler.
     *
     * @param string $method The HTTP method of the current request.
     * @param string $uri The URI path of the current request.
     */
    public function dispatch($method, $uri)
    {
        $method = strtoupper($method);
        $normalizedUri = $this->normalizeUri($uri);

        if (isset($this->routes[$method][$normalizedUri])) {
            // Exact match route
            $route = $this->routes[$method][$normalizedUri];
            $this->handleRouteWithMiddleware($route['handler'], $route['middlewares']);
        } else {
            // Try pattern match for dynamic routes
            foreach ($this->routes[$method] as $pattern => $route) {
                $matches = [];
                if (preg_match($this->convertToRegex($pattern), $normalizedUri, $matches)) {
                    array_shift($matches); // Remove the full match part (first element)
                    $this->handleRouteWithMiddleware($route['handler'], $route['middlewares'], $matches);
                    return;
                }
            }

            // No route matched, invoke the 404 handler
            if (is_callable($this->notFoundHandler)) {
                echo call_user_func($this->notFoundHandler);
            } else {
                include_once BASE_DIR . '/views/default/error/404.php';
            }
        }
    }

    /**
     * Handle route execution, including middleware.
     *
     * @param callable|string $handler The route handler (callback or controller).
     * @param array $middlewares The middleware to be executed for the route.
     * @param array $params The dynamic route parameters.
     */
    private function handleRouteWithMiddleware($handler, $middlewares, $params = [])
    {
        // Loop through each middleware and execute it
        foreach ($middlewares as $middleware) {
            // Handle callable middlewares
            if (is_callable($middleware)) {
                // Pass the request as a parameter along with the $next callback to continue execution
                $response = call_user_func($middleware, $params, function () use ($handler, $params) {
                    // Return the result after middleware (Don't call the handler yet)
                    return null;
                });

                // If middleware returns a response, stop further execution (e.g., redirect)
                if ($response) {
                    echo $response;
                    return;
                }
            }

            // Handle class-based middlewares (e.g., AuthMiddleware)
            elseif (class_exists($middleware)) {
                $middlewareInstance = new $middleware();

                // Call middleware handle method with a next callback
                $response = $middlewareInstance->handle($params, function () use ($handler, $params) {
                    // Middleware doesn't call the handler, just return null
                    return null;
                });

                // If the middleware returns a response, stop execution (e.g., redirect)
                if ($response) {
                    echo $response;
                    return;
                }
            }
        }

        // After all middlewares pass, call the route handler once
        echo $this->callHandlerWithParams($handler, $params);
    }

    /**
     * Normalize the URI by removing trailing slashes and ensuring it starts with a single slash.
     *
     * @param string $uri The raw URI to normalize.
     * @return string The normalized URI.
     */
    private function normalizeUri($uri)
    {
        return '/' . trim($uri, '/');
    }

    /**
     * Convert the route pattern to a regular expression.
     *
     * @param string $pattern The route pattern.
     * @return string The regular expression.
     */
    private function convertToRegex($pattern)
    {
        return '#^' . preg_replace('/{([a-zA-Z0-9_]+)}/', '([^/]+)', $pattern) . '$#';
    }

    /**
     * Call the route handler function (either callback or controller).
     *
     * @param callable|string $handler The route handler (callback or controller).
     * @return mixed The response from the handler.
     */
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

    /**
     * Call the controller's method with parameters.
     *
     * @param string $controller The controller class.
     * @param string $action The action method.
     * @param array $params The parameters for the action.
     * @return mixed The response from the controller's method.
     */
    private function callControllerMethod($controller, $action, $params = [])
    {
        // Replace slashes with namespace separator
        $controllerClass = 'Project\\Controllers\\' . str_replace(['/', '\\'], '\\', $controller);

        if (class_exists($controllerClass)) {
            $controllerInstance = new $controllerClass();
            if (method_exists($controllerInstance, $action)) {
                return call_user_func_array([$controllerInstance, $action], $params);
            } else {
                return "Action '$action' not found in '$controllerClass'.";
            }
        } else {
            return "Controller '$controllerClass' not found.";
        }
    }


    /**
     * Call the route handler with parameters.
     *
     * @param callable|string $handler The route handler (callback or controller).
     * @param array $params The dynamic route parameters.
     * @return mixed The response from the handler.
     */
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
