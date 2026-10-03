<?php

declare(strict_types=1);

namespace App\View\Compiler;

use App\View\FragmentNotFoundException;
use App\View\LogicalViewName;

/**
 * Emits PHP from scanner tokens in one direction. Runtime View data is never
 * inspected here; a compiled file can therefore be reused across requests.
 */
final class TemplateCompiler
{
    private TemplateScanner $scanner;

    public function __construct(?TemplateScanner $scanner = null)
    {
        $this->scanner = $scanner ?? new TemplateScanner();
    }

    public function compile(string $source, string $view, bool $componentTemplate = false,
        bool $allowFragments = true, ?string $requestedFragment = null): string
    {
        $compiled = '';
        $target =& $compiled;
        /** @var array<string, string> $fragments */
        $fragments = [];
        $controlFlow = new ControlFlowCompiler($view);
        /** @var list<string> Legacy block directives outside the structured flow stack. */
        $legacyBlocks = [];
        $propsDeclaration = null;
        $meaningfulContent = false;
        foreach ($this->scanner->scan($source, $view) as $token) {
            $type = $token['type'];
            if ($type === 'directive' && $token['name'] === 'props') {
                if (!$componentTemplate) {
                    throw new CompilerException($view, $token['line'],
                        '@props is only valid in a component template.');
                }
                if ($propsDeclaration !== null) {
                    throw new CompilerException($view, $token['line'],
                        'A component may declare @props only once.');
                }
                if ($meaningfulContent) {
                    throw new CompilerException($view, $token['line'],
                        '@props must precede component output and other directives.');
                }
                $parts = $this->scanner->splitArguments($token['value'], $view,
                    $token['line'], '@props');
                if (count($parts) !== 1 || $parts[0] === '') {
                    throw new CompilerException($view, $token['line'],
                        '@props expects one declaration array expression.');
                }
                $propsDeclaration = $parts[0];
                continue;
            }
            if ($componentTemplate && $type === 'directive'
                && in_array($token['name'], ['extends', 'section'], true)) {
                throw new CompilerException($view, $token['line'],
                    '@' . $token['name'] . ' is not valid in a component template.');
            }
            $meaningfulContent = $meaningfulContent || self::isMeaningfulContent($token);
            if ($type === 'directive' && $token['name'] === 'fragment') {
                if (!$allowFragments || $componentTemplate) {
                    throw new CompilerException($view, $token['line'],
                        '@fragment is only valid in the explicitly requested root View.');
                }
                if ($legacyBlocks !== []) {
                    throw new CompilerException($view, $token['line'],
                        '@fragment cannot be declared inside @' . end($legacyBlocks) . '.');
                }
                $name = $this->fragmentName($token['value'], $view, $token['line']);
                $controlFlow->openFragment($name, $token['line']);
                $fragments[$name] = '';
                $compiled .= '<?php $__squehub_fragmentRenderers[' . var_export($name, true)
                    . ']($__squehub_fragmentScope, $__squehub_layoutState); ?>';
                $target =& $fragments[$name];
                continue;
            }
            if ($type === 'directive' && $token['name'] === 'endfragment') {
                if ($legacyBlocks !== []) {
                    throw new CompilerException($view, $token['line'],
                        '@endfragment cannot close while @' . end($legacyBlocks)
                        . ' remains open.');
                }
                $controlFlow->closeFragment($token['line']);
                $target =& $compiled;
                continue;
            }
            if ($type === 'text' || $type === 'php') {
                $controlFlow->acceptBodyToken($token['value'], $token['line']);
                $target .= $token['value'];
                continue;
            }
            if ($type === 'php_directive') {
                $controlFlow->acceptBodyToken('@php', $token['line']);
                $target .= '<?php ' . $token['value'] . ' ?>';
                continue;
            }
            if ($type === 'echo' || $type === 'raw_echo') {
                $controlFlow->acceptBodyToken($type === 'echo' ? '{{' : '{!!', $token['line']);
                $expression = trim($token['value']);
                if ($expression === '') {
                    throw new CompilerException($view, $token['line'], 'Empty echo expression.');
                }
                $method = $type === 'echo' ? 'escape' : 'raw';
                $target .= '<?php echo \\App\\Core\\ViewEscaper::' . $method
                    . '(' . $expression . '); ?>';
                continue;
            }
            if (ControlFlowCompiler::supports($token['name'])) {
                $target .= $controlFlow->emit($token['name'], $token['value'], $token['line']);
                continue;
            }
            $controlFlow->acceptBodyToken('@' . $token['name'], $token['line']);
            if (in_array($token['name'], ['auth', 'guest', 'can', 'cannot'], true)) {
                $condition = $this->presentationCondition($token['name'], $token['value'],
                    $token['parenthesized'] ?? false, $view, $token['line']);
                $target .= $controlFlow->openPresentation($token['name'],
                    $condition, $token['line']);
                continue;
            }
            if ($token['name'] === 'session') {
                $key = $this->sessionKey($token['value'], $view, $token['line']);
                $target .= $controlFlow->openSession($key, $token['line']);
                continue;
            }
            if ($token['name'] === 'section') {
                $section = $this->sectionName($token['value'], $view, $token['line']);
                $controlFlow->openSection($section, $token['line']);
                $target .= '<?php if ($__squehub_layoutState->startSection('
                    . var_export($section, true) . ')): ?>';
                continue;
            }
            if ($token['name'] === 'endsection') {
                $controlFlow->closeSection($token['line']);
                $target .= '<?php endif; $__squehub_layoutState->endSection(); ?>';
                continue;
            }
            if ($token['name'] === 'component') {
                $opening = $this->emitComponentOpen($token['value'], $view, $token['line']);
                $controlFlow->openComponent($token['line']);
                $target .= $opening;
                continue;
            }
            if ($token['name'] === 'endcomponent') {
                $controlFlow->closeComponent($token['line']);
                $target .= '<?php \\App\\Core\\View::endComponent($__squehub_layoutState); ?>';
                continue;
            }
            if ($token['name'] === 'slot') {
                $slot = $this->slotName($token['value'], $view, $token['line']);
                $controlFlow->openSlot($slot, $token['line']);
                $target .= '<?php $__squehub_layoutState->startComponentSlot('
                    . var_export($slot, true) . ', ' . var_export($view, true)
                    . ', ' . $token['line'] . '); ?>';
                continue;
            }
            if ($token['name'] === 'endslot') {
                $controlFlow->closeSlot($token['line']);
                $target .= '<?php $__squehub_layoutState->endComponentSlot(); ?>';
                continue;
            }
            if ($token['name'] === 'error') {
                $field = $this->singleExpression('error', $token['value'], $view,
                    $token['line']);
                $target .= $this->emitErrorOpen($field,
                    $controlFlow->openError($token['line']));
                continue;
            }
            if ($token['name'] === 'enderror') {
                $target .= $this->emitErrorClose($controlFlow->closeError($token['line']));
                continue;
            }
            if ($token['name'] === 'push' || $token['name'] === 'prepend') {
                $opening = $this->emitAssetBlockOpen($token['name'], $token['value'],
                    $view, $token['line']);
                $controlFlow->openAssetBlock($token['name'], $token['line']);
                $target .= $opening;
                continue;
            }
            if ($token['name'] === 'endpush' || $token['name'] === 'endprepend') {
                $controlFlow->closeAssetBlock(substr($token['name'], 3), $token['line']);
                $target .= '<?php $__squehub_layoutState->endAssetBlock(); ?>';
                continue;
            }
            if ($token['name'] === 'extends') {
                $controlFlow->declareExtends($token['line']);
            }
            $target .= $this->emitDirective($token['name'], $token['value'], $view, $token['line']);
            if ($token['name'] === 'do' || $token['name'] === 'hasNotification') {
                $legacyBlocks[] = $token['name'];
            } elseif ($token['name'] === 'enddo'
                || $token['name'] === 'endhasNotification') {
                array_pop($legacyBlocks);
            }
        }
        $controlFlow->finish();
        if ($requestedFragment !== null && !array_key_exists($requestedFragment, $fragments)) {
            throw new FragmentNotFoundException($view, $requestedFragment);
        }
        if ($fragments !== []) {
            self::rejectNamespaceDeclaration($source, $view);
            $strictPrefix = self::leadingDeclarePrefix($source, $view);
            if ($strictPrefix !== null) {
                // PHP requires a leading declare (notably strict_types) before
                // generated statements. Reopen its original trailing PHP code
                // after the render dispatch rather than moving the declaration.
                $compiled = $strictPrefix . ' ?>'
                    . $this->emitFragmentMap($fragments, $view)
                    . '<?php ' . substr($compiled, strlen($strictPrefix));
            } else {
                $compiled = $this->emitFragmentMap($fragments, $view) . $compiled;
            }
        }
        if (!$componentTemplate) {
            return $compiled;
        }
        // Bind the interface before any template output, including when a
        // component omits @props and therefore accepts no supplied props.
        return '<?php extract($__squehub_layoutState->bindComponentProps('
            . ($propsDeclaration ?? '[]') . '), EXTR_SKIP); ?>' . $compiled;
    }

