<?php

declare(strict_types=1);

namespace App\View\Compiler;

/**
 * Validates template blocks while emitting ordinary PHP control flow.
 * One instance belongs to one compilation; no runtime values enter its stack.
 */
final class ControlFlowCompiler
{
    private const DIRECTIVES = [
        'if' => true, 'elseif' => true, 'else' => true, 'endif' => true,
        'unless' => true, 'endunless' => true,
        'switch' => true, 'case' => true, 'default' => true,
        'break' => true, 'endswitch' => true,
        'foreach' => true, 'endforeach' => true,
        'forelse' => true, 'empty' => true, 'endforelse' => true,
        'for' => true, 'endfor' => true,
        'while' => true, 'endwhile' => true, 'continue' => true,
        'endauth' => true, 'endguest' => true,
        'endcan' => true, 'endcannot' => true, 'endsession' => true,
    ];

    /** @var list<array{type: string, line: int, elseSeen: bool, defaultSeen: bool, labelSeen: bool, emptySeen: bool, loopId: int, errorId: int, sessionId: int, componentSlots: array<string, int>}> */
    private array $frames = [];

    private readonly ForeachClauseParser $foreachParser;

    private int $nextLoopId = 0;

    private int $nextErrorId = 0;

    private int $nextSessionId = 0;

    /** @var array<string, int> */
    private array $declaredSections = [];

    /** @var array<string, int> Fragment names are unique within one owning View. */
    private array $declaredFragments = [];

    private bool $layoutDeclared = false;

    public function __construct(private readonly string $view)
    {
        $this->foreachParser = new ForeachClauseParser();
    }

    public static function supports(string $name): bool
    {
        return isset(self::DIRECTIVES[$name]);
    }

    /**
     * PHP forbids inline HTML between switch and its first label. Keep that
     * interval in PHP mode, where template indentation is harmless whitespace.
     */
    public function acceptBodyToken(string $value, int $line): void
    {
        if ($this->awaitingSwitchLabel() && trim($value) !== '') {
            $firstContent = strspn($value, " \t\n\r\0\x0B");
            $sourceLine = $line + substr_count(substr($value, 0, $firstContent), "\n");
            throw new CompilerException($this->view, $sourceLine,
                '@switch requires @case or @default before template content.');
        }
    }

    public function emit(string $name, string $body, int $line): string
    {
        if ($this->awaitingSwitchLabel() && !in_array($name, ['case', 'default', 'endswitch'], true)) {
            throw new CompilerException($this->view, $line,
                '@switch requires @case or @default before @' . $name . '.');
        }

        return match ($name) {
            'if', 'unless', 'switch' => $this->open($name, $body, $line),
            'foreach', 'forelse' => $this->openIterable($name, $body, $line),
            'for', 'while' => $this->openNativeLoop($name, $body, $line),
            'elseif' => $this->emitElseif($body, $line),
            'else' => $this->emitElse($line),
            'empty' => $this->emitEmpty($line),
            'endif', 'endunless', 'endswitch', 'endforeach', 'endforelse',
            'endfor', 'endwhile', 'endauth', 'endguest', 'endcan',
                'endcannot', 'endsession' => $this->close($name, $line),
            'case', 'default' => $this->emitLabel($name, $body, $line),
            'break' => $this->emitBreak($line),
            'continue' => $this->emitContinue($line),
            default => throw new CompilerException($this->view, $line,
                'Unsupported control directive @' . $name . '.'),
        };
    }

    public function finish(): void
    {
        $frame = end($this->frames);
        if ($frame !== false) {
            throw new CompilerException($this->view, $frame['line'],
                'Unterminated @' . $frame['type'] . ' opened at line ' . $frame['line'] . '.');
        }
    }

    /** Layout selection is structural and cannot depend on a runtime branch. */
    public function declareExtends(int $line): void
    {
        if ($this->frames !== []) {
            throw new CompilerException($this->view, $line,
                '@extends must be declared at the template level.');
        }
        if ($this->layoutDeclared) {
            throw new CompilerException($this->view, $line,
                'A View may declare only one @extends parent layout.');
        }
        $this->layoutDeclared = true;
    }

    /** A section name may be overridden by an ancestor, but not redeclared here. */
    public function openSection(string $name, int $line): void
    {
        foreach ($this->frames as $frame) {
            if ($frame['type'] === 'fragment') {
                throw new CompilerException($this->view, $line,
                    '@section cannot be declared inside @fragment.');
            }
            if ($frame['type'] === 'section') {
                throw new CompilerException($this->view, $line,
                    '@section cannot be nested inside another section.');
            }
        }
        if (isset($this->declaredSections[$name])) {
            throw new CompilerException($this->view, $line,
                'Duplicate @section(' . $name . ') declared at line '
                    . $this->declaredSections[$name] . '.');
        }
        $this->declaredSections[$name] = $line;
        $this->push('section', $line);
    }

