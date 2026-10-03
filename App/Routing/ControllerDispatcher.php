<?php

declare(strict_types=1);

namespace App\Routing;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Foundation\Application;
use App\Packages\PackageManager;
use App\Http\DispatchResult;
use App\Http\Request;
use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/** Resolves controller constructors through Container and only injects Request/route values into methods. */
final class ControllerDispatcher
{
    private ?Diagnostics $diagnostics = null;

    public function __construct(private Container $container, private Application $app)
    {
    }

    public function setDiagnostics(Diagnostics $diagnostics): void
    {
        $this->diagnostics = $diagnostics;
    }

    public function dispatch(RouteDefinition $route, Request $request): DispatchResult
    {
        $level = ob_get_level();
        ob_start();
        try {
            $action = $route->action();
            $label = $action instanceof Closure ? 'Closure'
                : (is_array($action) ? (isset($action[0])
                    ? (is_object($action[0]) ? $action[0]::class : (string) $action[0]) : 'callable')
                    : (is_string($action) ? $action : 'callable'));
            $value = $this->diagnostics === null ? $this->invoke($route, $request)
                : $this->diagnostics->span('http.controller',
                    fn (): mixed => $this->invoke($route, $request), ['controller' => $label]);
            $output = '';
            while (ob_get_level() > $level) {
                $output = ob_get_clean() . $output;
            }
            return new DispatchResult($value, $output);
        } catch (Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $exception;
        }
    }

    private function invoke(RouteDefinition $route, Request $request): mixed
    {
        $action = $route->action();
        $params = $request->route();
        if ($route->modelBindings() !== []) {
            // Route middleware already ran and still sees raw path values.
            // Only this controller invocation receives hydrated Models.
            $params = $this->diagnostics === null
                ? $this->container->make(RouteModelBinder::class)->resolve($route, $params)
                : $this->diagnostics->span('http.binding',
                    fn (): array => $this->container->make(RouteModelBinder::class)->resolve($route, $params));
        }
        if ($action instanceof Closure) {
            $reflection = new ReflectionFunction($action);
            return $action(...$this->arguments($reflection->getParameters(), $request, $params, $route->isLegacy()));
        }
        if (is_array($action) && count($action) === 2) {
            [$target, $method] = $action;
            $controller = is_string($target) ? $this->container->make($target) : $target;
            if (!is_object($controller) || !method_exists($controller, $method)) {
                throw new InvalidArgumentException('Route controller action was not found.');
            }
            $reflection = new ReflectionMethod($controller, $method);
            if (!$reflection->isPublic()) {
                throw new InvalidArgumentException('Route controller action must be public.');
            }
            return $reflection->invokeArgs($controller,
                $this->arguments($reflection->getParameters(), $request, $params, $route->isLegacy()));
        }
        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            if ($route->isLegacy()) {
                $resolved = $this->legacyClass($class);
                if ($resolved === null) {
                    return "Controller '{$class}' not found in any known namespaces.";
                }
                $class = $resolved;
            }
            return $this->invokeAction($class, $method, $request, $params, $route->isLegacy());
        }
        if (is_string($action) && function_exists($action)) {
            $reflection = new ReflectionFunction($action);
            return $action(...$this->arguments($reflection->getParameters(), $request, $params, $route->isLegacy()));
        }
        if (is_string($action)) {
            if ($route->isLegacy()) {
                return 'Handler not valid.';
            }
            return $this->invokeAction($action, '__invoke', $request, $params, $route->isLegacy());
        }
        if (is_callable($action)) {
            return $action(...$this->arguments((new ReflectionMethod($action, '__invoke'))->getParameters(),
                $request, $params, $route->isLegacy()));
        }
        if ($route->isLegacy()) {
            return 'Handler not valid.';
        }
        throw new InvalidArgumentException('Route action is invalid.');
    }

    private function invokeAction(string $class, string $method, Request $request, array $params, bool $legacy): mixed
    {
        $controller = $this->container->make($class);
        if (!method_exists($controller, $method)) {
            if ($legacy) {
                return "Action '{$method}' not found in '{$class}'.";
            }
            throw new InvalidArgumentException("Route action '{$class}@{$method}' was not found.");
        }
        $reflection = new ReflectionMethod($controller, $method);
        if (!$reflection->isPublic()) {
            throw new InvalidArgumentException('Route controller action must be public.');
        }
        return $reflection->invokeArgs($controller,
            $this->arguments($reflection->getParameters(), $request, $params, $legacy));
    }

    /** @param list<ReflectionParameter> $parameters */
    private function arguments(array $parameters, Request $request, array $routeParams, bool $legacy): array
    {
        $values = [];
        $positional = array_values($routeParams);
        $position = 0;
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()
                && is_a(Request::class, $type->getName(), true)) {
                $values[] = $request;
            } elseif (array_key_exists($parameter->getName(), $routeParams)) {
                $values[] = $routeParams[$parameter->getName()];
                $position++;
            } elseif ($legacy && array_key_exists($position, $positional)) {
                $values[] = $positional[$position++];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $values[] = $parameter->getDefaultValue();
            } else {
                throw new InvalidArgumentException("Cannot supply route action parameter '{$parameter->getName()}'.");
            }
        }
        return $values;
    }

    private function legacyClass(string $name): ?string
    {
        $normalized = str_replace('/', '\\', $name);
        $candidates = ['Project\\Controllers\\' . $normalized];
        foreach ($this->app->container()->make(PackageManager::class)->active() as $package) {
            // Project\ follows the tracked package tree. Packages\ retains
            // the older public Package namespace for existing installations.
            $candidates[] = 'Project\\Packages\\' . $package->name() . '\\Controllers\\' . $normalized;
            $candidates[] = 'Packages\\' . $package->name() . '\\Controllers\\' . $normalized;
        }
        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
