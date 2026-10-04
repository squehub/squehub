<?php

declare(strict_types=1);

namespace App\Kits;

/**
 * Lexically validates the one expected Kit entry without executing PHP.
 * Runtime inheritance is checked again when a trusted lifecycle hook runs.
 */
final class KitEntry
{
    public static function valid(string $file, string $name): bool
    {
        $source = @file_get_contents($file);
        if ($source === false || strlen($source) > 262144) { return false; }
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }
        $tokens = array_values(array_filter($tokens, static fn (mixed $token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $namespace = '';
        $uses = [];
        $depth = 0;
        $found = false;
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            $id = is_array($token) ? $token[0] : null;
            $literal = is_array($token) ? $token[1] : $token;
            if ($literal === '{') { ++$depth; continue; }
            if ($literal === '}') { --$depth; continue; }
            if ($depth !== 0) { continue; }
            if ($id === T_NAMESPACE || $id === T_USE) {
                $parts = [];
                $end = null;
                for ($next = $index + 1; $next < $count; ++$next) {
                    $piece = is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next];
                    if ($piece === ';' || $piece === '{') { $end = $piece; break; }
                    $parts[] = is_array($tokens[$next]) && $tokens[$next][0] === T_AS ? ' as ' : $piece;
                }
                if ($end !== ';') { return false; }
                $value = implode('', $parts);
                if ($id === T_NAMESPACE) {
                    $namespace = trim($value, '\\');
                } else {
                    foreach (explode(',', $value) as $import) {
                        if (preg_match('/\A([^ ]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\z/i',
                            trim($import), $match) === 1) {
                            $alias = $match[2] ?? substr($match[1], strrpos($match[1], '\\') + 1);
                            $uses[$alias] = trim($match[1], '\\');
                        }
                    }
                }
                $index = $next;
                continue;
            }
            if ($id !== T_CLASS) { continue; }
            $class = $tokens[$index + 1] ?? null;
            if ($found || !is_array($class) || $class[0] !== T_STRING || $class[1] !== $name) {
                return false;
            }
            $parent = null;
            for ($next = $index + 2; $next < $count; ++$next) {
                $part = $tokens[$next];
                $piece = is_array($part) ? $part[1] : $part;
                if ($piece === '{') { break; }
                if (is_array($part) && $part[0] === T_EXTENDS) {
                    $parentParts = [];
                    for ($at = $next + 1; $at < $count; ++$at) {
                        $segment = $tokens[$at];
                        if (!is_array($segment) || !in_array($segment[0],
                            [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                            break;
                        }
                        $parentParts[] = $segment[1];
                    }
                    $parent = implode('', $parentParts);
                }
            }
            if ($parent === null) { return false; }
            $resolved = $uses[$parent] ?? (str_contains($parent, '\\')
                ? trim($parent, '\\') : $namespace . '\\' . $parent);
            $found = $namespace === 'Project\\Kits\\' . $name
                && $resolved === 'App\\Plugins\\Kit';
        }
        return $found;
    }
}
