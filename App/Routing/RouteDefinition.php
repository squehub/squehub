<?php

declare(strict_types=1);

namespace App\Routing;

use App\Api\ApiVersionPolicy;
use App\Api\Contract\OperationContract;
use App\Database\Identifier;
use App\Database\Model;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;

/** A registered route; fluent changes update its owning registry. */
final class RouteDefinition
{
    private ?string $name = null;
    private bool $protected = false;
    private ?string $apiVersion = null;
    private ?OperationContract $contract = null;
    private RoutePattern $pattern;

    /** @var array<string, array{model: class-string<Model>, key: ?string}> Declarative, never hydrated. */
    private array $modelBindings = [];

    /** @var list<string|object> Modern routes also accept explicit middleware objects. */
    private array $middleware;

    /** @param list<string> $methods */
    public function __construct(
        private RouteRegistry $registry,
        private array $methods,
        private string $uri,
        private mixed $action,
        array $middleware = [],
        private bool $legacy = false,
        ?string $apiVersion = null,
        ?string $host = null,
        private bool $fallback = false
    ) {
        $this->middleware = $middleware;
        $this->pattern = new RoutePattern($uri, $host, $fallback);
        if ($apiVersion !== null) {
            $this->apiVersion($apiVersion);
        }
    }

    /** @return list<string> */
    public function methods(): array { return $this->methods; }
    public function uri(): string { return $this->uri; }
    public function action(): mixed { return $this->action; }
    public function nameValue(): ?string { return $this->name; }
    public function middlewares(): array { return $this->middleware; }
    public function isLegacy(): bool { return $this->legacy; }
    public function isProtected(): bool { return $this->protected; }
    public function apiVersionValue(): ?string { return $this->apiVersion; }
    public function contractValue(): ?OperationContract { return $this->contract; }
    public function hostPattern(): ?string { return $this->pattern->host(); }
    public function isFallback(): bool { return $this->fallback; }
    public function pattern(): RoutePattern { return $this->pattern; }

    /** Constrain one declared path or host parameter without changing its raw request value. */
    public function where(string $parameter, string $pattern): self
    {
        if ($this->legacy || $this->fallback) {
            throw new LogicException('Parameter constraints are available on ordinary modern routes only.');
        }
        $this->pattern->constrain($parameter, $pattern);
        return $this;
    }

    /** @return array<string, array{model: class-string<Model>, key: ?string}> */
    public function modelBindings(): array { return $this->modelBindings; }

    /**
     * Opt a path parameter into one Model lookup when a matched request reaches
     * its controller. Middleware and static route inspection never resolve it.
     *
     * @param class-string<Model> $modelClass
     */
    public function bind(string $parameter, string $modelClass, ?string $key = null): self
    {
        if ($this->legacy) {
            throw new LogicException('Model binding is available on modern routes only.');
        }
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $parameter) !== 1
            || !str_contains($this->uri, '{' . $parameter . '}')) {
            throw new InvalidArgumentException('Model binding must name a parameter in this route URI.');
        }
        if (isset($this->modelBindings[$parameter])) {
            throw new LogicException("Route parameter '{$parameter}' is already bound.");
        }
        if (!is_subclass_of($modelClass, Model::class)
            || !(new ReflectionClass($modelClass))->isInstantiable()) {
            throw new InvalidArgumentException('Route binding must name a concrete modern Model class.');
        }
        if ($key !== null) {
            Identifier::simple($key);
        }
        $this->modelBindings[$parameter] = ['model' => $modelClass, 'key' => $key];
        return $this;
    }

    /** Attach an explicit public network contract without changing dispatch. */
    public function contract(OperationContract $contract): self
    {
        // OpenAPI path parameters are required, and the current Contract
        // model has no per-operation host or fallback representation.
        if ($this->pattern->hasOptionalPath() || $this->hostPattern() !== null || $this->fallback) {
            throw new LogicException('A route with optional path parameters, a host condition, or fallback behavior cannot declare an OperationContract.');
        }
        if ($this->contract !== null) {
            throw new LogicException('A route contract is already attached.');
        }
        $this->contract = $contract;
        $this->registry->refreshContribution($this);
        return $this;
    }

    /** Declare one semantic API version for this route. */
    public function apiVersion(string $version): self
    {
        $normalized = ApiVersionPolicy::normalizeIdentifier($version);
        if ($this->apiVersion !== null && $this->apiVersion !== $normalized) {
            throw new LogicException('A route cannot declare conflicting API versions.');
        }
        $this->apiVersion = $normalized;
        $this->registry->refreshContribution($this);
        return $this;
    }

    /** Framework operational routes must not be replaced by legacy overwrites. */
    public function protect(): self { $this->protected = true; return $this; }

    public function named(string $name): self
    {
        $this->registry->nameRoute($this, $name);
        return $this;
    }

    /** Accept aliases/classes or explicit handle()/invokable objects in order. */
    public function through(string|object|array $middleware): self
    {
        $items = is_array($middleware) ? $middleware : [$middleware];
        foreach ($items as $item) {
            if ((!is_string($item) || trim($item) === '')
                && (!is_object($item) || (!(method_exists($item, 'handle') && is_callable([$item, 'handle']))
                    && !is_callable($item)))) {
                throw new InvalidArgumentException('Modern route middleware must be an alias, class, or handler object.');
            }
            $this->middleware[] = $item;
        }
        $this->registry->refreshContribution($this);
        return $this;
    }

    /** @internal Used only when legacy registration overwrites an existing method/URI. */
    public function removeMethod(string $method): void
    {
        $this->methods = array_values(array_filter($this->methods, static fn (string $value): bool => $value !== $method));
    }

    /** @internal Registry owns name uniqueness. */
    public function setName(?string $name): void
    {
        $this->name = $name;
    }
}
