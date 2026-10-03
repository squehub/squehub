<?php

declare(strict_types=1);

namespace App\View\Compiler;

/**
 * Separates trusted PHP from template text and recognizes only established
 * SqueHub syntax. It never evaluates an expression or rewrites emitted PHP.
 */
final class TemplateScanner
{
    private const ARGUMENT_DIRECTIVES = [
        'notification' => true, 'hasNotification' => true, 'echo' => true,
        'json' => true,
        'translate' => true,
        'if' => true, 'elseif' => true, 'unless' => true,
        'switch' => true, 'case' => true,
        'foreach' => true, 'forelse' => true, 'for' => true,
        'while' => true, 'enddo' => true, 'extends' => true, 'include' => true,
        'includeOptional' => true, 'includeWhen' => true,
        'component' => true, 'props' => true, 'slot' => true,
        'fragment' => true,
        'error' => true, 'method' => true,
        'auth' => true, 'guest' => true, 'can' => true,
        'cannot' => true, 'session' => true,
        'section' => true, 'yield' => true, 'datetime' => true,
        'style' => true, 'script' => true, 'stack' => true,
        'frontend' => true,
        'push' => true, 'prepend' => true,
    ];

    private const BARE_DIRECTIVES = [
        'endhasNotification' => true, 'else' => true, 'endif' => true,
        'endunless' => true, 'default' => true, 'break' => true,
        'continue' => true, 'empty' => true, 'endforelse' => true,
        'endswitch' => true,
        'endforeach' => true, 'endfor' => true, 'endwhile' => true,
        'do' => true, 'endsection' => true, 'year' => true,
        'month' => true, 'date' => true, 'time' => true, 'csrf' => true,
        'endpush' => true, 'endprepend' => true,
        'endcomponent' => true, 'endslot' => true, 'enderror' => true,
        'endfragment' => true,
        'auth' => true, 'guest' => true,
        'endauth' => true, 'endguest' => true,
        'endcan' => true, 'endcannot' => true, 'endsession' => true,
    ];

    private ?string $rawElement = null;
    private ?string $scriptQuote = null;
    private ?string $scriptComment = null;
    private bool $scriptEscaped = false;
    private bool $scriptRegexCharacterClass = false;
    private bool $scriptBlockPreviousAsterisk = false;
    private ?string $scriptPreviousCodeCharacter = null;
    private string $scriptCurrentCodeWord = '';
    private ?string $scriptPreviousCodeWord = null;
    /** @var list<bool> */
    private array $scriptControlParentheses = [];
    private bool $scriptAfterControlCondition = false;

    /** @return list<array{type: string, value: string, line: int, name?: string, phpKind?: int, parenthesized?: bool}> */
    public function scan(string $source, string $view): array
    {
        $this->rawElement = null;
        $this->resetScriptState();
        $htmlComment = false;
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            throw new CompilerException($view, 1, 'A UTF-8 BOM before template output is not supported.');
        }

        $tokens = [];
        $line = 1;
        // PHP's lexer protects strings, comments, and short echo blocks in raw
        // PHP. Only T_INLINE_HTML is scanned for SqueHub syntax.
        foreach (token_get_all($source) as $phpToken) {
            $value = is_array($phpToken) ? $phpToken[1] : $phpToken;
            if (is_array($phpToken) && $phpToken[0] === T_INLINE_HTML) {
                array_push($tokens, ...$this->scanText($value, $view, $line, $htmlComment));
            } elseif ($htmlComment) {
                // An HTML comment may cross PHP tokens. Suppress the PHP code
                // as well as template syntax until its literal closing tag.
                $endings = self::lineEndings($value);
                if ($endings !== '') {
                    $tokens[] = ['type' => 'text', 'value' => $endings, 'line' => $line];
                }
            } else {
                $tokens[] = ['type' => 'php', 'value' => $value, 'line' => $line,
                    'phpKind' => is_array($phpToken) ? $phpToken[0] : 0];
            }
            $line += substr_count($value, "\n");
        }

