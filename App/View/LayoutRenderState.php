<?php

declare(strict_types=1);

namespace App\View;

use App\View\Assets\AssetException;
use App\View\Assets\AssetRenderState;
use App\View\Compiler\CompilerException;

/**
 * Holds layout declarations and captured sections for one View::render() tree.
 * The child renders before its ancestors, so the first definition at a closer
 * inheritance depth wins without any process-wide section registry.
 */
final class LayoutRenderState
{
    /** @var list<array{name: string, identity: string}> */
    private array $layoutChain = [];

    /** @var list<array{type: string, name: string, identity: string}> */
    private array $fragmentChain = [];

    /** @var list<array<string, mixed>> */
    private array $components = [];

    /** @var list<array{type: string, name: string, depth: int, parent: ?array}> */
    private array $frames = [];

    /** @var array<string, array{content: string, depth: int}> */
    private array $sections = [];

    /** @var list<array{name: string, active: bool}> */
    private array $captures = [];

    private readonly AssetRenderState $assets;

    private ?int $assetCapture = null;

    /** @param array<string, string> $legacySections */
    public function __construct(array $legacySections = [], ?AssetRenderState $assets = null)
    {
        $this->assets = $assets ?? new AssetRenderState();
        foreach ($legacySections as $name => $content) {
            $this->sections[$name] = ['content' => $content, 'depth' => -1];
        }
    }

    /**
     * Compare physical source identity, including in-root aliases. Case folding
     * is limited to Windows; Linux view paths retain their case distinction.
     */
    public function enterLayout(string $name, string $path, ?string $fromView = null,
        int $sourceLine = 1): void
    {
        $identity = self::physicalIdentity($path, $fromView ?? $name, $sourceLine);
        foreach ($this->layoutChain as $ancestor) {
            if ($ancestor['identity'] === $identity) {
                $chain = $this->logicalDependencyChain('Layout', $name);
                throw new CompilerException($fromView ?? $name, $sourceLine,
                    'Circular layout inheritance: ' . implode(' -> ', $chain) . '.',
                    '@extends', $chain);
            }
        }

        $this->layoutChain[] = ['name' => $name, 'identity' => $identity];
        $this->frames[] = ['type' => 'layout', 'name' => $name,
            'depth' => count($this->layoutChain) - 1, 'parent' => null];
        $this->assets->participateLayout($name, count($this->layoutChain) - 1);
    }

    public function leaveLayout(): void
    {
        array_pop($this->frames);
        array_pop($this->layoutChain);
    }

    /**
     * Track only active partials. Repeated sibling includes are valid, while a
     * physical alias to an active page, layout, or partial is recursive.
     * Reject before the partial composer runs or its assets participate.
     */
    public function enterInclude(string $name, string $path, ?string $fromView = null,
        int $sourceLine = 1): void
    {
        $identity = self::physicalIdentity($path, $fromView ?? $name, $sourceLine);
        foreach (array_merge($this->layoutChain, $this->fragmentChain) as $ancestor) {
            if ($ancestor['identity'] === $identity) {
                $chain = $this->logicalDependencyChain('Include', $name);
                throw new CompilerException($fromView ?? $name, $sourceLine,
                    'Circular include dependency: ' . implode(' -> ', $chain) . '.',
                    '@include', $chain);
            }
        }

        $this->fragmentChain[] = ['type' => 'include', 'name' => $name, 'identity' => $identity];
        $this->frames[] = ['type' => 'include', 'name' => $name,
            'depth' => max(0, count($this->layoutChain) - 1), 'parent' => null];
        $this->assets->participateInclude($name);
    }

    public function leaveInclude(): void
    {
        array_pop($this->frames);
        array_pop($this->fragmentChain);
    }

