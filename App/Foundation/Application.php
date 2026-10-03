<?php

declare(strict_types=1);

namespace App\Foundation;

use App\Activation\ActivationRegistry;
use App\Activation\ActivationStore;
use App\Config\Loader;
use App\Config\ConfigCache;
use App\Config\Repository;
use App\Container\Container;
use App\Contributions\ContributionRegistry;
use App\Core\View;
use App\Data\TypedPayloadRegistry;
use App\Frontend\AssetMapper;
use App\Frontend\FrontendManager;
use App\Kits\KitManager;
use App\Packages\PackageServiceProvider;
use App\Packages\PackageManager;
use App\View\ViewContextManager;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Owns the container, configuration, and provider bootstrap lifecycle. */
final class Application
{
    private string $basePath;
    private Container $container;
    private ContributionRegistry $contributions;
    private Repository $config;
    private ConfigCache $configCache;
    private Environment $environmentValues;
    private ViewContextManager $views;

    /** @var list<class-string<ServiceProvider>> */
    private array $providerClasses = [];

    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    private array $providers = [];

    /** @var array<class-string<ServiceProvider>, true> */
    private array $bootedProviders = [];

    private bool $booting = false;
    private bool $booted = false;
    private bool $failed = false;
    private bool $activatePackages = true;
    private bool $packageVerification = false;
    private bool $ignoreConfigCache = false;
    private bool $configurationPreviewOnly = false;

    public function __construct(string $basePath)
    {
        $resolved = realpath($basePath);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('Application base path must be an existing directory.');
        }
        $normalized = str_replace('\\', '/', $resolved);
        $this->basePath = strlen($normalized) > 3 ? rtrim($normalized, '/') : $normalized;
        $this->container = new Container();
        $this->contributions = new ContributionRegistry();
        $this->config = new Repository();
        $this->configCache = new ConfigCache($this->basePath);
        $this->environmentValues = new Environment($this->basePath);
        $this->views = new ViewContextManager($this);

