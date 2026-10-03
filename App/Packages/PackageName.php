<?php

declare(strict_types=1);

namespace App\Packages;

/** Canonical Package identity shared by discovery, lifecycle, and generators. */
final class PackageName
{
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo',
        'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'instanceof', 'interface', 'isset', 'list',
        'match', 'namespace', 'new', 'null', 'or', 'parent', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'return', 'self', 'static',
        'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'while',
        'xor', 'yield', 'bool', 'float', 'int', 'iterable', 'mixed', 'never',
        'object', 'string', 'void',
    ];

    public static function valid(string $name): bool
    {
        return strlen($name) <= 120
            && preg_match('/\A[A-Z][A-Za-z0-9_]*\z/D', $name) === 1
            && !in_array(strtolower($name), self::RESERVED, true)
            && preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/iD', $name) !== 1;
    }

    public static function require(string $name): string
    {
        if (!self::valid($name)) {
            throw new PackageException('Package name must be an exact, capitalized PHP identifier.');
        }
        return $name;
    }
}