    public function closeSection(int $line): void
    {
        $this->requireTop('section', 'endsection', $line);
        array_pop($this->frames);
    }

    /** Fragments have static root/section placement and cannot nest in runtime flow. */
    public function openFragment(string $name, int $line): void
    {
        if ($this->frames !== [] && !(count($this->frames) === 1
            && $this->frames[0]['type'] === 'section')) {
            $parent = end($this->frames);
            throw new CompilerException($this->view, $line,
                '@fragment must be declared at the View root or directly inside @section; '
                . 'it cannot be nested inside @' . $parent['type'] . '.');
        }
        if (isset($this->declaredFragments[$name])) {
            throw new CompilerException($this->view, $line,
                'Duplicate @fragment(' . $name . ') declared at line '
                . $this->declaredFragments[$name] . '.');
        }
        $this->declaredFragments[$name] = $line;
        $this->push('fragment', $line);
    }

    public function closeFragment(int $line): void
    {
        $this->requireTop('fragment', 'endfragment', $line);
        array_pop($this->frames);
    }

    /** Asset captures may contain control flow, but another capture is ambiguous. */
    public function openAssetBlock(string $type, int $line): void
    {
        if ($type !== 'push' && $type !== 'prepend') {
            throw new \LogicException('Unsupported asset block type.');
        }
        foreach ($this->frames as $frame) {
            if ($frame['type'] === 'push' || $frame['type'] === 'prepend') {
                throw new CompilerException($this->view, $line,
                    '@' . $type . ' cannot be nested inside @' . $frame['type']
                    . ' opened at line ' . $frame['line'] . '.');
            }
        }
        $this->push($type, $line);
    }

    public function closeAssetBlock(string $type, int $line): void
    {
        if ($type !== 'push' && $type !== 'prepend') {
            throw new \LogicException('Unsupported asset block type.');
        }
        $this->requireTop($type, 'end' . $type, $line);
        array_pop($this->frames);
    }

    /** Components are paired captures and may nest inside ordinary flow. */
    public function openComponent(int $line): void
    {
        $this->push('component', $line);
    }

    public function closeComponent(int $line): void
    {
        $this->requireTop('component', 'endcomponent', $line);
        array_pop($this->frames);
    }

    /** A named slot belongs to the nearest component, including when nested. */
    public function openSlot(string $name, int $line): void
    {
        $componentIndex = null;
        for ($index = count($this->frames) - 1; $index >= 0; --$index) {
            $type = $this->frames[$index]['type'];
            if ($type === 'component') {
                $componentIndex = $index;
                break;
            }
            if ($type === 'slot') {
                throw new CompilerException($this->view, $line,
                    '@slot cannot be nested inside another slot of the same component.');
            }
            if ($type === 'push' || $type === 'prepend') {
                throw new CompilerException($this->view, $line,
                    '@slot cannot open inside an asset capture block.');
            }
        }
        if ($componentIndex === null) {
            throw new CompilerException($this->view, $line,
                '@slot requires an open @component.');
        }
        $priorLine = $this->frames[$componentIndex]['componentSlots'][$name] ?? null;
        if ($priorLine !== null) {
            throw new CompilerException($this->view, $line,
                'Duplicate @slot(' . $name . ') declared at line ' . $priorLine . '.');
        }
        $this->frames[$componentIndex]['componentSlots'][$name] = $line;
        $this->push('slot', $line);
    }

    public function closeSlot(int $line): void
    {
        $this->requireTop('slot', 'endslot', $line);
        array_pop($this->frames);
    }

    /** One field-error block owns its temporary $message binding. */
    public function openError(int $line): int
    {
        foreach ($this->frames as $frame) {
            if ($frame['type'] === 'error') {
                throw new CompilerException($this->view, $line,
                    '@error cannot be nested inside another @error block.');
            }
        }
        $this->push('error', $line);
        $id = ++$this->nextErrorId;
        $this->frames[array_key_last($this->frames)]['errorId'] = $id;
        return $id;
    }

    public function closeError(int $line): int
    {
        $index = $this->requireTop('error', 'enderror', $line);
        $id = $this->frames[$index]['errorId'];
        array_pop($this->frames);
        return $id;
    }

    /** Presentation checks belong to the same structural stack as @if. */
    public function openPresentation(string $name, string $condition, int $line): string
    {
        if (!in_array($name, ['auth', 'guest', 'can', 'cannot'], true)) {
            throw new \LogicException('Unsupported presentation condition.');
        }
        $this->push($name, $line);
        return '<?php if (' . $condition . '): ?>';
    }

