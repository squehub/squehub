<?php

declare(strict_types=1);

namespace App\Container;

use App\Contributions\ContributionRegistry;
use App\Container\Exception\BindingResolutionException;
use App\Container\Exception\CircularDependencyException;
use App\Container\Exception\ContainerException;
use App\Container\Exception\NotFoundException;
use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/** Stores explicit services and autowires unregistered concrete classes on demand. */
final class Container implements ContainerInterface
{
    /** @var array<string, array{concrete: string|Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, string> Alias => target. */
    private array $aliases = [];

    /** @var list<string> */
    private array $resolving = [];

    private ?ContributionRegistry $contributions = null;

    public function setContributionRegistry(ContributionRegistry $contributions): void
    {
        $this->contributions = $contributions;
    }

    public function bind(string $id, string|Closure|null $concrete = null): void
    {
        $this->register($id, $concrete ?? $id, false);
    }

    public function singleton(string $id, string|Closure|null $concrete = null): void
    {
        $this->register($id, $concrete ?? $id, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertNotAlias($id);
        if ((class_exists($id) || interface_exists($id)) && !$instance instanceof $id) {
            throw new BindingResolutionException("Instance for '{$id}' is incompatible with that service ID.");
        }
        unset($this->bindings[$id]);
        $this->instances[$id] = $instance;
        $this->contributions?->forget('service', $id);
        $this->contributions?->record('service', $id, null,
            ['binding' => 'instance']);
    }

    public function alias(string $id, string $alias): void
    {
        if ($id === $alias || isset($this->bindings[$alias])
            || isset($this->instances[$alias]) || isset($this->aliases[$alias])) {
            throw new ContainerException("Cannot register alias '{$alias}': the name is already in use or points to itself.");
        }

        $seen = [$alias => true];
        $target = $id;
        while (isset($this->aliases[$target])) {
            if (isset($seen[$target])) {
                throw new CircularDependencyException("Circular container alias involving '{$alias}'.");
            }
            $seen[$target] = true;
            $target = $this->aliases[$target];
        }
        if (isset($seen[$target])) {
            throw new CircularDependencyException("Circular container alias involving '{$alias}'.");
        }

        $this->aliases[$alias] = $id;
        $this->contributions?->forget('service', $alias);
        $this->contributions?->record('service', $alias, null,
            ['binding' => 'alias']);
    }

    /** Only explicit bindings, instances, and aliases to them count as registered. */
    public function has(string $id): bool
    {
        $id = $this->canonical($id);
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        return $this->make($id);
    }

    public function make(string $id): object
    {
        // Unlike has(), make() may resolve an unregistered concrete class.
        $id = $this->canonical($id);
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolving, true)) {
            throw new CircularDependencyException('Circular container dependency: '
                . implode(' -> ', [...$this->resolving, $id]));
        }

        $this->resolving[] = $id;
        try {
            $binding = $this->bindings[$id] ?? ['concrete' => $id, 'shared' => false];
            $concrete = $binding['concrete'];
            try {
                $object = $concrete instanceof Closure ? $concrete($this) : $this->build($concrete);
            } catch (NotFoundException $exception) {
                if (!isset($this->bindings[$id])) {
                    throw $exception;
                }
                throw new BindingResolutionException("Binding for '{$id}' cannot be built: {$exception->getMessage()}", 0, $exception);
            }
            if (!is_object($object)) {
                throw new BindingResolutionException("Factory for '{$id}' must return an object.");
            }
            if ((class_exists($id) || interface_exists($id)) && !$object instanceof $id) {
                throw new BindingResolutionException("Binding for '{$id}' returned an incompatible object.");
            }
            if ($binding['shared']) {
                $this->instances[$id] = $object;
            }
            return $object;
        } finally {
            array_pop($this->resolving);
        }
    }

    private function register(string $id, string|Closure $concrete, bool $shared): void
    {
        $this->assertNotAlias($id);
        // Rebinding invalidates a previously resolved singleton or instance.
        unset($this->instances[$id]);
        $this->bindings[$id] = ['concrete' => $concrete, 'shared' => $shared];
        $this->contributions?->forget('service', $id);
        // A concrete string may be application-supplied; provenance needs only
        // binding shape and owner, never a factory target or object state.
        $this->contributions?->record('service', $id, null,
            ['binding' => $shared ? 'singleton' : 'transient']);
    }

    private function assertNotAlias(string $id): void
    {
        if (isset($this->aliases[$id])) {
            throw new ContainerException("Cannot register '{$id}' because it is an alias.");
        }
    }

    private function canonical(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new CircularDependencyException("Circular container alias involving '{$id}'.");
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }

    private function build(string $class): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $exception) {
            throw new NotFoundException("Container service '{$class}' was not found.", 0, $exception);
        }
        if (!$reflection->isInstantiable()) {
            throw new NotFoundException("Container service '{$class}' is not instantiable; bind it to a concrete class.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($class, $parameter);
        }
        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(string $class, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();
            if ($type->allowsNull() && !$this->has($dependency)
                && !class_exists($dependency)) {
                return null;
            }
            try {
                return $this->make($dependency);
            } catch (CircularDependencyException $exception) {
                throw $exception;
            } catch (ContainerException $exception) {
                if ($parameter->isDefaultValueAvailable()) {
                    return $parameter->getDefaultValue();
                }
                throw new BindingResolutionException(
                    "Cannot resolve '{$class}' constructor parameter \${$parameter->getName()} ({$dependency}): {$exception->getMessage()}",
                    0,
                    $exception
                );
            }
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        if ($type !== null && $type->allowsNull()) {
            return null;
        }
        $description = $type === null ? 'untyped' : (string) $type;
        throw new BindingResolutionException(
            "Cannot resolve '{$class}' constructor parameter \${$parameter->getName()} ({$description}); register a factory or instance."
        );
    }
}