    private function fragmentName(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@fragment');
        if (count($parts) !== 1 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@fragment expects one quoted static name.');
        }
        return $this->staticNameValue($parts[0], $view, $line, 'fragment',
            '/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D');
    }

    /**
     * The same compiled closure serves full and selected rendering. Selected
     * dispatch returns from the cached include before any sibling code or
     * deferred layout declaration can execute. Runtime data stays outside the
     * compiled file and enters only through the render-local scope argument.
     *
     * @param array<string, string> $fragments
     */
    private function emitFragmentMap(array $fragments, string $view): string
    {
        $code = '<?php $__squehub_fragmentRenderers = [';
        foreach ($fragments as $name => $body) {
            $code .= var_export($name, true)
                . ' => static function (array $__squehub_fragmentScope, '
                . '\\App\\View\\LayoutRenderState $__squehub_layoutState): void {'
                . ' extract($__squehub_fragmentScope, EXTR_SKIP); ?>'
                . $body . '<?php },';
        }
        $code .= ']; if ($__squehub_fragmentSelection !== null) {'
            . '$__squehub_fragmentRenderer = $__squehub_fragmentRenderers['
            . '$__squehub_fragmentSelection] ?? null;'
            . 'if ($__squehub_fragmentRenderer === null) {'
            . 'throw new \\App\\View\\FragmentNotFoundException('
            . var_export($view, true) . ', $__squehub_fragmentSelection); }'
            . '$__squehub_fragmentRenderer($__squehub_fragmentScope, '
            . '$__squehub_layoutState); return; } ?>';
        return $code;
    }

