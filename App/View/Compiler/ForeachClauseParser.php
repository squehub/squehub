<?php

declare(strict_types=1);

namespace App\View\Compiler;

use ParseError;

/**
 * Finds foreach's top-level `as` without mistaking strings, closures, or
 * nested expressions for the separator. PHP validates target syntax itself.
 */
final class ForeachClauseParser
{
    /** @return array{iterable: string, target: string} */
    public function parse(string $body, string $view, int $line, string $directive): array
    {
        if (trim($body) === '') {
            throw new CompilerException($view, $line, '@' . $directive . ' requires an iterable and target.');
        }

        $prefix = '<?php ';
        $offset = -strlen($prefix);
        $depth = 0;
        $separator = null;
        foreach (token_get_all($prefix . $body) as $token) {
            $part = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_AS && $depth === 0) {
                if ($separator !== null) {
                    throw new CompilerException($view, $line,
                        '@' . $directive . ' contains more than one top-level as clause.');
                }
                $separator = $offset;
            } elseif (!is_array($token)) {
                if ($part === '(' || $part === '[' || $part === '{') {
                    ++$depth;
                } elseif ($part === ')' || $part === ']' || $part === '}') {
                    --$depth;
                }
            }
            $offset += strlen($part);
        }

        $iterable = $separator === null ? '' : trim(substr($body, 0, $separator));
        $target = $separator === null ? '' : trim(substr($body, $separator + strlen('as')));
        if ($iterable === '' || $target === '') {
            throw new CompilerException($view, $line,
                '@' . $directive . ' requires a top-level `iterable as target` clause.');
        }

        // Buffering Traversable input cannot preserve by-reference writes to
        // its source. Reject reference targets instead of silently changing
        // the established native-PHP behavior.
        foreach (token_get_all($prefix . $target) as $token) {
            if ($token === '&' || (is_array($token)
                && in_array($token[0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                    T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))) {
                throw new CompilerException($view, $line,
                    '@' . $directive . ' does not support by-reference targets.');
            }
            if (is_array($token) && $token[0] === T_VARIABLE
                && ($token[1] === '$loop' || str_starts_with($token[1], '$__squehub_'))) {
                throw new CompilerException($view, $line,
                    '@' . $directive . ' target cannot replace framework loop state.');
            }
        }

        try {
            $_ = token_get_all($prefix . 'foreach (' . $body . '): endforeach;', TOKEN_PARSE);
        } catch (ParseError) {
            // PHP's parser is authoritative; its source excerpt is deliberately
            // omitted from this logical-view diagnostic.
            throw new CompilerException($view, $line,
                '@' . $directive . ' has malformed iterable or target syntax.');
        }

        return ['iterable' => $iterable, 'target' => $target];
    }
}
