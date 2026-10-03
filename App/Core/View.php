<?php

namespace App\Core;

use App\Contributions\Contribution;
use App\Contributions\ContributionOwner;
use App\Contributions\ContributionRegistry;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Frontend\AssetMapper;
use App\Frontend\FrontendManager;
use App\Http\Response;
use App\Packages\PackageManager;
use App\Packages\PackageFiles;
use App\View\Compiler\CompilerException;
use App\View\Compiler\TemplateCompiler;
use App\View\Compiled\CompiledViewStore;
use App\View\Compiled\CompiledViewException;
use App\View\Assets\AssetRegistry;
use App\View\Assets\AssetRenderState;
use App\View\ComponentAttributeBag;
use App\View\FragmentRenderResult;
use App\View\FragmentNotFoundException;
use App\View\InvalidFragmentNameException;
use App\View\LayoutRenderState;
use App\View\LoopContext;
use App\View\LogicalViewName;
use App\View\ViewContextManager;
use App\View\ViewHttpException;
use App\View\ViewNotFoundException;
use App\View\ViewRenderException;
use App\View\ViewRenderResult;
use WeakReference;

/**
 * Resolves and renders SqueHub templates through the existing static view API.
 * Shared View Context registrations and provider caches live on the selected
 * Application, which this static gateway references only weakly.
 */
class View
{
    protected static $viewPaths = [];
    protected static $sections = [];
    protected static $sectionStack = [];
    protected static $currentSection = null;
    protected static $parentView = null;
    private static int $renderDepth = 0;
    private static ?PackageManager $packageManager = null;
    private static ?string $applicationRoot = null;
    private static ?ContributionRegistry $contributions = null;

    /** The static gateway selects an Application but never owns its context. */
    private static ?WeakReference $contextManager = null;

    public static function selectContextManager(?ViewContextManager $manager): void
    {
        self::$contextManager = $manager === null ? null : WeakReference::create($manager);
    }

    /** Share one stable value on the selected Application. */
    public static function share(string $name, mixed $value): void
    {
        self::requireContextManager()->share($name, $value);
    }

    /** Register a request-scoped provider on the selected Application. */
    public static function provide(callable|string $provider): void
    {
        self::requireContextManager()->provide($provider);
    }

    /** Register a composer for one exact logical View name. */
    public static function compose(string $view, callable|string $composer): void
    {
        self::requireContextManager()->compose($view, $composer);
    }

    /** Register assets for exact logical View owners on this Application. */
    public static function assets(): AssetRegistry
    {
        return self::requireContextManager()->assets();
    }

    /** Read the Application selected by the current View runtime context. */
    public static function application(): Application
    {
        return self::requireContextManager()->application();
    }

    private static function contextManager(): ?ViewContextManager
    {
        $manager = self::$contextManager?->get();
        if (!$manager instanceof ViewContextManager
            || self::$contributions !== $manager->application()->contributions()
            || self::$applicationRoot !== $manager->application()->basePath()) {
            return null;
        }
        return $manager;
    }

    private static function requireContextManager(): ViewContextManager
    {
        return self::contextManager()
            ?? throw new \LogicException('Shared View Context requires a selected Application.');
    }

    /** Keep asset URL projection tied to the selected Application, not static View state. */
    private static function urlBasePath(?ViewContextManager $manager): ?UrlBasePath
    {
        if ($manager === null) return null;
        $container = $manager->application()->container();
        return $container->has(UrlBasePath::class) ? $container->make(UrlBasePath::class) : null;
    }

    /** Frontend resources belong to the rendering Application, not static View state. */
    private static function assetMapper(?ViewContextManager $manager): ?AssetMapper
    {
        if ($manager === null) return null;
        $container = $manager->application()->container();
        return $container->has(AssetMapper::class) ? $container->make(AssetMapper::class) : null;
    }

    private static function frontendManager(?ViewContextManager $manager): ?FrontendManager
    {
        if ($manager === null) return null;
        $container = $manager->application()->container();
        return $container->has(FrontendManager::class)
            ? $container->make(FrontendManager::class) : null;
    }

    /** Replace the Application-specific provenance target when an app is created. */
    public static function setContributionRegistry(?ContributionRegistry $registry, ?string $applicationRoot = null): void
    {
        self::$contributions = $registry;
        self::$applicationRoot = $applicationRoot;
        self::$packageManager = null;
        self::$viewPaths = [];
        self::$contextManager = null;
    }

    /** A new Application replaces Package view visibility and clears stale paths. */
    public static function setPackageManager(?PackageManager $manager, ?string $applicationRoot = null): void
    {
        self::$packageManager = $manager;
        self::$applicationRoot = $applicationRoot;
        self::$viewPaths = [];
    }

    /** Select the Application using the static view API for this operation. */
    public static function selectApplicationContext(ContributionRegistry $registry, string $applicationRoot,
        ?PackageManager $manager): void
    {
        if (self::$contributions === $registry && self::$applicationRoot === $applicationRoot
            && self::$packageManager === $manager) {
            return;
        }
        self::$contributions = $registry;
        self::$applicationRoot = $applicationRoot;
        self::$packageManager = $manager;
        self::$viewPaths = [];
    }

    /**
     * Return the current request's flashed errors, or an empty bag before
     * Session bootstrap. Flash expiry belongs to SessionStore, not View.
     */
    public static function errorBag(): \App\Validation\ErrorBag
    {
        if (!\App\Session\Session::isAvailable()) return new \App\Validation\ErrorBag();
        $messages = \App\Session\Session::manager()->store()->get('_validation_errors', []);
        return new \App\Validation\ErrorBag(is_array($messages) ? $messages : []);
    }