    /** Preserve a caller's $value while the current Session key is presented. */
    public function openSession(string $key, int $line): string
    {
        $id = ++$this->nextSessionId;
        $this->push('session', $line);
        $this->frames[array_key_last($this->frames)]['sessionId'] = $id;
        $prefix = '$__squehub_session_' . $id . '_';

        return '<?php ' . $prefix . "had = array_key_exists('value', get_defined_vars()); "
            . $prefix . 'saved = $value ?? null; try { '
            . $prefix . 'store = \\App\\Session\\Session::isAvailable() ? \\session() : null; '
            . 'if (' . $prefix . 'store !== null && '
            . $prefix . 'store->has(' . $key . ')): '
            . '$value = ' . $prefix . 'store->get(' . $key . '); ?>';
    }

    private function open(string $name, string $body, int $line): string
    {
        $expression = $this->expression($name, $body, $line);
        $this->push($name, $line);

        return match ($name) {
            'if' => '<?php if (' . $expression . '): ?>',
            'unless' => '<?php if (!(' . $expression . ')): ?>',
            // Keep PHP open until the first label, avoiding T_INLINE_HTML in
            // the part of a native switch where PHP accepts labels only.
            'switch' => '<?php switch (' . $expression . '):',
            default => throw new \LogicException('Unsupported opening control directive.'),
        };
    }

    private function openNativeLoop(string $name, string $body, int $line): string
    {
        $expression = $this->expression($name, $body, $line);
        $this->push($name, $line);
        return '<?php ' . $name . ' (' . $expression . '): ?>';
    }

    private function openIterable(string $name, string $body, int $line): string
    {
        $clause = $this->foreachParser->parse($body, $this->view, $line, $name);
        $id = ++$this->nextLoopId;
        $this->push($name, $line, $id);

        $had = $this->loopVariable($id, 'had');
        $prior = $this->loopVariable($id, 'prior');
        $parent = $this->loopVariable($id, 'parent');
        $sequence = $this->loopVariable($id, 'sequence');
        $position = $this->loopVariable($id, 'position');

        // A finite Traversable may be buffered at runtime, but the source
        // expression is evaluated once and metadata is set before the body.
        $open = '<?php ' . $had . " = array_key_exists('loop', get_defined_vars()); "
            . $prior . ' = $loop ?? null; '
            . $parent . ' = isset($loop) && $loop instanceof \\App\\View\\LoopContext ? $loop : null; '
            . $sequence . ' = \\App\\View\\LoopSequence::prepare(' . $clause['iterable'] . '); '
            . $position . ' = 0; try { ';
        if ($name === 'forelse') {
            $open .= 'if (' . $sequence . '->count() > 0): ';
        }
        return $open . 'foreach (' . $sequence . '->iterate() as ' . $clause['target']
            . '): $loop = new \\App\\View\\LoopContext(' . $position . '++, '
            . $sequence . '->count(), ' . $parent . '); ?>';
    }

    private function emitEmpty(int $line): string
    {
        $index = $this->requireTop('forelse', 'empty', $line);
        if ($this->frames[$index]['emptySeen']) {
            throw new CompilerException($this->view, $line, 'Duplicate @empty in @forelse.');
        }
        $this->frames[$index]['emptySeen'] = true;
        return '<?php endforeach; else: ?>';
    }

    private function emitElseif(string $body, int $line): string
    {
        $index = $this->requireTop('if', 'elseif', $line);
        if ($this->frames[$index]['elseSeen']) {
            throw new CompilerException($this->view, $line, '@elseif cannot follow @else.');
        }
        return '<?php elseif (' . $this->expression('elseif', $body, $line) . '): ?>';
    }

    private function emitElse(int $line): string
    {
        $index = count($this->frames) - 1;
        $type = $this->frames[$index]['type'] ?? null;
        if (!in_array($type, ['if', 'unless', 'auth', 'guest', 'can',
            'cannot', 'session'], true)) {
            throw new CompilerException($this->view, $line,
                '@else requires an open conditional directive.');
        }
        if ($this->frames[$index]['elseSeen']) {
            throw new CompilerException($this->view, $line, 'Duplicate @else.');
        }
        $this->frames[$index]['elseSeen'] = true;
        return '<?php else: ?>';
    }

    private function close(string $name, int $line): string
    {
        $type = match ($name) {
            'endif' => 'if',
            'endunless' => 'unless',
            'endswitch' => 'switch',
            'endforeach' => 'foreach',
            'endforelse' => 'forelse',
            'endfor' => 'for',
            'endwhile' => 'while',
            'endauth' => 'auth',
            'endguest' => 'guest',
            'endcan' => 'can',
            'endcannot' => 'cannot',
            'endsession' => 'session',
            default => throw new \LogicException('Unsupported closing control directive.'),
        };
        $index = $this->requireTop($type, $name, $line);
        $frame = $this->frames[$index];
        $pendingLabel = $type === 'switch' && !$frame['labelSeen'];
        array_pop($this->frames);

        if ($type === 'switch') {
            return $pendingLabel ? 'endswitch; ?>' : '<?php endswitch; ?>';
        }
        if ($type === 'foreach' || $type === 'forelse') {
            return $this->closeIterable($type, $frame['loopId'], $frame['emptySeen']);
        }
        if ($type === 'for' || $type === 'while') {
            return '<?php end' . $type . '; ?>';
        }
        if ($type === 'session') {
            return $this->closeSession($frame['sessionId']);
        }
        return '<?php endif; ?>';
    }