    /**
     * A component is active during caller-scope slot capture and during its
     * own rendering. One physical chain catches component/include mixed cycles
     * without treating a later sibling invocation as recursive.
     *
     * @param array<string, mixed> $props
     */
    public function beginComponent(string $name, string $path, array $props,
        ComponentAttributeBag $attributes, ?LoopContext $loop, string $fromView,
        int $sourceLine): void
    {
        $identity = self::physicalIdentity($path, $fromView, $sourceLine);
        foreach (array_merge($this->layoutChain, $this->fragmentChain) as $ancestor) {
            if ($ancestor['identity'] !== $identity) {
                continue;
            }
            $chain = $this->logicalDependencyChain('Component', $name);
            throw new CompilerException($fromView, $sourceLine,
                'Circular component dependency: ' . implode(' -> ', $chain) . '.',
                '@component', $chain);
        }
        $this->fragmentChain[] = ['type' => 'component', 'name' => $name, 'identity' => $identity];
        // Slots are evaluated before the component template. Reserving its
        // participation here keeps parent resources ahead of nested children.
        $this->assets->participateComponent($name);
        $this->components[] = [
            'name' => $name, 'path' => $path, 'props' => $props,
            'attributes' => $attributes, 'loop' => $loop,
            'sourceView' => $fromView, 'sourceLine' => $sourceLine,
            'default' => [], 'slots' => [], 'activeSlot' => null,
            'phase' => 'capture',
        ];
        ob_start();
    }

