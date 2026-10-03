<?php

declare(strict_types=1);

namespace App\Database\Lifecycle;

use App\Container\Container;
use App\Database\Model;
use App\Events\EventDispatcher;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Keeps explicit Model observers within one Application's database manager.
 * A hydrated Model reaches this same registry through its retained Connection,
 * even if another Application later becomes the static helper's active owner.
 */
final class ModelObserverRegistry
{
    private const PHASES = [
        'saving', 'creating', 'created', 'saved',
        'updating', 'updated', 'deleting', 'deleted',
        'restoring', 'restored',
    ];

    /** @var array<class-string<Model>, list<class-string>> */
    private array $observers = [];

    /** @var array<class-string<Model>, array<string, list<callable>>> */
    private array $listeners = [];

    public function __construct(
        private ?Container $container = null,
        private ?EventDispatcher $events = null
    ) {
    }

    /**
     * Register a class with one or more public lifecycle methods. Resolution
     * is deferred until a matching transition, so constructor DI is available.
     *
     * @param class-string<Model> $modelClass
     * @param class-string $observerClass
     */
    public function observe(string $modelClass, string $observerClass): void
    {
        $this->assertModel($modelClass);
        if (!class_exists($observerClass)) {
            throw new InvalidArgumentException('Model observer class is not autoloadable.');
        }
        $reflection = new ReflectionClass($observerClass);
        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException('Model observer must be instantiable.');
        }
        $found = false;
        foreach (self::PHASES as $phase) {
            if (!$reflection->hasMethod($phase)) {
                continue;
            }
            $method = $reflection->getMethod($phase);
            if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 2
                || $method->getNumberOfParameters() < 1) {
                throw new InvalidArgumentException('Model observer lifecycle method has an invalid signature.');
            }
            $found = true;
        }
        if (!$found) {
            throw new InvalidArgumentException('Model observer declares no lifecycle method.');
        }
        $this->observers[$modelClass][] = $observerClass;
    }

    /**
     * Register one callback. It receives the Model and optional event context.
     * Registration order is delivery order; duplicate registrations stay distinct.
     *
     * @param class-string<Model> $modelClass
     */
    public function listen(string $modelClass, string $phase, callable $listener): void
    {
        $this->assertModel($modelClass);
        $this->assertPhase($phase);
        $this->listeners[$modelClass][$phase][] = $listener;
    }

    /** @internal Deliver a transition synchronously without swallowing failures. */
    public function emit(Model $model, string $phase, string $operation): void
    {
        $this->assertPhase($phase);
        $event = new ModelLifecycleEvent($model, $phase, $operation);
        foreach ($this->matchingClasses($model) as $class) {
            foreach ($this->observers[$class] ?? [] as $observerClass) {
                if ($this->container === null) {
                    throw new LogicException('Class-based Model observers require an Application container.');
                }
                $observer = $this->container->make($observerClass);
                if (method_exists($observer, $phase)) {
                    $method = new ReflectionMethod($observer, $phase);
                    $method->getNumberOfParameters() >= 2
                        ? $observer->{$phase}($model, $event)
                        : $observer->{$phase}($model);
                }
            }
            foreach ($this->listeners[$class][$phase] ?? [] as $listener) {
                $listener($model, $event);
            }
        }
        $this->events?->emit($event);
    }

    /** @return list<class-string<Model>> */
    private function matchingClasses(Model $model): array
    {
        $matches = [];
        foreach (class_parents($model) ?: [] as $class) {
            if (is_a($class, Model::class, true)) {
                $matches[] = $class;
            }
        }
        $matches[] = $model::class;
        return $matches;
    }

    private function assertModel(string $class): void
    {
        if (!is_a($class, Model::class, true)) {
            throw new InvalidArgumentException('Lifecycle registrations require a modern Model class.');
        }
    }

    private function assertPhase(string $phase): void
    {
        if (!in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException('Model lifecycle phase is invalid.');
        }
    }
}