    /** The finally block also restores $value after a body exception or break. */
    private function closeSession(int $id): string
    {
        $prefix = '$__squehub_session_' . $id . '_';
        return '<?php endif; } finally { if (' . $prefix . 'had) { $value = '
            . $prefix . 'saved; } else { unset($value); } unset('
            . $prefix . 'had, ' . $prefix . 'saved, ' . $prefix
            . 'store); } ?>';
    }

    private function closeIterable(string $type, int $id, bool $emptySeen): string
    {
        $had = $this->loopVariable($id, 'had');
        $prior = $this->loopVariable($id, 'prior');
        $end = $type === 'foreach' || !$emptySeen ? 'endforeach; ' : '';
        if ($type === 'forelse') {
            $end .= 'endif; ';
        }

        // Finally restores a previous null value as faithfully as any other
        // value. It also runs after break or an exception in a nested include.
        return '<?php ' . $end . '} finally { if (' . $had . ') { $loop = '
            . $prior . '; } else { unset($loop); } } ?>';
    }

    private function emitLabel(string $name, string $body, int $line): string
    {
        $index = $this->requireTop('switch', $name, $line);
        if ($name === 'default' && $this->frames[$index]['defaultSeen']) {
            throw new CompilerException($this->view, $line, 'Duplicate @default in @switch.');
        }
        $pendingLabel = !$this->frames[$index]['labelSeen'];
        $this->frames[$index]['labelSeen'] = true;
        if ($name === 'default') {
            $this->frames[$index]['defaultSeen'] = true;
        }

        $label = $name === 'case'
            ? 'case (' . $this->expression('case', $body, $line) . '):'
            : 'default:';
        return $pendingLabel ? $label . ' ?>' : '<?php ' . $label . ' ?>';
    }

    private function emitBreak(int $line): string
    {
        foreach (array_reverse($this->frames) as $frame) {
            if (($frame['type'] === 'switch' && $frame['labelSeen'])
                || $this->isLoop($frame['type'])) {
                return '<?php break; ?>';
            }
        }
        throw new CompilerException($this->view, $line,
            '@break requires an active switch case or loop.');
    }

    private function emitContinue(int $line): string
    {
        foreach (array_reverse($this->frames) as $frame) {
            if ($frame['type'] === 'switch') {
                throw new CompilerException($this->view, $line,
                    '@continue cannot target a switch; close it before continuing the loop.');
            }
            if ($this->isLoop($frame['type'])) {
                return '<?php continue; ?>';
            }
        }
        throw new CompilerException($this->view, $line, '@continue requires an active loop.');
    }

    private function isLoop(string $type): bool
    {
        return in_array($type, ['foreach', 'forelse', 'for', 'while'], true);
    }

    private function loopVariable(int $id, string $part): string
    {
        return '$__squehub_loop_' . $part . '_' . $id;
    }

    private function push(string $type, int $line, int $loopId = 0): void
    {
        $this->frames[] = [
            'type' => $type,
            'line' => $line,
            'elseSeen' => false,
            'defaultSeen' => false,
            'labelSeen' => false,
            'emptySeen' => false,
            'loopId' => $loopId,
            'errorId' => 0,
            'sessionId' => 0,
            'componentSlots' => [],
        ];
    }

    private function expression(string $name, string $body, int $line): string
    {
        $expression = trim($body);
        if ($expression === '') {
            throw new CompilerException($this->view, $line,
                '@' . $name . ' requires a non-empty expression.');
        }
        return $expression;
    }

    private function awaitingSwitchLabel(): bool
    {
        $frame = end($this->frames);
        return $frame !== false && $frame['type'] === 'switch' && !$frame['labelSeen'];
    }

    private function requireTop(string $expected, string $name, int $line): int
    {
        $index = count($this->frames) - 1;
        $actual = $this->frames[$index]['type'] ?? null;
        if ($actual !== $expected) {
            $reason = $actual === null
                ? '@' . $name . ' requires an open @' . $expected . '.'
                : '@' . $name . ' cannot close or follow @' . $actual
                    . ' opened at line ' . $this->frames[$index]['line'] . '.';
            throw new CompilerException($this->view, $line, $reason);
        }
        return $index;
    }
}