    /** Keep an existing first-statement PHP declare ahead of generated code. */
    private static function leadingDeclarePrefix(string $source, string $view): ?string
    {
        $tokens = token_get_all($source);
        if (!isset($tokens[0]) || !is_array($tokens[0])
            || $tokens[0][0] !== T_OPEN_TAG) {
            return null;
        }
        $offset = strlen($tokens[0][1]);
        $declareSeen = false;
        foreach (array_slice($tokens, 1) as $token) {
            $kind = is_array($token) ? $token[0] : null;
            $part = is_array($token) ? $token[1] : $token;
            if (!$declareSeen) {
                if (in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $offset += strlen($part);
                    continue;
                }
                if ($kind !== T_DECLARE) { return null; }
                $declareSeen = true;
            }
            $offset += strlen($part);
            if ($part === ';') { return substr($source, 0, $offset); }
            if ($part === ':' || $kind === T_CLOSE_TAG) {
                throw new CompilerException($view, 1,
                    'A Fragment View requires a leading PHP declare statement to end with a semicolon.');
            }
        }
        if ($declareSeen) {
            throw new CompilerException($view, 1,
                'A Fragment View has an unterminated leading PHP declare statement.');
        }
        return null;
    }

    /** A PHP namespace must precede all statements and cannot host a View map. */
    private static function rejectNamespaceDeclaration(string $source, string $view): void
    {
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                throw new CompilerException($view, $token[2],
                    'A Fragment View cannot declare a PHP namespace.');
            }
        }
    }

    /** PHP comments and template whitespace may precede a component interface. */
    private static function isMeaningfulContent(array $token): bool
    {
        if ($token['type'] === 'text') {
            return trim($token['value']) !== '';
        }
        if ($token['type'] === 'php') {
            return !in_array($token['phpKind'] ?? 0,
                [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }
        return true;
    }

    /**
     * Scanner grammar is independent of these established directive handlers.
     * Future directives can add handlers without changing delimiter parsing.
     */
    private function emitDirective(string $name, string $body, string $view, int $line): string
    {
        return match ($name) {
            'include', 'includeOptional', 'includeWhen' => $this->emitInclude($name, $body, $view, $line),
            'extends' => $this->emitChildView($body, $view, $line),
            'yield' => $this->emitYield($body, $view, $line),
            'style', 'script' => $this->emitDirectAsset($name, $body, $view, $line),
            'frontend' => '<?php $__squehub_layoutState->frontend('
                . $this->staticName($body, $view, $line, 'frontend',
                    '/\\A[A-Za-z][A-Za-z0-9._-]*\\z/D') . '); ?>',
            'stack' => $this->emitStack($body, $view, $line),
            'csrf' => '<?php echo \\csrf_field(); ?>',
            'method' => '<?php echo \\method_field('
                . $this->singleExpression('method', $body, $view, $line) . '); ?>',
            'json' => '<?php echo \\json('
                . $this->singleExpression('json', $body, $view, $line) . '); ?>',
            'translate' => $this->emitTranslation($body, $view, $line),
            'echo' => '<?php echo \\App\\Core\\ViewEscaper::escape(' . trim($body) . '); ?>',
            'notification' => $this->emitNotification($body, $view, $line, true),
            'hasNotification' => $this->emitNotification($body, $view, $line, false),
            'endhasNotification' => '<?php endif; ?>',
            'do' => '<?php do { ?>',
            'enddo' => '<?php } while (' . trim($body) . '); ?>',
            'year' => '<?php echo date("Y"); ?>',
            'month' => '<?php echo date("F"); ?>',
            'date' => '<?php echo date("Y-m-d"); ?>',
            'time' => '<?php echo date("H:i:s"); ?>',
            'datetime' => '<?php echo date(' . trim($body) . '); ?>',
            default => throw new CompilerException($view, $line, 'Unsupported directive @' . $name . '.'),
        };
    }

    /** Preserve an existing caller $message even when the block exits early. */
    private function emitErrorOpen(string $field, int $id): string
    {
        $prefix = '$__squehub_form_error_' . $id . '_';
        return '<?php ' . $prefix . "had = array_key_exists('message', get_defined_vars()); "
            . $prefix . 'saved = $message ?? null; '
            . $prefix . 'current = $errors->first(' . $field . '); '
            . 'try { if (' . $prefix . 'current !== null) { $message = '
            . $prefix . 'current; ?>';
    }

    private function emitErrorClose(int $id): string
    {
        $prefix = '$__squehub_form_error_' . $id . '_';
        return '<?php } } finally { if (' . $prefix . 'had) { $message = '
            . $prefix . 'saved; } else { unset($message); } unset('
            . $prefix . 'had, ' . $prefix . 'saved, ' . $prefix
            . 'current); } ?>';
    }

    /** Keep a directive's single trusted expression intact for runtime use. */
    private function singleExpression(string $name, string $body, string $view,
        int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@' . $name);
        if (count($parts) !== 1 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@' . $name . ' expects one non-empty expression.');
        }
        return $parts[0];
    }

    /** A translation remains a plain string and uses the ordinary HTML escaper. */
    private function emitTranslation(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@translate');
        if (count($parts) < 1 || count($parts) > 3 || in_array('', $parts, true)) {
            throw new CompilerException($view, $line,
                '@translate expects a key, optional parameters, and optional locale.');
        }
        return '<?php echo \\App\\Core\\ViewEscaper::escape('
            . '\\App\\Translation\\Translation::get(' . implode(', ', $parts) . ')); ?>';
    }

    /** Only named Auth guards may override the current/default guard. */
    private function presentationCondition(string $name, string $body,
        bool $parenthesized, string $view, int $line): string
    {
        if ($name === 'auth' || $name === 'guest') {
            if (!$parenthesized) {
                $check = '\\auth()->hasDefaultGuard() && \\auth()->check()';
                return $name === 'auth' ? $check : '!(' . $check . ')';
            }
            $guard = $this->singleStaticName($name, $body, $view, $line,
                '/\A[A-Za-z_][A-Za-z0-9_]*\z/D');
            return '\\auth()->guard(' . $guard . ')->'
                . ($name === 'auth' ? 'check' : 'guest') . '()';
        }

        $parts = $this->scanner->splitArguments($body, $view, $line, '@' . $name);
        if (count($parts) < 1 || count($parts) > 2 || $parts[0] === ''
            || (count($parts) === 2 && $parts[1] === '')) {
            throw new CompilerException($view, $line,
                '@' . $name . ' expects a quoted ability and optional resource expression.');
        }
        $ability = $this->staticName($parts[0], $view, $line, $name,
            '/\A[A-Za-z_][A-Za-z0-9._-]*\z/D');
        return '\\authorize()->' . ($name === 'can' ? 'allows' : 'denies')
            . '(' . $ability . (count($parts) === 2 ? ', ' . $parts[1] : '') . ')';
    }

    /** A Session key is a single PHP string literal; SessionStore validates its value. */
    private function sessionKey(string $body, string $view, int $line): string
    {
        $literal = $this->singleExpression('session', $body, $view, $line);
        $tokens = token_get_all('<?php ' . $literal);
        if (count($tokens) !== 2 || !is_array($tokens[1])
            || $tokens[1][0] !== T_CONSTANT_ENCAPSED_STRING
            || $literal === "''" || $literal === '""') {
            throw new CompilerException($view, $line,
                '@session requires one quoted static key.');
        }
        return $literal;
    }

    private function singleStaticName(string $name, string $body, string $view,
        int $line, string $pattern): string
    {
        return $this->staticName($this->singleExpression($name, $body, $view,
            $line), $view, $line, $name, $pattern);
    }

    /**
     * All include forms use the same runtime renderer and inherited context.
     * A conditional form encloses the complete call, so neither target nor
     * explicit data is evaluated when its condition is false.
     */
    private function emitInclude(string $name, string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@' . $name);
        $conditional = $name === 'includeWhen';
        $targetIndex = $conditional ? 1 : 0;
        if (count($parts) < $targetIndex + 1 || count($parts) > $targetIndex + 2
            || $parts[$targetIndex] === '' || ($conditional && $parts[0] === '')) {
            throw new CompilerException($view, $line,
                '@' . $name . ' expects ' . ($conditional ? 'a condition, ' : '')
                . 'a View name and optional data.');
        }
        $child = var_export($this->staticViewName($parts[$targetIndex], $view,
            $line, $name)->name(), true);
        $overlay = $parts[$targetIndex + 1] ?? '[]';
        if ($overlay === '') {
            throw new CompilerException($view, $line, '@' . $name . ' data cannot be empty.');
        }
        $call = '\\App\\Core\\View::include(' . $child
            . ', get_defined_vars(), true, ' . $overlay
            . ', $__squehub_layoutState, ' . ($name === 'includeOptional' ? 'true' : 'false')
            . ', ' . var_export($view, true) . ', ' . $line . ');';

        return $conditional
            ? '<?php if (' . $parts[0] . '): ' . $call . ' endif; ?>'
            : '<?php ' . $call . ' ?>';
    }

    /** The component name is a static logical suffix under Components.*. */
    private function emitComponentOpen(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@component');
        if (count($parts) < 1 || count($parts) > 3 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@component expects a quoted name, optional props and optional attributes.');
        }
        $name = $this->staticViewName($parts[0], $view, $line, 'component')
            ->component()->name();
        $props = $parts[1] ?? '[]';
        $attributes = $parts[2] ?? '[]';
        if ($props === '' || $attributes === '') {
            throw new CompilerException($view, $line,
                '@component props and attributes expressions cannot be empty.');
        }

        return '<?php \\App\\Core\\View::beginComponent('
            . var_export($name, true) . ', '
            . $props . ', ' . $attributes . ', $__squehub_layoutState, '
            . var_export($view, true) . ', ' . $line . ', $loop ?? null); ?>';
    }

    private function slotName(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@slot');
        if (count($parts) !== 1 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@slot expects one quoted static name.');
        }
        return $this->staticNameValue($parts[0], $view, $line, 'slot',
            '/\A[A-Za-z_][A-Za-z0-9_-]*\z/D');
    }

    private function emitChildView(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@extends');
        if (count($parts) < 1 || count($parts) > 2 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@extends expects a View name and optional data.');
        }
        $child = var_export($this->staticViewName($parts[0], $view,
            $line, 'extends')->name(), true);
        $overlay = $parts[1] ?? '[]';
        if ($overlay === '') {
            throw new CompilerException($view, $line, '@extends data cannot be empty.');
        }
        // The parent executes only after the child's sections are captured.
        return '<?php $__squehub_layoutState->declareParent(' . $child . ', '
            . $overlay . ', ' . $line . '); ?>';
    }

    private function emitYield(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@yield');
        if (count($parts) < 1 || count($parts) > 2 || $parts[0] === '') {
            throw new CompilerException($view, $line, '@yield expects a section name and optional default.');
        }
        $section = $this->staticName($parts[0], $view, $line, 'yield',
            '/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D');
        $default = $parts[1] ?? "''";
        if ($default === '') {
            throw new CompilerException($view, $line, '@yield default cannot be empty.');
        }
        // A defined section is already rendered HTML. The default is a runtime
        // expression and is evaluated and escaped only on the missing branch.
        return '<?php if ($__squehub_layoutState->hasSection(' . $section
            . ')): echo $__squehub_layoutState->section(' . $section
            . '); else: echo \\App\\Core\\ViewEscaper::escape(' . $default
            . '); endif; ?>';
    }

    private function emitDirectAsset(string $name, string $body, string $view, int $line): string
    {
        [$url, $once] = $this->assetArguments($name, $body, $view, $line);
        return '<?php $__squehub_layoutState->' . $name . '(' . $url . ', '
            . $once . '); ?>';
    }

    private function emitStack(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@stack');
        if (count($parts) !== 1 || $parts[0] === '') {
            throw new CompilerException($view, $line, '@stack expects one quoted static name.');
        }
        $stack = $this->staticName($parts[0], $view, $line, 'stack',
            '/\A[A-Za-z][A-Za-z0-9._-]*\z/D');
        return '<?php echo $__squehub_layoutState->stack(' . $stack . '); ?>';
    }

    private function emitAssetBlockOpen(string $name, string $body, string $view, int $line): string
    {
        [$stack, $once] = $this->assetArguments($name, $body, $view, $line, true);
        return '<?php $__squehub_layoutState->startAssetBlock(' . $stack . ', '
            . $once . ', ' . ($name === 'prepend' ? 'true' : 'false') . '); ?>';
    }

    /** @return array{string, string} PHP expressions for resource/name and once key. */
    private function assetArguments(string $name, string $body, string $view, int $line,
        bool $staticStack = false): array
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@' . $name);
        if (count($parts) < 1 || count($parts) > 2 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@' . $name . ' expects ' . ($staticStack ? 'a quoted static stack name' : 'a URL expression')
                . ' and optional once argument.');
        }
        $first = $staticStack
            ? $this->staticName($parts[0], $view, $line, $name,
                '/\A[A-Za-z][A-Za-z0-9._-]*\z/D')
            : trim($parts[0]);
        if (!$staticStack && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\s*:(?!:)/', $first)) {
            throw new CompilerException($view, $line,
                '@' . $name . ' requires a URL expression before once.');
        }
        if (count($parts) === 1) {
            return [$first, 'null'];
        }
        if (!preg_match('/\Aonce\s*:\s*(.+)\z/sD', $parts[1], $match)
            || trim($match[1]) === '') {
            throw new CompilerException($view, $line,
                '@' . $name . ' accepts only a non-empty named once argument.');
        }
        return [$first, trim($match[1])];
    }

    private function sectionName(string $body, string $view, int $line): string
    {
        $parts = $this->scanner->splitArguments($body, $view, $line, '@section');
        if (count($parts) !== 1 || $parts[0] === '') {
            throw new CompilerException($view, $line,
                '@section expects one quoted static name.');
        }
        return $this->staticNameValue($parts[0], $view, $line, 'section',
            '/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D');
    }

    private function emitNotification(string $body, string $view, int $line, bool $render): string
    {
        $type = $this->staticName($body, $view, $line, 'notification', '/\A[A-Za-z0-9_]+\z/D');
        if (!$render) {
            return '<?php if ($message = \\App\\Core\\Notification::get(' . $type . ')): ?>';
        }
        $color = match (trim($type, "'")) {
            'success' => 'green', 'error' => 'red', default => 'black',
        };
        return '<?php if ($message = \\App\\Core\\Notification::get(' . $type . ')): ?>'
            . '<p style="color: ' . $color . '; "><?= \\App\\Core\\ViewEscaper::escape($message) ?></p>'
            . '<?php endif; ?>';
    }

    /** A logical name is static in the established layout/include grammar. */
    private function staticName(string $source, string $view, int $line,
        string $directive, string $pattern): string
    {
        return var_export($this->staticNameValue($source, $view, $line,
            $directive, $pattern), true);
    }

    private function staticNameValue(string $source, string $view, int $line,
        string $directive, string $pattern): string
    {
        $source = trim($source);
        if (!preg_match('/\A([\'\"])(.*)\1\z/sD', $source, $match)
            || !preg_match($pattern, $match[2])) {
            throw new CompilerException($view, $line,
                '@' . $directive . ' requires a quoted static name.');
        }
        return $match[2];
    }

    /** Static View dependencies share the public logical-name grammar. */
    private function staticViewName(string $source, string $view, int $line,
        string $directive): LogicalViewName
    {
        $source = trim($source);
        if (!preg_match('/\A([\'\"])(.*)\1\z/sD', $source, $match)) {
            throw new CompilerException($view, $line,
                '@' . $directive . ' requires a quoted static name.');
        }
        return LogicalViewName::parse($match[2])
            ?? throw new CompilerException($view, $line,
                '@' . $directive . ' requires a quoted static name.');
    }
}