        $this->container->instance(self::class, $this);
        // Activation state belongs to the Application. Merely constructing the
        // store never creates a registry file or executes Package/Kit PHP.
        $this->container->instance(ActivationStore::class, new ActivationStore($this->basePath));
        $this->container->singleton(ActivationRegistry::class);
        $this->container->instance(ContributionRegistry::class, $this->contributions);
        $this->container->instance(ViewContextManager::class, $this->views);
        $this->container->setContributionRegistry($this->contributions);
        $this->config->setContributionRegistry($this->contributions);
        View::setContributionRegistry($this->contributions, $this->basePath);
        View::selectContextManager($this->views);
        $this->container->instance(Container::class, $this->container);
        // Typed payload type definitions are scoped to this Application, so a
        // worker cannot accidentally reuse another Application's registry.
        $this->container->instance(TypedPayloadRegistry::class, new TypedPayloadRegistry());
        $this->container->alias(TypedPayloadRegistry::class,
            \App\Plugins\TypedPayloadRegistry::class);
        $this->container->instance(Repository::class, $this->config);
        $this->container->instance(ConfigCache::class, $this->configCache);
        $this->container->instance(Environment::class, $this->environmentValues);
        // Kit metadata is application-scoped. Constructing the manager does not
        // discover Kits or execute their lifecycle PHP during normal boot.
        $this->container->singleton(KitManager::class);
    }

    public function basePath(string $path = ''): string
    {
        return $path === '' ? $this->basePath : $this->basePath . '/' . ltrim($path, '/\\');
    }

    public function appPath(): string
    {
        return $this->sourcePath('App');
    }

    public function projectPath(): string
    {
        return $this->sourcePath('Project');
    }

    public function configPath(): string
    {
        return $this->sourcePath('Config');
    }

    public function publicPath(): string
    {
        return $this->basePath('public');
    }

    private function sourcePath(string $directory): string
    {
        $preferred = $this->basePath($directory);
        if (is_dir($preferred)) {
            return $preferred;
        }

        $legacy = $this->basePath(lcfirst($directory));
        return is_dir($legacy) ? $legacy : $preferred;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function contributions(): ContributionRegistry
    {
        return $this->contributions;
    }

    public function views(): ViewContextManager
    {
        return $this->views;
    }

    public function config(): Repository
    {
        return $this->config;
    }

    public function environment(): string
    {
        return (string) $this->config->get('app.env', 'development');
    }

    public function isDebug(): bool
    {
        return $this->config->get('app.debug', false) === true;
    }

    /** @param class-string<ServiceProvider> $providerClass */
    public function register(string $providerClass): void
    {
        if ($this->booting || $this->booted || $this->failed) {
            throw new LogicException('Providers must be registered before application bootstrap.');
        }
        if (!is_subclass_of($providerClass, ServiceProvider::class)) {
            throw new InvalidArgumentException("Provider '{$providerClass}' must extend ServiceProvider.");
        }
        if (!in_array($providerClass, $this->providerClasses, true)) {
            $this->providerClasses[] = $providerClass;
        }
    }

    /** @return list<class-string<ServiceProvider>> */
    public function providers(): array
    {
        return $this->providerClasses;
    }

    public function hasProvider(string $providerClass): bool
    {
        return in_array($providerClass, $this->providerClasses, true);
    }

    public function isProviderBooted(string $providerClass): bool
    {
        return isset($this->bootedProviders[$providerClass]);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** Allow management CLI to inspect/repair Packages without executing them. */
    public function inspectPackagesOnly(): void
    {
        if ($this->booting || $this->booted || $this->failed) {
            throw new LogicException('Package inspection mode must be selected before bootstrap.');
        }
        $this->activatePackages = false;
    }

    /** Mark an explicit trusted Package verification before normal bootstrap. */
    public function verifyPackagesOnly(): void
    {
        if ($this->booting || $this->booted || $this->failed) {
            throw new LogicException('Package verification mode must be selected before bootstrap.');
        }
        $this->packageVerification = true;
    }

    public function isPackageVerification(): bool
    {
        return $this->packageVerification;
    }

    /** Management commands must still boot when a corrupt cache needs clearing. */
    public function ignoreConfigCache(): void
    {
        if ($this->booting || $this->booted || $this->failed) {
            throw new LogicException('Config cache mode must be selected before bootstrap.');
        }
        $this->ignoreConfigCache = true;
    }

    /**
     * Config cache preview needs the Application's Environment and paths, but
     * loading executable Config PHP or booting providers would defeat a static
     * preview. This mode is selected only before CLI bootstrap.
     */
    public function inspectConfigurationOnly(): void
    {
        if ($this->booting || $this->booted || $this->failed) {
            throw new LogicException('Configuration inspection mode must be selected before bootstrap.');
        }
        $this->activatePackages = false;
        $this->ignoreConfigCache = true;
        $this->configurationPreviewOnly = true;
    }

    public function bootstrap(): void
    {
        if ($this->booted) {
            return;
        }
        if ($this->booting || $this->failed) {
            throw new LogicException('Application bootstrap cannot be restarted or reentered.');
        }

        // Package activation participates in every Application, including test
        // and embedded applications that do not use Bootstrap/App.php. Keep it
        // after caller-registered providers so Package hooks see core bindings.
        if ($this->activatePackages) {
            $this->register(PackageServiceProvider::class);
            $packageIndex = array_search(PackageServiceProvider::class, $this->providerClasses, true);
            if ($packageIndex !== false) {
                unset($this->providerClasses[$packageIndex]);
            }
            $this->providerClasses = array_values($this->providerClasses);
            $this->providerClasses[] = PackageServiceProvider::class;
        } else {
            // Package lifecycle commands still need one Application-owned
            // manager, but no Package entry, route, or utility may execute.
            $this->container->singleton(PackageManager::class);
            $this->providerClasses = array_values(array_filter($this->providerClasses,
                static fn (string $class): bool => $class !== PackageServiceProvider::class));
        }

        $this->booting = true;
        try {
            $this->environmentValues->load();
            if ($this->configurationPreviewOnly) {
                // No Config file or provider may execute while the command is
                // still deciding whether a private cache would change.
                $this->booted = true;
                return;
            }
            if ($this->ignoreConfigCache || !$this->configCache->load(
                $this->configPath(), $this->environmentValues, $this->config
            )) {
                (new Loader())->load($this->configPath(), $this->environmentValues, $this->config);
            }

            // Validate the deployment URL mount before Package providers can
            // register routes or generate URLs. Filesystem basePath() is separate.
            $urlBasePath = $this->config->get('http.base_path', '');
            if (!is_string($urlBasePath)) {
                throw new InvalidArgumentException('HTTP URL base path must be a string.');
            }
            $mount = new UrlBasePath($urlBasePath);
            $this->container->instance(UrlBasePath::class, $mount);
            $assets = new AssetMapper($this, $mount);
            $this->container->instance(AssetMapper::class, $assets);
            $this->container->instance(FrontendManager::class,
                new FrontendManager($this, $assets));

            // Complete registration before booting so providers can use services
            // contributed by providers later in the list.
            foreach ($this->providerClasses as $class) {
                $provider = $this->container->make($class);
                if (!$provider instanceof ServiceProvider) {
                    throw new LogicException("Provider '{$class}' did not resolve to a ServiceProvider.");
                }
                $this->providers[$class] = $provider;
                $provider->register();
            }
            foreach ($this->providers as $class => $provider) {
                $provider->boot();
                $this->bootedProviders[$class] = true;
            }
            $this->booted = true;
        } catch (Throwable $exception) {
            // A partially booted application cannot safely retry bootstrap.
            $this->failed = true;
            throw $exception;
        } finally {
            $this->booting = false;
        }
    }
}
