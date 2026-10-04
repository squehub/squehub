<?php

declare(strict_types=1);

namespace App\View;

use App\Foundation\Application;
use App\Http\Request;
use App\View\Assets\AssetRegistry;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/** Application-owned registrations with request-bound provider results. */
final class ViewContextManager
{
    /** @var array<string, mixed> */
    private array $shared = [];

    /** @var list<callable|string> */
    private array $providers = [];

    /** @var array<string, list<callable|string>> */
    private array $composers = [];

    private ?Request $request = null;

    /** @var array<string, mixed>|null */
    private ?array $requestProviderValues = null;

    /** @var list<array{request: ?Request, providers: ?array}> */
    private array $requestScopes = [];

    private int $renderDepth = 0;

    /** @var array<string, mixed>|null */
    private ?array $renderShared = null;

    /** @var array<string, mixed>|null */
    private ?array $renderProviderValues = null;

    private readonly AssetRegistry $assets;

    public function __construct(private readonly Application $application)
    {
        $this->assets = new AssetRegistry();
    }

    public function application(): Application
    {
        return $this->application;
    }

    /** The active HTTP scope is released by endRequest(), including nested handling. */
    public function currentRequest(): ?Request
    {
        return $this->request;
    }

    /** Registrations persist on this Application; each render snapshots them. */
    public function assets(): AssetRegistry
    {
        return $this->assets;
    }

    /** Replace a stable Application value explicitly when the name is shared again. */
    public function share(string $name, mixed $value): void
    {
        self::assertContextName($name);
        $this->shared[$name] = $value;
    }

    /** Register a global provider, invoked lazily once per HTTP request. */
    public function provide(callable|string $provider): void
    {
        $this->providers[] = $provider;
    }

    /** Register a composer for one exact, case-sensitive logical View name. */
    public function compose(string $view, callable|string $composer): void
    {
        if (LogicalViewName::parse($view) === null) {
            throw new InvalidArgumentException('Composer View name must be a logical View name.');
        }
        $this->composers[$view][] = $composer;
    }

    /** Begin one Kernel handling attempt, even if it reuses the same Request object. */
    public function beginRequest(Request $request): void
    {
        if ($this->renderDepth !== 0) {
            throw new LogicException('A request cannot begin during View rendering.');
        }
        $this->requestScopes[] = [
            'request' => $this->request,
            'providers' => $this->requestProviderValues,
        ];
        $this->request = $request;
        $this->requestProviderValues = null;
    }

    /** Release the Request and every provider value derived from it. */
    public function endRequest(): void
    {
        $previous = array_pop($this->requestScopes);
        $this->request = $previous['request'] ?? null;
        $this->requestProviderValues = $previous['providers'] ?? null;
    }

    /** Keep one stable shared snapshot through a complete render tree. */
    public function beginRender(): void
    {
        if ($this->renderDepth++ === 0) {
            $this->renderShared = $this->shared;
            $this->renderProviderValues = null;
        }
    }

    public function endRender(): void
    {
        if ($this->renderDepth === 0) {
            throw new LogicException('View render scope is not active.');
        }
        if (--$this->renderDepth === 0) {
            $this->renderShared = null;
            $this->renderProviderValues = null;
        }
    }

    /**
     * Resolve one View snapshot. Included Views use shared < provider < inherited
     * parent < child composer < explicit include data. Layouts are special:
     * View passes the resolved child context as explicit data, keeping that
     * context authoritative over a layout composer. Generated framework names
     * are never taken from caller data.
     *
     * @param array<array-key, mixed> $explicit
     * @param array<array-key, mixed> $inherited
     * @return array<array-key, mixed>
     */
    public function contextFor(string $view, array $explicit = [], array $inherited = []): array
    {
        $shared = $this->renderShared ?? $this->shared;
        $providers = $this->providerValues();
        $composers = $this->composerValues($view);

        // Existing render calls may carry keys extract() ignores. Preserve that
        // behavior while excluding names that would alter renderer or PHP state.
        $explicit = self::acceptedRuntimeData($explicit);
        $inherited = self::acceptedRuntimeData($inherited);

        return array_replace($shared, $providers, $inherited, $composers, $explicit);
    }

    /** @param array<array-key, mixed> $data @return array<array-key, mixed> */
    private static function acceptedRuntimeData(array $data): array
    {
        unset($data['errors'], $data['GLOBALS'], $data['this']);
        foreach (array_keys($data) as $name) {
            if (is_string($name) && str_starts_with($name, '__squehub_')) {
                unset($data[$name]);
            }
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function providerValues(): array
    {
        if ($this->request !== null && $this->requestProviderValues !== null) {
            return $this->requestProviderValues;
        }
        if ($this->renderDepth > 0 && $this->renderProviderValues !== null) {
            return $this->renderProviderValues;
        }

        $values = [];
        $context = new ViewContext($this->application, $this->request, null);
        foreach ($this->providers as $provider) {
            $this->mergeUnique($values, $this->invoke($provider, 'provide', $context), 'provider');
        }

        if ($this->request !== null) {
            $this->requestProviderValues = $values;
        }
        if ($this->renderDepth > 0) {
            $this->renderProviderValues = $values;
        }
        return $values;
    }

    /** @return array<string, mixed> */
    private function composerValues(string $view): array
    {
        $values = [];
        if (!isset($this->composers[$view])) {
            return $values;
        }
        $context = new ViewContext($this->application, $this->request, $view);
        foreach ($this->composers[$view] as $composer) {
            $this->mergeUnique($values, $this->invoke($composer, 'compose', $context), 'composer');
        }
        return $values;
    }

    /** @return array<string, mixed> */
    private function invoke(callable|string $source, string $method, ViewContext $context): array
    {
        if (is_string($source) && !is_callable($source)) {
            $instance = $this->application->container()->make($source);
            if (!is_callable([$instance, $method])) {
                throw new LogicException("View {$method} class must expose {$method}(ViewContext).");
            }
            $result = $instance->{$method}($context);
        } else {
            $result = $source($context);
        }
        if (!is_array($result)) {
            throw new UnexpectedValueException("View {$method} must return an array of context values.");
        }
        foreach ($result as $name => $_value) {
            self::assertContextName($name);
        }
        return $result;
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $values */
    private function mergeUnique(array &$target, array $values, string $layer): void
    {
        foreach ($values as $name => $value) {
            if (array_key_exists($name, $target)) {
                throw new LogicException("Duplicate View {$layer} context name '{$name}'.");
            }
            $target[$name] = $value;
        }
    }

    private static function assertContextName(mixed $name): void
    {
        if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('View context name must be a safe PHP variable name.');
        }
        if ($name === 'this' || $name === 'GLOBALS'
            || str_starts_with($name, '__squehub_') || $name === 'errors') {
            throw new InvalidArgumentException("View context name '{$name}' is reserved.");
        }
    }
}