        return $tokens;
    }

    /** @return list<array{type: string, value: string, line: int, name?: string, phpKind?: int, parenthesized?: bool}> */
    private function scanText(string $text, string $view, int $startLine, bool &$htmlComment): array
    {
        $tokens = [];
        $plain = '';
        $plainLine = $startLine;
        $line = $startLine;
        $length = strlen($text);
        $previousDirectiveEnd = -1;
        for ($i = 0; $i < $length;) {
            if ($htmlComment) {
                $end = strpos($text, '-->', $i);
                $next = $end === false ? $length : $end + 3;
                $comment = substr($text, $i, $next - $i);
                $plain .= self::lineEndings($comment);
                $line += substr_count($comment, "\n");
                $i = $next;
                if ($end !== false) {
                    $htmlComment = false;
                }
                continue;
            }
            if ($this->consumeRawElementTag($text, $i, $tag)) {
                $plain .= $tag;
                $line += substr_count($tag, "\n");
                $i += strlen($tag);
                continue;
            }

            // Historical HTML comments are removed. Retaining only their line
            // endings keeps diagnostics aligned without exposing hidden syntax.
            if ($this->rawElement === null && substr_compare($text, '<!--', $i, 4) === 0) {
                $htmlComment = true;
                $i += 4;
                continue;
            }

            $opener = substr($text, $i, 3) === '{!!' ? '{!!'
                : (substr($text, $i, 2) === '{{' ? '{{' : null);
            if ($opener !== null) {
                self::flush($tokens, $plain, $plainLine);
                $close = $opener === '{{' ? '}}' : '!!}';
                [$expression, $next] = $this->readExpression($text, $i + strlen($opener),
                    $close, $view, $line, $opener);
                $tokens[] = ['type' => $opener === '{{' ? 'echo' : 'raw_echo',
                    'value' => $expression, 'line' => $line];
                $line += substr_count(substr($text, $i, $next - $i), "\n");
                $i = $next;
                $plainLine = $line;
                continue;
            }

            // Script text remains protected except for the one output form
            // intended for JavaScript values. Quoted/commented @json text is
            // ordinary JavaScript, not a template directive.
            if ($this->rawElement === 'script'
                && $this->scriptCharacterProtected($text, $i)) {
                $plain .= $text[$i];
                if ($text[$i] === "\n") { ++$line; }
                ++$i;
                continue;
            }

            if ($text[$i] === '@' && ($this->rawElement === null || $this->rawElement === 'script')
                && preg_match('/\G@([A-Za-z_][A-Za-z0-9_]*)/A', $text, $match, 0, $i)) {
                $name = $match[1];
                if ($this->rawElement === 'script' && $name !== 'json') {
                    $plain .= $text[$i++];
                    continue;
                }
                $boundary = $i === 0 || !preg_match('/[A-Za-z0-9_.@]/', $text[$i - 1])
                    || $previousDirectiveEnd === $i
                    || in_array($name, ['yield', 'endsection', 'endpush', 'endprepend',
                        'endcomponent', 'endslot', 'enderror',
                        'endfragment',
                        'endauth', 'endguest', 'endcan', 'endcannot', 'endsession',
                        'endhasNotification', 'else', 'endif',
                        'endunless', 'default', 'break', 'continue', 'empty',
                        'endforelse', 'endswitch',
                        'endforeach', 'endfor', 'endwhile'], true);
                if (!$boundary) {
                    $plain .= $text[$i++];
                    continue;
                }
                $afterName = $i + strlen($match[0]);
                if ($name === 'php') {
                    self::flush($tokens, $plain, $plainLine);
                    $end = $this->findPhpDirectiveEnd($text, $afterName);
                    if ($end === null) {
                        throw new CompilerException($view, $line, 'Unterminated @php block.');
                    }
                    $tokens[] = ['type' => 'php_directive',
                        'value' => substr($text, $afterName, $end - $afterName), 'line' => $line];
                    $next = $end + strlen('@endphp');
                    $line += substr_count(substr($text, $i, $next - $i), "\n");
                    $i = $next;
                    $previousDirectiveEnd = $i;
                    $plainLine = $line;
                    continue;
                }
                if (isset(self::ARGUMENT_DIRECTIVES[$name])) {
                    $open = $afterName;
                    while ($open < $length && ctype_space($text[$open])) { ++$open; }
                    if ($open < $length && $text[$open] === '(') {
                        self::flush($tokens, $plain, $plainLine);
                        [$args, $next] = $this->readExpression($text, $open + 1, ')',
                            $view, $line, '@' . $name, true);
                        $tokens[] = ['type' => 'directive', 'name' => $name,
                            'value' => $args, 'line' => $line, 'parenthesized' => true];
                        $line += substr_count(substr($text, $i, $next - $i), "\n");
                        $i = $next;
                        if ($this->rawElement === 'script') {
                            $this->scriptPreviousCodeCharacter = ')';
                            $this->scriptCurrentCodeWord = '';
                            $this->scriptPreviousCodeWord = null;
                            $this->scriptAfterControlCondition = false;
                        }
                        $previousDirectiveEnd = $i;
                        $plainLine = $line;
                        continue;
                    }
                    // Recognized control directives cannot degrade into literal
                    // output when their required expression is missing.
                    if (in_array($name, ['if', 'elseif', 'unless', 'switch', 'case',
                        'foreach', 'forelse', 'for', 'while', 'extends', 'include',
                        'includeOptional', 'includeWhen', 'component', 'props', 'slot', 'section',
                        'fragment',
                        'error', 'method', 'can', 'cannot', 'session',
                        'yield', 'style', 'script', 'stack', 'push', 'prepend',
                        'json', 'translate'], true)) {
                        throw new CompilerException($view, $line,
                            '@' . $name . ' requires a parenthesized expression.');
                    }
                }
                if (isset(self::BARE_DIRECTIVES[$name])) {
                    if (in_array($name, ['else', 'endif', 'endunless', 'default', 'break',
                        'continue', 'empty', 'endforelse', 'endforeach', 'endfor',
                        'endwhile', 'endswitch', 'endsection', 'endpush',
                        'endprepend', 'endcomponent', 'endslot', 'enderror',
                        'endfragment',
                        'endauth', 'endguest', 'endcan', 'endcannot', 'endsession'], true)) {
                        $next = $afterName;
                        // A parenthesis on a later line belongs to branch
                        // content, not to the preceding bare directive.
                        while ($next < $length && ($text[$next] === ' ' || $text[$next] === "\t")) { ++$next; }
                        if ($next < $length && $text[$next] === '(') {
                            throw new CompilerException($view, $line,
                                '@' . $name . ' does not accept arguments.');
                        }
                    }
                    self::flush($tokens, $plain, $plainLine);
                    $tokens[] = ['type' => 'directive', 'name' => $name,
                        'value' => '', 'line' => $line];
                    $i = $afterName;
                    $previousDirectiveEnd = $i;
                    $plainLine = $line;
                    continue;
                }
            }

            $plain .= $text[$i];
            if ($text[$i] === "\n") { ++$line; }
            ++$i;
        }
        self::flush($tokens, $plain, $plainLine);
        return $tokens;
    }

    /** Protect ordinary CSS and JavaScript @ text from directive handling. */
    private function consumeRawElementTag(string $text, int $offset, ?string &$tag): bool
    {
        $tag = null;
        if ($text[$offset] !== '<') { return false; }
        if ($this->rawElement === null) {
            if (!preg_match('/\G<(script|style)\b[^>]*>/iA', $text, $match, 0, $offset)) {
                return false;
            }
            $this->rawElement = strtolower($match[1]);
            if ($this->rawElement === 'script') { $this->resetScriptState(); }
            $tag = $match[0];
            return true;
        }
        if (!preg_match('/\G<\/\s*' . $this->rawElement . '\s*>/iA', $text, $match, 0, $offset)) {
            return false;
        }
        $this->rawElement = null;
        $this->resetScriptState();
        $tag = $match[0];
        return true;
    }

    private function resetScriptState(): void
    {
        $this->scriptQuote = null;
        $this->scriptComment = null;
        $this->scriptEscaped = false;
        $this->scriptRegexCharacterClass = false;
        $this->scriptBlockPreviousAsterisk = false;
        $this->scriptPreviousCodeCharacter = null;
        $this->scriptCurrentCodeWord = '';
        $this->scriptPreviousCodeWord = null;
        $this->scriptControlParentheses = [];
        $this->scriptAfterControlCondition = false;
    }

    /** A small lexical guard for JavaScript text around the one allowed directive. */
    private function scriptCharacterProtected(string $text, int $offset): bool
    {
        $char = $text[$offset];
        $next = $text[$offset + 1] ?? '';
        if ($this->scriptComment === 'line') {
            if ($char === "\n" || $char === "\r") { $this->scriptComment = null; }
            return true;
        }
        if ($this->scriptComment === 'block') {
            if ($char === '/' && $this->scriptBlockPreviousAsterisk) {
                $this->scriptComment = null;
            }
            $this->scriptBlockPreviousAsterisk = $char === '*';
            return true;
        }
        if ($this->scriptQuote !== null) {
            if ($this->scriptEscaped) {
                $this->scriptEscaped = false;
            } elseif ($char === '\\') {
                $this->scriptEscaped = true;
            } elseif ($this->scriptQuote === '/' && $char === '[') {
                $this->scriptRegexCharacterClass = true;
            } elseif ($this->scriptQuote === '/' && $char === ']') {
                $this->scriptRegexCharacterClass = false;
            } elseif ($char === $this->scriptQuote && !$this->scriptRegexCharacterClass) {
                $this->scriptQuote = null;
                $this->scriptPreviousCodeCharacter = $char;
            }
            return true;
        }
        if (ctype_alnum($char) || $char === '_' || $char === '$') {
            $this->scriptCurrentCodeWord .= $char;
            $this->scriptPreviousCodeCharacter = $char;
            $this->scriptAfterControlCondition = false;
            return false;
        }
        if ($this->scriptCurrentCodeWord !== '') {
            $this->scriptPreviousCodeWord = $this->scriptCurrentCodeWord;
            $this->scriptCurrentCodeWord = '';
        }
        if ($char === '(') {
            $this->scriptControlParentheses[] = in_array($this->scriptPreviousCodeWord,
                ['if', 'while', 'for', 'with', 'switch', 'catch'], true);
            $this->scriptAfterControlCondition = false;
        } elseif ($char === ')') {
            $this->scriptAfterControlCondition = array_pop($this->scriptControlParentheses) === true;
        } elseif (!ctype_space($char) && $char !== '/') {
            $this->scriptAfterControlCondition = false;
        }
        if ($char === '\'' || $char === '"' || $char === '`') {
            $this->scriptQuote = $char;
            return true;
        }
        if ($char === '/' && ($next === '/' || $next === '*')) {
            $this->scriptComment = $next === '/' ? 'line' : 'block';
            return true;
        }
        if ($char === '<' && substr($text, $offset, 4) === '<!--') {
            $this->scriptComment = 'line';
            return true;
        }
        // A regex literal in an expression position is protected too. This
        // avoids treating /@json(...)/ as a template directive.
        if ($char === '/' && ($this->scriptAfterControlCondition
            || $this->scriptPreviousCodeCharacter === null
            || str_contains('=([{:;,!?&|+*-~%^<>', $this->scriptPreviousCodeCharacter)
            || ((ctype_alnum($this->scriptPreviousCodeCharacter)
                    || $this->scriptPreviousCodeCharacter === '_')
                && in_array($this->scriptPreviousCodeWord,
                    ['return', 'throw', 'case', 'yield', 'await', 'delete', 'void',
                        'typeof', 'instanceof', 'in', 'of', 'new'], true)))) {
            $this->scriptQuote = '/';
            $this->scriptRegexCharacterClass = false;
            $this->scriptAfterControlCondition = false;
            return true;
        }
        if (!ctype_space($char)) { $this->scriptPreviousCodeCharacter = $char; }
        return false;
    }

    /**
     * Read trusted PHP expression text without interpreting PHP semantics.
     * Delimiters inside strings and comments cannot close a construct; a stack
     * tracks the three PHP grouping pairs and prevents first-`)` truncation.
     *
     * @return array{string, int} Expression and offset after closing delimiter.
     */
    private function readExpression(string $text, int $start, string $close,
        string $view, int $line, string $label, bool $parenthesized = false): array
    {
        $stack = $parenthesized ? ['('] : [];
        $quote = null;
        $comment = null;
        $length = strlen($text);
        for ($i = $start; $i < $length; ++$i) {
            $char = $text[$i];
            $next = $i + 1 < $length ? $text[$i + 1] : '';
            if ($comment === 'line') {
                if ($char === "\n") { $comment = null; }
                continue;
            }
            if ($comment === 'block') {
                if ($char === '*' && $next === '/') { $comment = null; ++$i; }
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') { ++$i; continue; }
                if ($char === $quote) { $quote = null; }
                continue;
            }
            if ($char === '/' && $next === '/') { $comment = 'line'; ++$i; continue; }
            if ($char === '/' && $next === '*') { $comment = 'block'; ++$i; continue; }
            if ($char === '#') { $comment = 'line'; continue; }
            if ($char === '\'' || $char === '"' || $char === '`') { $quote = $char; continue; }

            if ($char === '<' && substr_compare($text, '<<<', $i, 3) === 0) {
                $i = $this->skipHeredoc($text, $i, $view, $line, $label);
                continue;
            }

            if (!$parenthesized && $stack === []
                && substr_compare($text, $close, $i, strlen($close)) === 0) {
                return [substr($text, $start, $i - $start), $i + strlen($close)];
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $stack[] = $char;
                continue;
            }
            if ($char === ')' || $char === ']' || $char === '}') {
                $open = array_pop($stack);
                if ($open === null || !self::pairMatches($open, $char)) {
                    throw new CompilerException($view, $line, 'Mismatched delimiter in ' . $label . '.');
                }
                if ($parenthesized && $stack === []) {
                    return [substr($text, $start, $i - $start), $i + 1];
                }
            }
        }
        throw new CompilerException($view, $line,
            ($quote !== null ? 'Unterminated quoted string in ' : 'Unterminated ') . $label . '.');
    }

    /** @return list<string> */
    public function splitArguments(string $args, string $view, int $line, string $label): array
    {
        $parts = [];
        $start = 0;
        $stack = [];
        $quote = null;
        $comment = null;
        $length = strlen($args);
        for ($i = 0; $i < $length; ++$i) {
            $char = $args[$i];
            $next = $i + 1 < $length ? $args[$i + 1] : '';
            if ($comment === 'line') {
                if ($char === "\n") { $comment = null; }
                continue;
            }
            if ($comment === 'block') {
                if ($char === '*' && $next === '/') { $comment = null; ++$i; }
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') { ++$i; continue; }
                if ($char === $quote) { $quote = null; }
                continue;
            }
            if ($char === '/' && $next === '/') { $comment = 'line'; ++$i; continue; }
            if ($char === '/' && $next === '*') { $comment = 'block'; ++$i; continue; }
            if ($char === '#') { $comment = 'line'; continue; }
            if ($char === '\'' || $char === '"' || $char === '`') { $quote = $char; continue; }
            if ($char === '<' && substr_compare($args, '<<<', $i, 3) === 0) {
                $i = $this->skipHeredoc($args, $i, $view, $line, $label);
                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') { $stack[] = $char; continue; }
            if ($char === ')' || $char === ']' || $char === '}') {
                $open = array_pop($stack);
                if ($open === null || !self::pairMatches($open, $char)) {
                    throw new CompilerException($view, $line, 'Mismatched delimiter in ' . $label . '.');
                }
                continue;
            }
            if ($char === ',' && $stack === []) {
                $parts[] = trim(substr($args, $start, $i - $start));
                $start = $i + 1;
            }
        }
        if ($quote !== null || $stack !== [] || $comment === 'block') {
            throw new CompilerException($view, $line, 'Unterminated ' . $label . ' arguments.');
        }
        $parts[] = trim(substr($args, $start));
        if (count($parts) > 1 && end($parts) === '') { array_pop($parts); }
        return $parts;
    }

    /**
     * PHP's lexer owns heredoc/nowdoc string boundaries. Template closers and
     * argument commas inside the string must remain ordinary PHP content.
     */
    private function skipHeredoc(string $text, int $start, string $view, int $line, string $label): int
    {
        $tokens = token_get_all('<?php ' . substr($text, $start));
        $offset = -strlen('<?php ');
        $started = false;
        foreach ($tokens as $token) {
            $part = is_array($token) ? $token[1] : $token;
            if ($offset === 0 && is_array($token) && $token[0] === T_START_HEREDOC) {
                $started = true;
            } elseif ($started && is_array($token) && $token[0] === T_END_HEREDOC) {
                return $start + $offset + strlen($part) - 1;
            }
            $offset += strlen($part);
        }

        throw new CompilerException($view, $line, 'Unterminated heredoc in ' . $label . '.');
    }

    private function findPhpDirectiveEnd(string $text, int $start): ?int
    {
        $tokens = token_get_all('<?php ' . substr($text, $start));
        $offset = -strlen('<?php ');
        foreach ($tokens as $index => $token) {
            $part = is_array($token) ? $token[1] : $token;
            if ($offset >= 0 && $part === '@') {
                $next = $tokens[$index + 1] ?? null;
                if (is_array($next) && $next[0] === T_STRING && $next[1] === 'endphp') {
                    return $start + $offset;
                }
            }
            $offset += strlen($part);
        }
        return null;
    }

    private static function pairMatches(string $open, string $close): bool
    {
        return ($open === '(' && $close === ')')
            || ($open === '[' && $close === ']')
            || ($open === '{' && $close === '}');
    }

    private static function lineEndings(string $text): string
    {
        $endings = '';
        for ($i = 0, $length = strlen($text); $i < $length; ++$i) {
            if ($text[$i] === "\n" || $text[$i] === "\r") { $endings .= $text[$i]; }
        }
        return $endings;
    }

    /** @param list<array{type: string, value: string, line: int, name?: string}> $tokens */
    private static function flush(array &$tokens, string &$plain, int $line): void
    {
        if ($plain !== '') {
            $tokens[] = ['type' => 'text', 'value' => $plain, 'line' => $line];
            $plain = '';
        }
    }
}