    /**
     * Application views override published package views, which override
     * templates bundled with individual packages.
     */
    public static function initViewPaths(): void
    {
        self::$viewPaths = [];

        $root = self::$applicationRoot ?? self::legacyBasePath();
        $physicalRoot = realpath($root);
        $seenViewPaths = [];
        foreach ([
            $root . '/Project/Views/',
            $root . '/Project/views/',
            $root . '/project/Views/',
            $root . '/project/views/',
            $root . '/Project/PackagesViews/',
            $root . '/Project/packagesViews/',
            $root . '/project/PackagesViews/',
            $root . '/project/packagesViews/',
        ] as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved)
                && $physicalRoot !== false && self::isWithin($resolved, $physicalRoot)
                && !isset($seenViewPaths[$resolved])) {
                $seenViewPaths[$resolved] = true;
                self::$viewPaths[] = $candidate;
            }
        }

        foreach (self::$packageManager?->active() ?? [] as $package) {
            $packageRoot = realpath($package->path());
            if ($packageRoot === false || !is_dir($packageRoot) || is_link($package->path())) {
                throw new \LogicException('Enabled Package directory is unavailable or linked.');
            }
            foreach (['Views', 'views'] as $viewDirectory) {
                $candidate = $packageRoot . '/' . $viewDirectory;
                if (!is_dir($candidate)) {
                    continue;
                }
                $possibleViewPath = realpath($candidate);
                if ($possibleViewPath === false || is_link($candidate)
                    || dirname($possibleViewPath) !== $packageRoot) {
                    throw new \LogicException('Enabled Package view directory is unsafe.');
                }
                if (!isset($seenViewPaths[$possibleViewPath])) {
                    $seenViewPaths[$possibleViewPath] = true;
                    self::$viewPaths[] = $possibleViewPath . '/';
                }
                break;
            }
        }
    }

    public static function getViewPaths(): array
    {
        if (empty(self::$viewPaths)) {
            self::initViewPaths();
        }
        return self::$viewPaths;
    }

    /** Searches configured paths for raw PHP views used by legacy code. */
    public static function findViewFile($viewName)
    {
        return self::resolveViewFile((string) $viewName, '.php');
    }

    /** Resolve a logical view without including or rendering its PHP source. */
    public static function sourceOf(string $view, bool $raw = false): ?Contribution
    {
        $resolution = self::resolveViewFileStatus($view, $raw ? '.php' : '.squehub.php');
        return $resolution['status'] === 'found' ? ($resolution['contribution'] ?? null) : null;
    }

    /** Index selected templates during explicit trusted verification. */
    public static function indexAvailable(): void
    {
        foreach (self::getViewPaths() as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink()) { continue; }
                $path = str_replace('\\', '/', $file->getPathname());
                $raw = false;
                if (str_ends_with($path, '.squehub.php')) {
                    $relative = substr($path, strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1, -12);
                } elseif (str_ends_with($path, '.php')) {
                    $relative = substr($path, strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1, -4);
                    $raw = true;
                } else {
                    continue;
                }
                if ($relative !== '' && preg_match('~\A[A-Za-z0-9_/-]+\z~D', $relative)) {
                    self::sourceOf(str_replace('/', '.', $relative), $raw);
                }
            }
        }
        foreach (self::namespacedSourceRoots() as $entry) {
            $root = $entry['root'];
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
                \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->isLink()) { continue; }
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_ends_with($path, '.squehub.php')) {
                    $relative = substr($path, strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1, -12);
                } else {
                    continue;
                }
                if ($relative !== '' && preg_match('~\A[A-Za-z0-9_/-]+\z~D', $relative)) {
                    self::sourceOf($entry['namespace'] . '::' . str_replace('/', '.', $relative));
                }
            }
        }
    }

    /** Render a dot-separated View to output for the established HTTP path. */
    public static function render($view, $data = [], array $__squehub_inherited = [])
    {
        $result = self::renderFull($view, $data, $__squehub_inherited, false);
        echo $result['html'];
        return $result['value'];
    }

    /**
     * Capture a full View and its finalized asset stacks without creating an
     * HTTP response. This uses the same render tree as render(), including
     * layouts, composers, and nested dependencies.
     *
     * @param array<array-key, mixed> $data
     */
    public static function renderResult(string $view, array $data = []): ViewRenderResult
    {
        $result = self::renderFull($view, $data, [], true);
        return new ViewRenderResult($result['html'], $result['stacks']);
    }

    /**
     * Return a rendered page through HTTP without echoing it. Response owns
     * status and header validation; rendering uses the normal View pipeline.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    public static function response(string $view, array $data = [], int $status = 200,
        array $headers = []): Response
    {
        return new Response(self::renderResult($view, $data)->html(), $status,
            ['Content-Type' => 'text/html; charset=UTF-8', ...$headers]);
    }

    /**
     * Keep the established echo API and the inspection API on one rendering
     * path. Only the final asset projection differs: inspection resolves all
     * participating named stacks for assertions, while render() preserves its
     * existing placeholder-only finalization contract.
     *
     * @return array{html: string, stacks: array<string, string>, value: mixed}
     */
    private static function renderFull($view, $data, array $__squehub_inherited,
        bool $captureStacks): array
    {
        $manager = self::contextManager();
        $manager?->beginRender();
        ++self::$renderDepth;
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $state = new LayoutRenderState([], new AssetRenderState(
                $manager?->assets()->snapshot() ?? [], self::urlBasePath($manager),
                self::assetMapper($manager), self::frontendManager($manager)));
            $value = self::renderInternal($view, $data, $__squehub_inherited,
                $state);
            $output = (string) ob_get_clean();
            if ($captureStacks) {
                $finished = $state->finishFragmentAssets($output);
                return ['html' => $finished['html'], 'stacks' => $finished['stacks'],
                    'value' => $value];
            }
            return ['html' => $state->finishAssets($output), 'stacks' => [],
                'value' => $value];
        } catch (\Throwable $error) {
            // A failed ancestor or nested section must not leak a partially
            // rendered document or retain an open section output buffer.
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw self::renderFailure((string) $view, $error);
        } finally {
            // Sections and a pending layout belong to one render tree. A
            // subsequent request must not inherit either from this one.
            if (--self::$renderDepth === 0) {
                self::$sections = [];
                self::$sectionStack = [];
                self::$parentView = null;
            }
            $manager?->endRender();
        }
    }

    /**
     * Render one statically declared Fragment from its owning root View. Its
     * composer and external owner assets participate, while sibling markup,
     * Layouts, and their composers never execute. The result carries both
     * HTML and finalized stacks without imposing an HTTP transport.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fragment(string $view, string $name, array $data = []): FragmentRenderResult
    {
        if (strlen($name) > 128
            || preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D', $name) !== 1) {
            throw new InvalidFragmentNameException(self::diagnosticName($view));
        }

        $manager = self::contextManager();
        $manager?->beginRender();
        ++self::$renderDepth;
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $resolution = self::resolveViewFileStatus($view, '.squehub.php');
            if ($resolution['status'] !== 'found') {
                throw new ViewNotFoundException(self::diagnosticName($view),
                    $resolution['status'] === 'unsafe');
            }
            $viewFilePath = $resolution['path'];

            $state = new LayoutRenderState([], new AssetRenderState(
                $manager?->assets()->snapshot() ?? [], self::urlBasePath($manager),
                self::assetMapper($manager), self::frontendManager($manager)));
            $state->enterLayout($view, $viewFilePath);
            try {
                $explicit = self::acceptedData($data);
                $context = $manager?->contextFor($view, $explicit) ?? $explicit;
                $context['errors'] = self::errorBag();
                $read = self::readAuthoritativeSource($view, $viewFilePath);
                $source = $read['source'];
                $compiled = self::processBladeSyntax($source, $view, false, true, $name);
                self::executeParsedContent($compiled, $context, $view, $state, $name,
                    $source, $viewFilePath, $read['physical']);
            } finally {
                $state->leaveLayout();
            }

            $html = (string) ob_get_clean();
            $finished = $state->finishFragmentAssets($html);
            return new FragmentRenderResult($finished['html'], $finished['stacks']);
        } catch (\Throwable $error) {
            // Failed bodies and nested captures may have opened several output
            // buffers. None may become a partial HTTP or later View response.
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw self::renderFailure($view, $error);
        } finally {
            if (--self::$renderDepth === 0) {
                self::$sections = [];
                self::$sectionStack = [];
                self::$parentView = null;
            }
            $manager?->endRender();
        }
    }

    private static function renderInternal($view, $data,
        array $__squehub_inherited, LayoutRenderState $__squehub_layoutState,
        ?string $fromView = null, int $sourceLine = 1)
    {
        $resolution = self::resolveViewFileStatus((string) $view, '.squehub.php');
        if ($resolution['status'] !== 'found') {
            if ($fromView !== null) {
                throw new CompilerException($fromView, $sourceLine,
                    $resolution['status'] === 'namespace'
                        ? 'Layout "' . (string) $view . '" has an unavailable Package namespace.'
                        : ($resolution['status'] === 'unsafe'
                        ? 'Layout "' . (string) $view . '" is unsafe or unavailable.'
                        : 'Layout "' . (string) $view . '" was not found.'), '@extends');
            }
            throw new ViewNotFoundException(self::diagnosticName((string) $view),
                $resolution['status'] === 'unsafe');
        }
        $viewFilePath = $resolution['path'];

        $__squehub_layoutState->enterLayout((string) $view, $viewFilePath,
            $fromView, $sourceLine);
        try {
            return self::renderLayoutFrame($view, $data, $__squehub_inherited,
                $__squehub_layoutState, $viewFilePath, $fromView === null);
        } catch (\Throwable $error) {
            throw self::renderFailure((string) $view, $error);
        } finally {
            $__squehub_layoutState->leaveLayout();
        }
    }

    /** Resolve one template, then traverse its declared parent after capture. */
    private static function renderLayoutFrame($view, $data,
        array $__squehub_inherited, LayoutRenderState $__squehub_layoutState,
        string $viewFilePath, bool $allowFragments)
    {

        // Keep renderer locals out of extract()'s reach. A View receives values,
        // never the mutable Application-owned registration store.
        $__squehub_view = (string) $view;
        $__squehub_viewFilePath = $viewFilePath;
        $__squehub_explicit = self::acceptedData($data);
        $__squehub_inherited = self::acceptedData($__squehub_inherited);
        $__squehub_manager = self::contextManager();
        $__squehub_context = $__squehub_manager?->contextFor(
            $__squehub_view, $__squehub_explicit, $__squehub_inherited)
            ?? array_replace($__squehub_inherited, $__squehub_explicit);
        $__squehub_context['errors'] = self::errorBag();
        // Compile source before executing it. Running a raw PHP View first
        // would put request data into the shared compiled cache.
        $__squehub_read = self::readAuthoritativeSource($__squehub_view,
            $__squehub_viewFilePath);
        $__squehub_source = $__squehub_read['source'];
        $__squehub_parsedContent = self::processBladeSyntax($__squehub_source,
            $__squehub_view, false, $allowFragments);

        // Section capture and yields execute against this render's local state.
        // Compiled PHP never receives a snapshot of request-specific content.
        $frameBufferLevel = ob_get_level();
        ob_start();
        try {
            $__squehub_renderedData = self::executeParsedContent($__squehub_parsedContent,
                $__squehub_context, $__squehub_view, $__squehub_layoutState, null,
                $__squehub_source, $__squehub_viewFilePath, $__squehub_read['physical']);
            $frameOutput = (string) ob_get_clean();
        } catch (\Throwable $error) {
            while (ob_get_level() > $frameBufferLevel) {
                ob_end_clean();
            }
            throw $error;
        }
        $__squehub_parent = $__squehub_layoutState->parent();
        if ($__squehub_parent !== null) {
            // Child locals and resolved context reach the parent. The optional
            // declaration overlay remains explicit and wins at this boundary.
            // Loose output outside child sections is not part of the final
            // layout structure; only the outermost layout emits frame output.
            $__squehub_layoutData = array_replace($__squehub_renderedData,
                self::acceptedData($__squehub_parent['overlay']));
            self::renderInternal($__squehub_parent['name'], $__squehub_layoutData,
                [], $__squehub_layoutState, $__squehub_view,
                $__squehub_parent['line']);
        } else {
            echo $frameOutput;
        }
    }

    /**
     * Render a partial in the current tree. Optional inclusion ignores only a
     * genuinely absent logical View; unsafe resolution and rendering failures
     * retain their normal exception boundary.
     */
    public static function include($view, $data = [], bool $__squehub_inherited = false,
        $__squehub_overlay = [], ?LayoutRenderState $__squehub_layoutState = null,
        bool $__squehub_optional = false, ?string $__squehub_fromView = null,
        int $__squehub_sourceLine = 1)
    {
        $manager = self::contextManager();
        $manager?->beginRender();
        $standalone = $__squehub_layoutState === null;
        $bufferLevel = ob_get_level();
        if ($standalone) {
            $__squehub_layoutState = new LayoutRenderState([], new AssetRenderState(
                $manager?->assets()->snapshot() ?? [], self::urlBasePath($manager),
                self::assetMapper($manager), self::frontendManager($manager)));
            ob_start();
        }
        try {
            if (!is_string($view) || LogicalViewName::parse($view) === null) {
                throw new CompilerException($__squehub_fromView ?? 'inline', $__squehub_sourceLine,
                    'Included View name must be a logical View name.');
            }
            $resolution = self::resolveViewFileStatus($view, '.squehub.php');
            if ($resolution['status'] === 'missing' && $__squehub_optional) {
                if ($standalone) {
                    ob_end_clean();
                }
                return;
            }
            if ($resolution['status'] !== 'found') {
                $reason = $resolution['status'] === 'namespace'
                    ? 'Included View "' . $view . '" has an unavailable Package namespace.'
                    : ($resolution['status'] === 'unsafe'
                    ? ($__squehub_fromView === null ? 'Included View is unsafe or unavailable.'
                        : 'Included View "' . $view . '" is unsafe or unavailable.')
                    : ($__squehub_fromView === null ? 'Included View was not found.'
                        : 'Included View "' . $view . '" was not found.'));
                throw new CompilerException($__squehub_fromView ?? $view,
                    $__squehub_sourceLine, $reason,
                    $__squehub_optional ? '@includeOptional' : '@include');
            }
            $viewFilePath = $resolution['path'];

            // Enter the active physical chain before composers or compilation.
            // A cycle never receives partial context or asset participation.
            $__squehub_layoutState->enterInclude($view, $viewFilePath,
                $__squehub_fromView, $__squehub_sourceLine);
            try {
                if (!is_array($data)) {
                    throw new CompilerException($__squehub_fromView ?? $view,
                        $__squehub_sourceLine, 'Included View data must be an array.');
                }
                $__squehub_explicitData = $__squehub_inherited ? $__squehub_overlay : $data;
                $__squehub_explicit = self::acceptedIncludeData($__squehub_explicitData,
                    $__squehub_fromView ?? $view, $__squehub_sourceLine);
                $__squehub_inheritedData = $__squehub_inherited ? self::acceptedData($data) : [];
                $__squehub_context = $manager?->contextFor(
                    $view, $__squehub_explicit, $__squehub_inheritedData)
                    ?? array_replace($__squehub_inheritedData, $__squehub_explicit);
                // Active iteration metadata cannot be replaced by an overlay
                // or composer. Outside an iterable loop `loop` is ordinary data.
                if (($__squehub_inheritedData['loop'] ?? null) instanceof LoopContext) {
                    $__squehub_context['loop'] = $__squehub_inheritedData['loop'];
                }
                $__squehub_context['errors'] = self::errorBag();
                $__squehub_read = self::readAuthoritativeSource($view, $viewFilePath);
                $__squehub_source = $__squehub_read['source'];
                $__squehub_parsed = self::processBladeSyntax($__squehub_source,
                    $view, false, false);
                self::executeParsedContent($__squehub_parsed, $__squehub_context,
                    $view, $__squehub_layoutState, null, $__squehub_source,
                    $viewFilePath, $__squehub_read['physical']);
            } finally {
                $__squehub_layoutState->leaveInclude();
            }
            if ($standalone) {
                echo $__squehub_layoutState->finishAssets((string) ob_get_clean());
            }
        } catch (\Throwable $error) {
            while ($standalone && ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw self::renderFailure(is_string($view) ? $view : 'inline', $error);
        } finally {
            $manager?->endRender();
        }
    }

    /**
     * Open a component in the caller's render tree. Its source uses the same
     * contained View resolver as pages and partials, while props and HTML
     * attributes remain separate, explicit invocation inputs.
     */
    public static function beginComponent(string $view, mixed $props, mixed $attributes,
        LayoutRenderState $state, string $fromView, int $sourceLine,
        mixed $loop = null): void
    {
        $componentName = LogicalViewName::parse($view);
        if ($componentName === null
            || !str_starts_with($componentName->local(), 'Components.')
            || $componentName->local() === 'Components.') {
            throw new CompilerException($fromView, $sourceLine,
                'Component name must resolve under Components.');
        }
        if (!is_array($props) || !is_array($attributes)) {
            throw new CompilerException($fromView, $sourceLine,
                'Component props and attributes must be separate arrays.');
        }
        $resolution = self::resolveViewFileStatus($view, '.squehub.php');
        if ($resolution['status'] !== 'found') {
            throw new CompilerException($fromView, $sourceLine,
                $resolution['status'] === 'namespace'
                    ? 'Component "' . $view . '" has an unavailable Package namespace.'
                    : ($resolution['status'] === 'unsafe'
                    ? 'Component "' . $view . '" is unsafe or unavailable.'
                    : 'Component "' . $view . '" was not found.'), '@component');
        }
        $state->beginComponent($view, $resolution['path'], $props,
            new ComponentAttributeBag($attributes),
            $loop instanceof LoopContext ? $loop : null, $fromView, $sourceLine);
    }

    /**
     * Render the component after all slot bodies have run exactly once in the
     * caller scope. The component gets only declared props and its small set
     * of framework runtime bindings, never arbitrary caller/shared variables.
     */
    public static function endComponent(LayoutRenderState $state): void
    {
        $component = $state->enterComponentTemplate();
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $read = self::readAuthoritativeSource($component['name'], $component['path']);
            $source = $read['source'];
            $compiled = self::processBladeSyntax($source, $component['name'], true, false);
            $bindings = [
                'slot' => $component['slot'],
                'slots' => $component['slots'],
                'attributes' => $component['attributes'],
            ];
            if ($component['loop'] !== null) {
                $bindings['loop'] = $component['loop'];
            }
            self::executeParsedContent($compiled, $bindings, $component['name'], $state,
                null, $source, $component['path'], $read['physical'], 'component');
            $output = (string) ob_get_clean();
        } catch (\Throwable $error) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw self::renderFailure($component['name'], $error);
        } finally {
            $state->leaveComponent();
        }
        echo $output;
    }


    /**
     * Use a layout/view as parent to extend from
     * @param string $view
     * @param array $data
     */
    public static function extends($view, $data = [], bool $__squehub_inherited = false,
        array $__squehub_overlay = [])
    {
        $manager = self::contextManager();
        $manager?->beginRender();
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $viewFilePath = self::getViewPath($view);
            if ($viewFilePath === false) {
                throw new ViewNotFoundException(self::diagnosticName((string) $view));
            }

            $__squehub_view = (string) $view;
            $__squehub_inheritedData = $__squehub_inherited ? self::acceptedData($data) : [];
            $__squehub_explicit = $__squehub_inherited
                ? self::acceptedData($__squehub_overlay) : self::acceptedData($data);
            // The renderer resolves the layout composer once after inherited
            // child data and the explicit overlay have been combined.
            $__squehub_layoutData = array_replace($__squehub_inheritedData, $__squehub_explicit);
            self::$parentView = $view;
            // Legacy Mail still invokes this public helper. Seed its captured
            // sections into an isolated render tree; compiled templates use
            // deferred @extends declarations instead of calling this helper.
            $state = new LayoutRenderState(self::$sections, new AssetRenderState(
                $manager?->assets()->snapshot() ?? [], self::urlBasePath($manager),
                self::assetMapper($manager), self::frontendManager($manager)));
            self::renderInternal($__squehub_view, $__squehub_layoutData, [], $state);
            echo $state->finishAssets((string) ob_get_clean());
        } catch (\Throwable $error) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw self::renderFailure((string) $view, $error);
        } finally {
            $manager?->endRender();
        }
    }

    /** @param array<array-key, mixed> $data @return array<array-key, mixed> */
    private static function acceptedData(array $data): array
    {
        // Keep explicit legacy keys that extract() ignores, but never let data
        // replace PHP's special variables or renderer-owned local names.
        unset($data['errors'], $data['GLOBALS'], $data['this']);
        foreach (array_keys($data) as $name) {
            if (is_string($name) && str_starts_with($name, '__squehub_')) {
                unset($data[$name]);
            }
        }
        return $data;
    }

    /**
     * Overlay keys become PHP variables in the partial. Preserve the existing
     * reserved-name filtering, but reject other invalid identifiers instead
     * of silently dropping application-supplied data.
     *
     * @return array<string, mixed>
     */
    private static function acceptedIncludeData(mixed $data, string $sourceView, int $sourceLine): array
    {
        if (!is_array($data)) {
            throw new CompilerException($sourceView, $sourceLine,
                'Included View data must be an array.');
        }
        $data = self::acceptedData($data);
        foreach (array_keys($data) as $name) {
            if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
                throw new CompilerException($sourceView, $sourceLine,
                    'Included View data keys must be safe PHP variable names.');
            }
        }
        return $data;
    }

    /**
     * Keep invalid or oversized caller-supplied names out of developer pages.
     * Static template dependencies are checked by the compiler before use.
     */
    private static function diagnosticName(string $view): string
    {
        return strlen($view) <= 128
            && LogicalViewName::parse($view) !== null
                ? $view : '[invalid logical name]';
    }

    /**
     * Preserve explicit template diagnostics and HTTP control flow. An
     * arbitrary PHP failure is attributed to the active logical View, while
     * its original Throwable remains chained for internal investigation.
     */
    private static function renderFailure(string $view, \Throwable $error): \Throwable
    {
        if ($error instanceof CompilerException || $error instanceof ViewNotFoundException
            || $error instanceof FragmentNotFoundException
            || $error instanceof InvalidFragmentNameException
            || $error instanceof ViewRenderException || $error instanceof ViewHttpException
            || $error instanceof \App\Validation\ValidationException
            || $error instanceof \App\Authorization\AuthorizationException
            || $error instanceof \App\Security\Csrf\CsrfException
            || $error instanceof \App\RateLimit\RateLimitExceededException
            || $error instanceof \App\Api\ApiError) {
            return $error;
        }
        if ($error instanceof \App\Http\Exception\HttpException) {
            return new ViewHttpException(self::diagnosticName($view), $error);
        }
        return new ViewRenderException(self::diagnosticName($view), $error);
    }


    /**
     * Start capturing a section block content
     * @param string $section Section name
     */
    public static function startSection($section)
    {
        array_push(self::$sectionStack, $section);
        ob_start();
    }

    /**
     * End capturing a section block and save content
     */
    public static function endSection()
    {
        if (!empty(self::$sectionStack)) {
            $section = array_pop(self::$sectionStack);
            self::$sections[$section] = ob_get_clean();
        }
    }

    /**
     * Output the content of a section or default
     * @param string $section Section name
     * @param string $default Default string if section empty
     */
    public static function yieldSection($section, $default = '')
    {
        echo self::$sections[$section] ?? $default;
    }

    // Optional helper methods for control structures outputting raw PHP tags

    public static function foreach($array, $alias)
    {
        echo "<?php foreach ($array as $alias): ?>";
    }
    public static function endforeach()
    {
        echo "<?php endforeach; ?>";
    }
    public static function for($start, $condition, $increment)
    {
        echo "<?php for ($start; $condition; $increment): ?>";
    }
    public static function endfor()
    {
        echo "<?php endfor; ?>";
    }
    public static function while($condition)
    {
        echo "<?php while ($condition): ?>";
    }
    public static function endwhile()
    {
        echo "<?php endwhile; ?>";
    }
    public static function do()
    {
        echo "<?php do { ?>";
    }
    public static function enddo()
    {
        echo "<?php } while (true); ?>";
    }

    /**
     * Resolve a view name to a full file path by searching known view paths
     * Uses dot notation (dots replaced with directory separators)
     * Adds .squehub.php extension
     *
     * @param string $view
     * @return string|false Full path if found, else false
     */
    private static function getViewPath($view)
    {
        return self::resolveViewFile((string) $view, '.squehub.php');
    }

    private static function resolveViewFile(string $view, string $extension)
    {
        $result = self::resolveViewFileStatus($view, $extension);
        return $result['status'] === 'found' ? $result['path'] : false;
    }

    /**
     * Optional includes need to distinguish absence from rejected links. The
     * resolver is shared with ordinary views, so every render uses the same
     * root precedence and realpath containment boundary.
     *
     * @return array{status: 'found'|'missing'|'unsafe'|'namespace', path: string|null, contribution?: ?Contribution}
     */
    private static function resolveViewFileStatus(string $view, string $extension): array
    {
        // A colon opts into the strict Package identity grammar. Ordinary
        // names keep their established slash and segment-casing behavior.
        if (str_contains($view, ':')) {
            $identity = LogicalViewName::parse($view);
            if ($identity === null || $identity->namespace() === null) {
                return ['status' => 'unsafe', 'path' => null];
            }
            return $extension === '.squehub.php'
                ? self::resolveNamespacedViewFileStatus($identity, $extension)
                : ['status' => 'missing', 'path' => null];
        }
        $segments = explode('/', str_replace(['.', '\\'], '/', $view));
        if (in_array('', $segments, true) || in_array('..', $segments, true)) {
            return ['status' => 'unsafe', 'path' => null];
        }
        foreach (self::getViewPaths() as $path) {
            $viewRoot = realpath($path);
            if ($viewRoot === false) {
                continue;
            }
            $unsafe = false;
            $resolved = self::resolveViewSegments(rtrim($path, '/\\'), $segments,
                $extension, $unsafe, $viewRoot);
            if ($unsafe) {
                return ['status' => 'unsafe', 'path' => null];
            }
            if ($resolved !== false) {
                // Explicit legacy View roots may be outside the selected
                // Application. Each root still contains its resolved files;
                // linked children cannot escape that configured boundary.
                $physical = realpath($resolved);
                if ($physical === false || !self::isWithin($physical, $viewRoot)) {
                    return ['status' => 'unsafe', 'path' => null];
                }
                $contribution = self::describeResolvedView($view, $resolved, $extension);
                return ['status' => 'found', 'path' => $resolved,
                    'contribution' => $contribution];
            }
        }

        return ['status' => 'missing', 'path' => null];
    }

    /** Resolve one activated Package namespace without changing the legacy root list. */
    private static function resolveNamespacedViewFileStatus(LogicalViewName $identity,
        string $extension): array
    {
        $namespace = $identity->namespace();
        $package = $namespace === null ? null : self::$packageManager?->viewNamespace($namespace);
        if ($package === null) {
            return ['status' => 'namespace', 'path' => null];
        }
        $base = realpath(self::$applicationRoot ?? self::legacyBasePath());
        if ($base === false || !is_dir($base)) {
            return ['status' => 'unsafe', 'path' => null];
        }
        try {
            PackageFiles::assertPhysical($package->path());
        } catch (\Throwable) {
            return ['status' => 'unsafe', 'path' => null];
        }
        $packageRoot = realpath($package->path());
        if ($packageRoot === false || !is_dir($packageRoot)
            || !self::isWithin($packageRoot, $base)) {
            return ['status' => 'unsafe', 'path' => null];
        }

        // The established Package convention selects Views before views.
        // Neither directory may be linked or reparsed away from this Package.
        $sourceRoot = null;
        foreach (['Views', 'views'] as $directory) {
            $candidate = $packageRoot . '/' . $directory;
            if (!is_dir($candidate) && !is_link($candidate)) {
                continue;
            }
            try {
                PackageFiles::assertPhysical($candidate);
            } catch (\Throwable) {
                return ['status' => 'unsafe', 'path' => null];
            }
            $physical = realpath($candidate);
            if ($physical === false || !is_dir($physical)
                || !self::samePhysicalPath(dirname($physical), $packageRoot)) {
                return ['status' => 'unsafe', 'path' => null];
            }
            $sourceRoot = $physical;
            break;
        }

        $segments = explode('.', $identity->local());
        // Check override roots in the same canonical/legacy order as
        // initViewPaths(). A present but unsafe override prevents fallback.
        foreach ([
            $base . '/Project/PackagesViews',
            $base . '/Project/packagesViews',
            $base . '/project/PackagesViews',
            $base . '/project/packagesViews',
        ] as $published) {
            if (!is_dir($published) && !is_link($published) && !file_exists($published)) {
                continue;
            }
            try {
                PackageFiles::assertPhysical($published);
            } catch (\Throwable) {
                return ['status' => 'unsafe', 'path' => null];
            }
            $publishedRoot = realpath($published);
            if ($publishedRoot === false || !is_dir($publishedRoot)
                || !self::isWithin($publishedRoot, $base)) {
                return ['status' => 'unsafe', 'path' => null];
            }
            $entries = @scandir($publishedRoot);
            if ($entries === false) {
                return ['status' => 'unsafe', 'path' => null];
            }
            // Inspect the directory entry itself: Windows must not select a
            // differently cased override for an exact logical namespace.
            if (!in_array($namespace, $entries, true)) {
                continue;
            }
            $override = $publishedRoot . '/' . $namespace;
            try {
                PackageFiles::assertPhysical($override);
            } catch (\Throwable) {
                return ['status' => 'unsafe', 'path' => null];
            }
            $overrideRoot = realpath($override);
            if ($overrideRoot === false || !is_dir($overrideRoot)
                || !self::isWithin($overrideRoot, $publishedRoot)) {
                return ['status' => 'unsafe', 'path' => null];
            }
            $unsafe = false;
            $resolved = self::resolveViewSegments($override, $segments,
                $extension, $unsafe, $overrideRoot);
            if ($unsafe) {
                return ['status' => 'unsafe', 'path' => null];
            }
            if ($resolved !== false) {
                $physical = realpath($resolved);
                if ($physical === false || !self::isWithin($physical, $overrideRoot)) {
                    return ['status' => 'unsafe', 'path' => null];
                }
                $shadow = null;
                if ($sourceRoot !== null) {
                    $shadowUnsafe = false;
                    $candidate = self::resolveViewSegments($sourceRoot, $segments,
                        $extension, $shadowUnsafe, $sourceRoot);
                    if (!$shadowUnsafe && $candidate !== false) {
                        $shadow = $candidate;
                    }
                }
                $contribution = self::describeResolvedView($identity->name(), $resolved,
                    $extension, $overrideRoot, 'override', $shadow);
                return ['status' => 'found', 'path' => $resolved,
                    'contribution' => $contribution];
            }
        }

        if ($sourceRoot !== null) {
            $unsafe = false;
            $resolved = self::resolveViewSegments($sourceRoot, $segments,
                $extension, $unsafe, $sourceRoot);
            if ($unsafe) {
                return ['status' => 'unsafe', 'path' => null];
            }
            if ($resolved !== false) {
                $physical = realpath($resolved);
                if ($physical === false || !self::isWithin($physical, $sourceRoot)) {
                    return ['status' => 'unsafe', 'path' => null];
                }
                $contribution = self::describeResolvedView($identity->name(), $resolved,
                    $extension, $sourceRoot, 'package');
                return ['status' => 'found', 'path' => $resolved,
                    'contribution' => $contribution];
            }
        }
        return ['status' => 'missing', 'path' => null];
    }

    private static function describeResolvedView(string $view, string $path, string $extension,
        ?string $selectedRoot = null, ?string $sourceKind = null,
        ?string $shadowPath = null): ?Contribution
    {
        $registry = self::$contributions;
        $base = realpath(self::$applicationRoot ?? self::legacyBasePath());
        $selected = realpath($path);
        if ($registry === null || $base === false || $selected === false
            || !self::isWithin($selected, $base)
            || $view === '' || strlen($view) > 512 || preg_match('/[\x00-\x1F\x7F]/', $view)) {
            return null;
        }
        $identity = str_contains($view, ':') ? LogicalViewName::parse($view) : null;
        if ($identity !== null && $identity->namespace() !== null) {
            $root = $selectedRoot === null ? false : realpath($selectedRoot);
            if ($root === false || !self::isWithin($root, $base)
                || !self::isWithin($selected, $root)
                || !in_array($sourceKind, ['override', 'package'], true)) {
                return null;
            }
            $namespace = $identity->namespace();
            $owner = $sourceKind === 'package'
                ? new ContributionOwner('package', $namespace)
                : new ContributionOwner('application', 'Project');
            $relative = self::relativePath($selected, $base);
            $metadata = [
                'namespace' => $namespace,
                'source_kind' => $sourceKind,
                'root' => self::relativePath($root, $base),
                'selected' => $relative,
            ];
            $shadow = $shadowPath === null ? false : realpath($shadowPath);
            if ($shadow !== false && self::isWithin($shadow, $base)) {
                $metadata['overrides'] = self::relativePath($shadow, $base);
            }
            $identifier = $identity->name() . ($extension === '.php' ? ':raw' : '');
            $registry->record('view', $identifier, $relative, $metadata, $owner);
            return new Contribution('view', $identifier, $owner, $relative, $metadata);
        }
        $owner = new ContributionOwner('application', 'Project');
        foreach (self::$packageManager?->active() ?? [] as $package) {
            $packageRoot = realpath($package->path());
            if ($packageRoot !== false && self::isWithin($selected, $packageRoot)) {
                $owner = new ContributionOwner('package', $package->name());
                break;
            }
        }
        $relative = self::relativePath($selected, $base);
        $root = null;
        $overrides = null;
        $segments = explode('/', str_replace(['.', '\\'], '/', $view));
        $selectedIndex = null;
        foreach (self::getViewPaths() as $index => $candidateRoot) {
            if (self::isWithin($selected, realpath($candidateRoot) ?: $candidateRoot)) {
                $root = self::relativePath(realpath($candidateRoot) ?: $candidateRoot, $base);
                $selectedIndex = $index;
                break;
            }
        }
        if ($selectedIndex !== null) {
            foreach (array_slice(self::getViewPaths(), $selectedIndex + 1) as $candidateRoot) {
                $candidate = self::resolveViewSegments(rtrim($candidateRoot, '/\\'), $segments, $extension);
                $real = $candidate === false ? false : realpath($candidate);
                if ($real !== false && self::isWithin($real, $base)) {
                    $overrides = self::relativePath($real, $base);
                    break;
                }
            }
        }
        $metadata = ['selected' => $relative];
        if ($root !== null) { $metadata['root'] = $root; }
        if ($overrides !== null) { $metadata['overrides'] = $overrides; }
        $identifier = $view . ($extension === '.php' ? ':raw' : '');
        $registry->record('view', $identifier, $relative, $metadata, $owner);
        return new Contribution('view', $identifier, $owner, $relative, $metadata);
    }

    private static function isWithin(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $prefix = $root . '/';
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    private static function relativePath(string $path, string $base): string
    {
        return str_replace('\\', '/', substr($path, strlen(rtrim($base, '/\\')) + 1));
    }

    private static function legacyBasePath(): string
    {
        return defined('BASE_DIR') ? (string) constant('BASE_DIR') : dirname(__DIR__, 2);
    }

    /**
     * Try the requested spelling first, then first-letter case variants. This
     * preserves old logical names such as home.welcome after templates move to
     * Home/Welcome without making unrelated names case insensitive.
     */
    private static function resolveViewSegments(string $directory, array $segments,
        string $extension, bool &$unsafe = false, ?string $root = null)
    {
        $root ??= realpath($directory) ?: $directory;
        $segment = array_shift($segments);
        foreach (array_unique([$segment, ucfirst($segment), lcfirst($segment)]) as $variant) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $variant;
            if ($segments === []) {
                $file = $candidate . $extension;
                // A broken or escaping link is a security rejection, not an
                // absent optional partial. Never execute its cached output.
                if (is_link($file)) {
                    $physical = realpath($file);
                    if ($physical === false || !self::isWithin($physical, $root)
                        || !is_file($physical)) {
                        $unsafe = true;
                        return false;
                    }
                }
                if (file_exists($file) && !is_file($file)) {
                    $unsafe = true;
                    return false;
                }
                if (is_file($file)) {
                    return $file;
                }
                // PHP may report a broken Windows reparse entry as neither a
                // link nor an existing file. Its directory entry is still
                // present and must not become an absent override fallback.
                if (self::unavailableEntryPresent($directory, $variant . $extension)) {
                    $unsafe = true;
                    return false;
                }
            } else {
                if (is_link($candidate)) {
                    $physical = realpath($candidate);
                    if ($physical === false || !self::isWithin($physical, $root)
                        || !is_dir($physical)) {
                        $unsafe = true;
                        return false;
                    }
                }
                if (file_exists($candidate) && !is_dir($candidate)) {
                    $unsafe = true;
                    return false;
                }
                if (is_dir($candidate)) {
                    $resolved = self::resolveViewSegments($candidate, $segments,
                        $extension, $unsafe, $root);
                    if ($unsafe || $resolved !== false) {
                        return $resolved;
                    }
                } elseif (self::unavailableEntryPresent($directory, $variant)) {
                    $unsafe = true;
                    return false;
                }
            }
        }

        return false;
    }

    /** A listed but unusable entry is unsafe, including an unreadable parent. */
    private static function unavailableEntryPresent(string $directory, string $name): bool
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return true;
        }
        foreach ($entries as $entry) {
            if (DIRECTORY_SEPARATOR === '\\'
                ? strcasecmp($entry, $name) === 0 : $entry === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read only the currently selected, contained source. A second resolution
     * rejects a source moved or linked outside its View root during the read;
     * compiled output never makes a previously safe path authoritative.
     *
     * @return array{source: string, physical: string}
     */
    private static function readAuthoritativeSource(string $view, string $path): array
    {
        $physical = self::currentSourcePhysical($view, $path);
        $source = @file_get_contents($path);
        if ($source === false) {
            self::currentSourcePhysical($view, $path, $physical);
            throw new \RuntimeException('Unable to read View source.');
        }
        self::currentSourcePhysical($view, $path, $physical);
        return ['source' => $source, 'physical' => $physical];
    }

    /** Verify the selected physical source again immediately before execution. */
    private static function currentSourcePhysical(string $view, string $path,
        ?string $expected = null): string
    {
        $resolution = self::resolveViewFileStatus($view, '.squehub.php');
        if ($resolution['status'] !== 'found') {
            throw new ViewNotFoundException(self::diagnosticName($view),
                $resolution['status'] === 'unsafe');
        }
        $physical = realpath($resolution['path']);
        $original = realpath($path);
        if ($physical === false || $original === false
            || !self::samePhysicalPath($physical, $original)
            || ($expected !== null && !self::samePhysicalPath($physical, $expected))) {
            throw new ViewNotFoundException(self::diagnosticName($view), true);
        }
        return $physical;
    }

    private static function samePhysicalPath(string $left, string $right): bool
    {
        $left = str_replace('\\', '/', $left);
        $right = str_replace('\\', '/', $right);
        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private static function compiledStore(): CompiledViewStore
    {
        return new CompiledViewStore(self::$applicationRoot ?? self::legacyBasePath());
    }

    /**
     * List only roots attached to registered, active Package namespaces.
     * Entries remain logical candidates; the resolver selects the effective
     * override or Package source before indexing or compilation.
     *
     * Warm collects failures per namespace and continues. Verification calls
     * without an error collector still fail on the first unsafe namespace.
     *
     * @param list<string>|null $errors
     * @return list<array{namespace:string,root:string}>
     */
    private static function namespacedSourceRoots(?array &$errors = null): array
    {
        $base = realpath(self::$applicationRoot ?? self::legacyBasePath());
        if ($base === false) {
            throw new \RuntimeException('A Package View source root is unavailable.');
        }
        $roots = [];
        foreach (self::$packageManager?->viewNamespaces() ?? [] as $namespace => $package) {
            $namespaceRoots = [];
            try {
                foreach ([
                    $base . '/Project/PackagesViews',
                    $base . '/Project/packagesViews',
                    $base . '/project/PackagesViews',
                    $base . '/project/packagesViews',
                ] as $published) {
                    if (!is_dir($published) && !is_link($published) && !file_exists($published)) {
                        continue;
                    }
                    PackageFiles::assertPhysical($published);
                    $publishedRoot = realpath($published);
                    if ($publishedRoot === false || !is_dir($publishedRoot)
                        || !self::isWithin($publishedRoot, $base)) {
                        throw new \RuntimeException('A Package View override root is unsafe.');
                    }
                    $entries = @scandir($publishedRoot);
                    if ($entries === false) {
                        throw new \RuntimeException('A Package View override root is unavailable.');
                    }
                    if (!in_array($namespace, $entries, true)) {
                        continue;
                    }
                    $override = $publishedRoot . '/' . $namespace;
                    PackageFiles::assertPhysical($override);
                    $physical = realpath($override);
                    if ($physical === false || !is_dir($physical)
                        || !self::isWithin($physical, $publishedRoot)) {
                        throw new \RuntimeException('A Package View override root is unsafe.');
                    }
                    $namespaceRoots[] = ['namespace' => $namespace, 'root' => $override];
                }
                PackageFiles::assertPhysical($package->path());
                $packageRoot = realpath($package->path());
                if ($packageRoot === false || !self::isWithin($packageRoot, $base)) {
                    throw new \RuntimeException('A Package View source root is unsafe.');
                }
                foreach (['Views', 'views'] as $directory) {
                    $candidate = $packageRoot . '/' . $directory;
                    if (!is_dir($candidate) && !is_link($candidate)) {
                        continue;
                    }
                    PackageFiles::assertPhysical($candidate);
                    $physical = realpath($candidate);
                    if ($physical === false || !is_dir($physical)
                        || !self::samePhysicalPath(dirname($physical), $packageRoot)) {
                        throw new \RuntimeException('A Package View source root is unsafe.');
                    }
                    $namespaceRoots[] = ['namespace' => $namespace, 'root' => $physical];
                    break;
                }
            } catch (\Throwable $error) {
                $message = 'Package View namespace "' . $namespace
                    . '" has an unsafe source root.';
                if ($errors === null) {
                    throw new \RuntimeException($message, 0, $error);
                }
                $errors[] = $message;
                continue;
            }
            foreach ($namespaceRoots as $root) {
                $roots[] = $root;
            }
        }
        return $roots;
    }

    /**
     * Compile currently visible templates without invoking any template,
     * composer, provider, component, or request-specific render behavior.
     * Successful artifacts remain useful if a later source fails preflight.
     *
     * @return array{compiled: int, reused: int, failed: int, errors: list<string>}
     */
    public static function warm(): array
    {
        $counts = ['compiled' => 0, 'reused' => 0, 'failed' => 0, 'errors' => []];
        $store = self::compiledStore();
        $seen = [];
        try {
            $roots = array_map(static fn (string $root): array =>
                ['namespace' => null, 'root' => $root], self::getViewPaths());
        } catch (\Throwable) {
            $roots = [];
            ++$counts['failed'];
            $counts['errors'][] = 'A View source root could not be inspected.';
        }
        try {
            $namespaceErrors = [];
            $roots = array_merge($roots, self::namespacedSourceRoots($namespaceErrors));
            foreach ($namespaceErrors as $error) {
                ++$counts['failed'];
                $counts['errors'][] = $error;
            }
        } catch (\Throwable $error) {
            ++$counts['failed'];
            $counts['errors'][] = $error->getMessage();
        }
        foreach ($roots as $entry) {
            $root = $entry['root'];
            try {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                    $root, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ((!$file->isFile() && !$file->isLink())
                        || !str_ends_with($file->getFilename(), '.squehub.php')) {
                        continue;
                    }
                    $relative = substr(str_replace('\\', '/', $file->getPathname()),
                        strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1, -12);
                    $local = str_replace('/', '.', $relative);
                    // Components use a canonical logical prefix even when a
                    // case-tolerant project spells its physical folder lower.
                    if (strncasecmp($local, 'components.', 11) === 0) {
                        $local = 'Components.' . substr($local, 11);
                    }
                    $view = $entry['namespace'] === null
                        ? $local : $entry['namespace'] . '::' . $local;
                    if (LogicalViewName::parse($view) === null || isset($seen[$view])) {
                        continue;
                    }
                    $seen[$view] = true;
                    try {
                        $resolution = self::resolveViewFileStatus($view, '.squehub.php');
                        if ($resolution['status'] !== 'found') {
                            throw new ViewNotFoundException(self::diagnosticName($view),
                                $resolution['status'] === 'unsafe');
                        }
                        $read = self::readAuthoritativeSource($view, $resolution['path']);
                        $component = str_starts_with($local, 'Components.');
                        $compiled = self::processBladeSyntax($read['source'], $view,
                            $component, !$component);
                        self::currentSourcePhysical($view, $resolution['path'], $read['physical']);
                        $result = $store->prepare($view, $read['source'], $compiled,
                            $component ? 'component' : 'template');
                        ++$counts[$result['compiled'] ? 'compiled' : 'reused'];
                    } catch (\Throwable $error) {
                        ++$counts['failed'];
                        $counts['errors'][] = 'View "' . $view . '": ' . $error->getMessage();
                    }
                }
            } catch (\Throwable $error) {
                ++$counts['failed'];
                $counts['errors'][] = 'A View source root could not be inspected.';
            }
        }
        return $counts;
    }

    /** Remove only generated compiled View artifacts for the selected app. */
    public static function clearCompiled(): int
    {
        return self::compiledStore()->clear();
    }

    /**
     * Compile SqueHub template directives into PHP for later rendering.
     * CSRF remains a runtime expression so a cached view cannot capture one
     * visitor's session token for another visitor.
     *
     * @param string $content Raw view content
     * @return string Parsed PHP content
     */
    private static function processBladeSyntax(string $content, string $view = 'inline',
        bool $componentTemplate = false, bool $allowFragments = true,
        ?string $requestedFragment = null): string
    {
        return (new TemplateCompiler())->compile($content, $view, $componentTemplate,
            $allowFragments, $requestedFragment);
    }
    /**
     * Execute the compiled PHP content of a view safely
     *
     * @param string $__squehub_parsedContent Parsed PHP content
     * @param array $__squehub_data Data variables to extract into scope
     * @param string|null $__squehub_view View name (optional)
     */
    private static function executeParsedContent($__squehub_parsedContent,
        $__squehub_data = [], $__squehub_view = null,
        ?LayoutRenderState $__squehub_layoutState = null,
        ?string $__squehub_fragmentSelection = null,
        ?string $__squehub_source = null, ?string $__squehub_sourcePath = null,
        ?string $__squehub_physicalSource = null,
        string $__squehub_compileMode = 'template'): array
    {
        $__squehub_layoutState ??= new LayoutRenderState();
        $__squehub_data = self::acceptedData($__squehub_data);
        // Existing template expressions still receive ordinary local variables.
        // Internal inputs remain prefixed and cannot be replaced by View data.
        extract($__squehub_data);
        $errors = self::errorBag();
        // Fragment closures receive the resolved root data and framework
        // bindings, never locals introduced earlier by sibling template code.
        $__squehub_fragmentScope = $__squehub_data;
        $__squehub_fragmentScope['errors'] = $errors;

        // If no view name provided, use a temporary file (for inline or dynamic content)
        if ($__squehub_view === null) {
            $__squehub_tempFile = tempnam(sys_get_temp_dir(), 'view_') . '.php';
            file_put_contents($__squehub_tempFile, $__squehub_parsedContent);

            try {
                include $__squehub_tempFile;
            } finally {
                unlink($__squehub_tempFile);
            }
            return self::acceptedData(get_defined_vars());
        }

        $__squehub_store = self::compiledStore();
        if ($__squehub_source === null || $__squehub_sourcePath === null
            || $__squehub_physicalSource === null) {
            throw new \LogicException('A named compiled View requires its authoritative source.');
        }
        for ($__squehub_attempt = 0; $__squehub_attempt < 2; ++$__squehub_attempt) {
            $__squehub_prepared = $__squehub_store->prepare($__squehub_view,
                $__squehub_source, $__squehub_parsedContent, $__squehub_compileMode);
            $__squehub_cacheFile = $__squehub_prepared['path'];
            self::currentSourcePhysical($__squehub_view, $__squehub_sourcePath,
                $__squehub_physicalSource);
            if (!$__squehub_store->matches($__squehub_cacheFile, $__squehub_parsedContent)) {
                // A maintenance clear can remove the file after preparation.
                // Rebuild once rather than including a missing artifact.
                continue;
            }
            try {
                $__squehub_loaded = include $__squehub_cacheFile;
                if ($__squehub_loaded === false && self::artifactChanged(
                    $__squehub_store, $__squehub_cacheFile, $__squehub_parsedContent)) {
                    continue;
                }
                return self::acceptedData(get_defined_vars());
            } catch (\Throwable $error) {
                // A concurrent clear may remove the artifact after the last
                // integrity check. Retry only if the derived file changed;
                // ordinary template exceptions retain their original cause.
                if ($__squehub_attempt === 0 && self::artifactChanged(
                    $__squehub_store, $__squehub_cacheFile, $__squehub_parsedContent)) {
                    continue;
                }
                throw $error;
            }
        }
        throw new CompiledViewException('Compiled View artifact could not be loaded.');
    }

    /** A clear or external replacement may race the include after preparation. */
    private static function artifactChanged(CompiledViewStore $store, string $path,
        string $compiled): bool
    {
        clearstatcache(true, $path);
        return !$store->matches($path, $compiled);
    }
}