    /** Named slot bodies execute in the caller scope and never enter default content. */
    public function startComponentSlot(string $name, string $fromView, int $sourceLine): void
    {
        $index = array_key_last($this->components);
        if ($index === null || $this->components[$index]['phase'] !== 'capture'
            || $this->components[$index]['activeSlot'] !== null) {
            throw new CompilerException($fromView, $sourceLine,
                '@slot requires an open component without another active named slot.');
        }
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $name) !== 1
            || array_key_exists($name, $this->components[$index]['slots'])) {
            throw new CompilerException($fromView, $sourceLine,
                'The named component slot is invalid or duplicated.');
        }
        $this->components[$index]['default'][] = (string) ob_get_clean();
        $this->components[$index]['activeSlot'] = $name;
        ob_start();
    }

    public function endComponentSlot(): void
    {
        $index = array_key_last($this->components);
        if ($index === null || $this->components[$index]['phase'] !== 'capture'
            || $this->components[$index]['activeSlot'] === null) {
            throw new ComponentException('No component slot capture is active.');
        }
        $name = $this->components[$index]['activeSlot'];
        $this->components[$index]['slots'][$name] = new ComponentSlot((string) ob_get_clean());
        $this->components[$index]['activeSlot'] = null;
        ob_start();
    }

    /**
     * Close caller-owned output and enter the component as the active asset
     * owner. The component remains on the physical chain until leaveComponent.
     *
     * @return array{name: string, path: string, attributes: ComponentAttributeBag, loop: ?LoopContext, slot: ComponentSlot, slots: SlotBag}
     */
    public function enterComponentTemplate(): array
    {
        $index = array_key_last($this->components);
        if ($index === null || $this->components[$index]['phase'] !== 'capture'
            || $this->components[$index]['activeSlot'] !== null) {
            throw new ComponentException('Component capture state is invalid.');
        }
        $this->components[$index]['default'][] = (string) ob_get_clean();
        $this->components[$index]['phase'] = 'template';
        $component = $this->components[$index];
        $this->frames[] = ['type' => 'component', 'name' => $component['name'],
            'depth' => max(0, count($this->layoutChain) - 1), 'parent' => null];
        $defaultHtml = implode('', $component['default']);
        return [
            'name' => $component['name'], 'path' => $component['path'],
            'attributes' => $component['attributes'], 'loop' => $component['loop'],
            // Indentation around only named slots is not default content.
            'slot' => new ComponentSlot(trim($defaultHtml) === '' ? '' : $defaultHtml),
            'slots' => new SlotBag($component['slots']),
        ];
    }

    public function leaveComponent(): void
    {
        $component = $this->components[array_key_last($this->components)] ?? null;
        if ($component === null || $component['phase'] !== 'template') {
            throw new ComponentException('No component template is active.');
        }
        array_pop($this->frames);
        array_pop($this->components);
        array_pop($this->fragmentChain);
    }

    /**
     * Validate the component's declared interface at runtime. Neither the
     * caller's locals nor shared View context are imported through this map.
     *
     * @return array<string, mixed>
     */
    public function bindComponentProps(mixed $schema): array
    {
        $component = $this->components[array_key_last($this->components)] ?? null;
        if ($component === null || $component['phase'] !== 'template' || !is_array($schema)) {
            throw new ComponentException('Component prop declaration must be an array.');
        }
        $declared = [];
        $bound = [];
        foreach ($schema as $key => $value) {
            $required = is_int($key);
            $name = $required ? $value : $key;
            if (!is_string($name) || !self::validPropName($name)) {
                throw new CompilerException($component['name'], 1,
                    'Component prop declaration contains an invalid or reserved name.');
            }
            if (isset($declared[$name])) {
                throw new CompilerException($component['name'], 1,
                    'Component prop "' . $name . '" is declared more than once.');
            }
            $declared[$name] = true;
            if (array_key_exists($name, $component['props'])) {
                $bound[$name] = $component['props'][$name];
            } elseif ($required) {
                throw new CompilerException($component['sourceView'], $component['sourceLine'],
                    'Required prop "' . $name . '" is missing for component "'
                    . $component['name'] . '".');
            } else {
                $bound[$name] = $value;
            }
        }
        foreach (array_keys($component['props']) as $name) {
            if (!is_string($name) || !self::validPropName($name)) {
                throw new CompilerException($component['sourceView'], $component['sourceLine'],
                    'Component "' . $component['name'] . '" prop name is invalid or reserved.');
            }
            if (!isset($declared[$name])) {
                throw new CompilerException($component['sourceView'], $component['sourceLine'],
                    'Unknown prop "' . $name . '" for component "'
                    . $component['name'] . '".');
            }
        }
        return $bound;
    }

    private static function validPropName(string $name): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1
            && !str_starts_with($name, '__squehub_')
            && !in_array($name, ['GLOBALS', 'this', 'slot', 'slots', 'attributes',
                'errors', 'loop', '_SERVER', '_GET', '_POST', '_COOKIE',
                '_FILES', '_ENV', '_REQUEST', '_SESSION'], true);
    }

    /** Physical identity is shared by layout and include cycle checks. */
    private static function physicalIdentity(string $path, string $view, int $line): string
    {
        $physical = realpath($path);
        if ($physical === false) {
            throw new CompilerException($view, $line, 'The View source is unavailable.');
        }
        $identity = str_replace('\\', '/', $physical);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($identity) : $identity;
    }

    /**
     * Display only logical labels. Physical identity remains the authority for
     * cycle detection, including two names that resolve through one symlink.
     *
     * @return list<string>
     */
    private function logicalDependencyChain(string $nextType, string $nextName): array
    {
        $chain = [];
        foreach ($this->layoutChain as $index => $frame) {
            $chain[] = ($index === 0 ? 'View ' : 'Layout ') . $frame['name'];
        }
        foreach ($this->fragmentChain as $frame) {
            $chain[] = ucfirst($frame['type']) . ' ' . $frame['name'];
        }
        $chain[] = $nextType . ' ' . $nextName;
        return $chain;
    }

    /** @param array<array-key, mixed> $overlay */
    public function declareParent(string $name, array $overlay, int $line): void
    {
        $index = count($this->frames) - 1;
        $frame = $this->frames[$index] ?? null;
        if ($frame === null || $frame['type'] !== 'layout') {
            throw new CompilerException($frame['name'] ?? $name, $line,
                '@extends is only valid in a page or layout, not an included View.');
        }
        if ($frame['parent'] !== null) {
            throw new CompilerException($frame['name'], $line,
                'A View may declare only one parent layout.');
        }
        $this->frames[$index]['parent'] = ['name' => $name, 'overlay' => $overlay,
            'line' => $line];
    }

    /** @return array{name: string, overlay: array, line: int}|null */
    public function parent(): ?array
    {
        $frame = $this->frames[array_key_last($this->frames)] ?? null;
        return $frame !== null && $frame['type'] === 'layout' ? $frame['parent'] : null;
    }

    /**
     * A skipped ancestor section never executes its body. This matters for
     * side effects and for a parent default that references unavailable data.
     */
    public function startSection(string $name): bool
    {
        foreach ($this->frames as $activeFrame) {
            if ($activeFrame['type'] === 'component') {
                throw new CompilerException($activeFrame['name'], 1,
                    'Components cannot declare layout sections.');
            }
        }
        $frame = $this->frames[array_key_last($this->frames)] ?? null;
        if ($frame === null) {
            throw new \LogicException('A section requires an active View render.');
        }
        $existing = $this->sections[$name] ?? null;
        $active = $existing === null || $existing['depth'] >= $frame['depth'];
        $this->captures[] = ['name' => $name, 'active' => $active];
        if ($active) {
            ob_start();
        }
        return $active;
    }

    public function endSection(): void
    {
        $capture = array_pop($this->captures);
        if ($capture === null) {
            throw new \LogicException('No section capture is active.');
        }
        if (!$capture['active']) {
            return;
        }
        $frame = $this->frames[array_key_last($this->frames)] ?? null;
        $this->sections[$capture['name']] = [
            'content' => (string) ob_get_clean(),
            'depth' => $frame['depth'] ?? 0,
        ];
    }

    public function hasSection(string $name): bool
    {
        return array_key_exists($name, $this->sections);
    }

    /** Already rendered section content must not be escaped a second time. */
    public function section(string $name): string
    {
        return $this->sections[$name]['content'] ?? '';
    }

    /** Template declarations use the currently executing logical View owner. */
    public function style(mixed $url, mixed $once = null): void
    {
        $this->assets->style($this->currentOwner(), $url, $once);
    }

    public function script(mixed $url, mixed $once = null): void
    {
        $this->assets->script($this->currentOwner(), $url, $once);
    }

    /** Register an optional frontend entry for this exact executing View. */
    public function frontend(string $name): void
    {
        $this->assets->frontend($this->currentOwner(), $name);
    }

    public function stack(string $name): string
    {
        return $this->assets->stack($name);
    }

    /** A captured push is registered where it opens, not where it closes. */
    public function startAssetBlock(string $stack, mixed $once = null, bool $prepend = false): void
    {
        if ($this->assetCapture !== null) {
            throw new AssetException('Asset push/prepend blocks cannot be nested.');
        }
        $this->assetCapture = $this->assets->reserveBlock(
            $this->currentOwner(), $stack, $once, $prepend);
        ob_start();
    }

    public function endAssetBlock(): void
    {
        if ($this->assetCapture === null) {
            throw new AssetException('No asset push/prepend block is active.');
        }
        $index = $this->assetCapture;
        $this->assetCapture = null;
        $this->assets->completeBlock($index, (string) ob_get_clean());
    }

    /** Finalize after the complete layout tree, including later declarations. */
    public function finishAssets(string $output): string
    {
        if ($this->assetCapture !== null) {
            throw new AssetException('An asset push/prepend block was not closed.');
        }
        return $this->assets->finalize($output);
    }

    /**
     * Finalize a selected Fragment without relying on Layout @stack positions.
     * The caller receives every stack owned by content that actually ran.
     *
     * @return array{html: string, stacks: array<string, string>}
     */
    public function finishFragmentAssets(string $output): array
    {
        if ($this->assetCapture !== null) {
            throw new AssetException('An asset push/prepend block was not closed.');
        }
        return $this->assets->finalizeFragment($output);
    }

    private function currentOwner(): string
    {
        $frame = $this->frames[array_key_last($this->frames)] ?? null;
        if ($frame === null) {
            throw new AssetException('An asset declaration requires an active View.');
        }
        return $frame['name'];
    }
}
