<?php

declare(strict_types=1);

namespace App\Routing;

use App\Contributions\ContributionRegistry;
use InvalidArgumentException;
use LogicException;

/** Application-owned aliases for route middleware. */
final class MiddlewareRegistry
{
    public function __construct(private ?ContributionRegistry $contributions = null)
    {
    }

    /** @var array<string, class-string> */
    private array $aliases = [];
    private ?string $packageContext = null;

    /** Package hooks may add aliases but may not replace another alias. */
    public function beginPackageContext(string $name): void
    {
        if ($name === '' || $this->packageContext !== null) {
            throw new LogicException('Package middleware context is already active or invalid.');
        }
        $this->packageContext = $name;
    }

    public function endPackageContext(): void
    {
        $this->packageContext = null;
    }

    public function alias(string $name, string $class): void
    {
        if ($name === '' || preg_match('/[\x00-\x20\x7F:]/', $name) || $class === '') {
            throw new InvalidArgumentException('Middleware alias and class must be nonempty.');
        }
        if ($this->packageContext !== null && isset($this->aliases[$name])) {
            throw new LogicException("Middleware alias '{$name}' already exists while loading {$this->packageContext}.");
        }
        $this->aliases[$name] = $class;
        $this->contributions?->forget('middleware', $name);
        $this->contributions?->record('middleware', $name, null, ['class' => $class]);
    }

    public function has(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    public function resolve(string $name): string
    {
        if (isset($this->aliases[$name])) {
            return $this->aliases[$name];
        }
        if (class_exists($name)) {
            return $name;
        }
        throw new LogicException("Middleware '{$name}' is not registered.");
    }
}
