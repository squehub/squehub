<?php

declare(strict_types=1);

namespace App\Database\Factories;

use App\Database\Collections\ModelCollection;
use App\Database\Database;
use App\Database\Model;
use DateTimeImmutable;

/**
 * Builds modern Models through their normal fill, cast, and save contracts.
 *
 * Factory subclasses provide a model class and a constructor compatible with
 * new(), which creates the concrete factory without application-side wiring.
 *
 * @template TModel of Model
 * @phpstan-consistent-constructor
 */
abstract class ModelFactory
{
    /** @var class-string<TModel> */
    protected string $model;
    private int $quantity = 1;
    /** @var list<string> */
    private array $selectedStates = [];
    private ?FactoryRandom $random = null;

    /** @return array<string, mixed> */
    abstract protected function definition(): array;

    /**
     * new() relies on the no-argument constructor contract of factory
     * subclasses; dependencies belong in model/application services instead.
     */
    public function __construct()
    {
    }

    /** @return array<string, mixed> */
    protected function states(): array
    {
        return [];
    }

    public static function new(): static
    {
        /** @var static<TModel> $factory */
        $factory = new static();
        return $factory;
    }

    public function count(int $count): static
    {
        if ($count < 1) {
            throw new FactoryException('Factory count must be at least one.');
        }
        $this->quantity = $count;
        return $this;
    }

    public function state(string $name): static
    {
        $states = $this->states();
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
            || !array_key_exists($name, $states) || !is_array($states[$name])) {
            throw new FactoryException('Factory ' . static::class . " has no valid state '{$name}'.");
        }
        $this->selectedStates[] = $name;
        return $this;
    }

    public function seed(int $seed): static
    {
        $this->random = new FactoryRandom($seed);
        return $this;
    }

    protected function fake(): FactoryRandom
    {
        return $this->random ??= new FactoryRandom();
    }

    protected function now(): DateTimeImmutable
    {
        return Database::manager()->clock()->now();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return TModel|ModelCollection
     */
    public function make(array $overrides = []): Model|ModelCollection
    {
        $models = [];
        for ($index = 0; $index < $this->quantity; $index++) {
            $models[] = $this->makeOne($overrides);
        }
        return $this->quantity === 1 ? $models[0] : new ModelCollection($models);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return TModel|ModelCollection
     */
    public function create(array $overrides = []): Model|ModelCollection
    {
        $models = [];
        for ($index = 0; $index < $this->quantity; $index++) {
            $model = $this->makeOne($overrides);
            if (!$model->save()) {
                throw new FactoryException('Factory ' . static::class . ' could not persist a model.');
            }
            $models[] = $model;
        }
        return $this->quantity === 1 ? $models[0] : new ModelCollection($models);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return TModel
     */
    private function makeOne(array $overrides): Model
    {
        if (!isset($this->model)) {
            throw new FactoryException('Factory ' . static::class . ' must bind a modern Model subclass.');
        }
        $class = $this->model;
        $this->validateModelClass($class);
        // Definition and states are evaluated per model; explicit attributes win last.
        $attributes = $this->definition();
        foreach ($this->selectedStates as $name) {
            $states = $this->states();
            if (!isset($states[$name]) || !is_array($states[$name])) {
                throw new FactoryException('Factory ' . static::class . " has no valid state '{$name}'.");
            }
            $attributes = array_replace($attributes, $states[$name]);
        }
        return new $class(array_replace($attributes, $overrides));
    }

    /**
     * Validate the native string at runtime, including factories supplied by
     * application code that is not checked by a static analyser.
     *
     */
    private function validateModelClass(string $class): void
    {
        if (!is_subclass_of($class, Model::class)) {
            throw new FactoryException('Factory ' . static::class . ' must bind a modern Model subclass.');
        }
    }
}
