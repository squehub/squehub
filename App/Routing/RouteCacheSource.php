<?php

declare(strict_types=1);

namespace App\Routing;

use ParseError;

/**
 * Proves that a route file belongs to a deliberately small declaration grammar.
 * A cache hit skips PHP execution, so an ordinary registry snapshot is not
 * enough: arbitrary PHP may register routes and also perform unrelated work.
 * Every token must be consumed by this grammar before the file is cacheable.
 */
final class RouteCacheSource
{
    private const ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'fallback'];
    private const ROUTE_MODIFIERS = ['named', 'through', 'where', 'bind', 'apiVersion', 'contract'];
    private const GROUP_MODIFIERS = ['prefix', 'through', 'host', 'apiVersion'];
    private const CONTRACT_METHODS = ['summary', 'description', 'tags', 'deprecated', 'internal',
        'path', 'query', 'header', 'cookie', 'body', 'response', 'error', 'paginatedResponse',
        'pat', 'session', 'authorizationAbilities'];
    private const SCHEMA_METHODS = ['required', 'nullable', 'description', 'format', 'examples',
        'default', 'deprecated', 'readOnly', 'writeOnly', 'minimum', 'maximum',
        'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'minItems',
        'maxItems', 'pattern', 'uniqueItems', 'additionalProperties'];
    private const SCHEMA_FACTORIES = ['string', 'integer', 'number', 'boolean', 'null', 'true',
        'false', 'object', 'array', 'enum', 'oneOf', 'anyOf', 'allOf', 'ref'];

    /** @var list<array{text:string,id:int|null,line:int}> */
    private array $tokens = [];
    private int $position = 0;
    /** @var array<string,string> */
    private array $imports = [];

    private function __construct(private string $source)
    {
    }

    /** Reject unsupported source before its PHP code can be skipped later. */
    public static function assertCacheable(string $bytes, string $source): void
    {
        if (strlen($bytes) > 262144) {
            throw new RouteCacheException("Route file '{$source}' exceeds the cacheable size limit.");
        }
        $parser = new self($source);
        try {
            $tokens = token_get_all($bytes, TOKEN_PARSE);
        } catch (ParseError $exception) {
            throw new RouteCacheException("Route file '{$source}' contains invalid PHP.", 0, $exception);
        }
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $parser->tokens[] = ['text' => $token[1], 'id' => $token[0], 'line' => $token[2]];
            } else {
                $parser->tokens[] = ['text' => $token, 'id' => null,
                    'line' => $parser->tokens[count($parser->tokens) - 1]['line'] ?? 1];
            }
            if (count($parser->tokens) > 20000) {
                throw new RouteCacheException("Route file '{$source}' has too many tokens to cache.");
            }
        }
        $parser->parseFile();
    }

    private function parseFile(): void
    {
        if (!$this->acceptId(T_OPEN_TAG)) {
            $this->reject('a PHP opening tag is required');
        }
        if ($this->acceptId(T_DECLARE)) {
            $this->expect('(');
            $this->expectId(T_STRING, 'strict_types');
            $this->expect('=');
            $this->expectId(T_LNUMBER, '1');
            $this->expect(')');
            $this->expect(';');
        }
        while ($this->acceptId(T_USE)) {
            $name = $this->qualifiedName();
            $separator = strrpos($name, '\\');
            $alias = $separator === false ? $name : substr($name, $separator + 1);
            if ($this->acceptId(T_AS)) {
                $alias = $this->expectId(T_STRING);
            }
            $this->expect(';');
            $this->imports[$alias] = ltrim($name, '\\');
        }
        $this->statements(false);
        if ($this->peek() !== null) {
            $this->reject('unexpected PHP after route declarations');
        }
    }

    private function statements(bool $group): void
    {
        while (($token = $this->peek()) !== null && (!$group || $token['text'] !== '}')) {
            $name = $this->qualifiedName();
            if ($this->resolve($name) !== 'App\\Routing\\Route'
                && $this->resolve($name) !== 'App\\Plugins\\Route'
                && $this->resolve($name) !== 'App\\Core\\Route') {
                $this->reject('only the SqueHub Route API may be called at file scope');
            }
            $this->expect('::');
            $method = $this->expectId(T_STRING);
            if ($method === 'path') {
                $this->callArguments('path');
                if ($this->accept('->')) {
                    $first = $this->expectId(T_STRING);
                    if ($first === 'host') {
                        $this->callArguments('host');
                        $this->expect('->');
                        $first = $this->expectId(T_STRING);
                    }
                    if (!in_array($first, self::ROUTE_METHODS, true)) {
                        $this->reject('route needs a supported HTTP verb or fallback');
                    }
                    $this->callArguments($first);
                    while ($this->accept('->')) {
                        $modifier = $this->expectId(T_STRING);
                        if (!in_array($modifier, self::ROUTE_MODIFIERS, true)) {
                            $this->reject('route modifier is not cacheable');
                        }
                        $this->callArguments($modifier);
                    }
                } else {
                    $this->reject('route path must choose an HTTP verb or fallback');
                }
            } elseif ($method === 'resource') {
                $this->callArguments('resource');
            } elseif ($method === 'group') {
                $this->callArguments('group');
                $modifier = null;
                while ($this->accept('->')) {
                    $modifier = $this->expectId(T_STRING);
                    if ($modifier === 'routes') {
                        $this->expect('(');
                        $this->groupClosure();
                        $this->expect(')');
                        break;
                    }
                    if (!in_array($modifier, self::GROUP_MODIFIERS, true)) {
                        $this->reject('group modifier is not cacheable');
                    }
                    $this->callArguments($modifier);
                }
                if ($modifier !== 'routes') {
                    $this->reject('group must contain literal route declarations');
                }
            } else {
                $this->reject('Route API operation is not cacheable');
            }
            $this->expect(';');
        }
    }

    private function groupClosure(): void
    {
        $this->acceptId(T_STATIC);
        if (!$this->acceptId(T_FUNCTION)) {
            $this->reject('group needs a declaration-only Closure');
        }
        $this->expect('(');
        $this->expect(')');
        if ($this->accept(':')) {
            $this->expectId(T_STRING, 'void');
        }
        $this->expect('{');
        $this->statements(true);
        $this->expect('}');
    }

    private function callArguments(string $method): void
    {
        $this->expect('(');
        $count = 0;
        $kinds = [];
        while (($token = $this->peek()) !== null && $token['text'] !== ')') {
            if ($count > 0) {
                $this->expect(',');
                if (($this->peek()['text'] ?? null) === ')') {
                    break;
                }
            }
            if (($this->peek()['id'] ?? null) === T_STRING && ($this->peek(1)['text'] ?? null) === ':') {
                $this->position += 2; // Named argument; the actual method validates its name.
            }
            $kinds[] = $this->expression(0);
            ++$count;
            if ($count > 64) {
                $this->reject('too many declaration arguments');
            }
        }
        $this->expect(')');
        $literalOnly = ['path', 'host', 'named', 'through', 'where', 'bind', 'apiVersion',
            'prefix', 'resource'];
        if (in_array($method, $literalOnly, true)
            && count(array_diff($kinds, ['scalar', 'class', 'array'])) > 0) {
            $this->reject('route metadata must use literal values');
        }
        if (in_array($method, self::ROUTE_METHODS, true)
            && ($count !== 1 || !in_array($kinds[0], ['scalar', 'class', 'array'], true))) {
            $this->reject('route action must be a literal controller reference, not a Closure or object');
        }
        if ($method === 'contract' && ($count !== 1 || $kinds[0] !== 'contract')) {
            $this->reject('route contract must use the pure OperationContract builder');
        }
        if ($method === 'path' && ($count !== 1 || $kinds[0] !== 'scalar')) {
            $this->reject('route path must be a literal string');
        }
        if ($method === 'group' && $count !== 0) {
            $this->reject('group constructor does not accept arguments');
        }
    }

    /** @return 'scalar'|'class'|'array'|'schema'|'contract' */
    private function expression(int $depth): string
    {
        if ($depth > 32) {
            $this->reject('declaration expression is too deeply nested');
        }
        $token = $this->peek();
        if ($token === null) {
            $this->reject('declaration expression is incomplete');
        }
        if ($token['text'] === '[') {
            $this->expect('[');
            $count = 0;
            while ($this->peek() !== null && $this->peek()['text'] !== ']') {
                if ($count > 0) {
                    $this->expect(',');
                    if (($this->peek()['text'] ?? null) === ']') break;
                }
                $this->expression($depth + 1);
                if ($this->accept('=>')) $this->expression($depth + 1);
                if (++$count > 256) $this->reject('literal array is too large');
            }
            $this->expect(']');
            return 'array';
        }
        if ($token['text'] === '(') {
            $this->expect('(');
            $kind = $this->expression($depth + 1);
            $this->expect(')');
            return $this->fluent($kind, $depth);
        }
        if ($this->acceptId(T_NEW)) {
            if ($this->resolve($this->qualifiedName()) !== 'App\\Api\\Contract\\OperationContract') {
                $this->reject('only the pure OperationContract constructor is cacheable');
            }
            $this->nestedArguments($depth + 1);
            return $this->fluent('contract', $depth);
        }
        if (in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)) {
            ++$this->position;
            return 'scalar';
        }
        if ($token['text'] === '-') {
            ++$this->position;
            if (!$this->acceptId(T_LNUMBER) && !$this->acceptId(T_DNUMBER)) {
                $this->reject('numeric literal is invalid');
            }
            return 'scalar';
        }
        $name = $this->qualifiedName();
        if (in_array(strtolower($name), ['null', 'true', 'false'], true)) {
            return 'scalar';
        }
        $this->expect('::');
        $member = $this->expectId(T_STRING, null, [T_CLASS]);
        if (strtolower($member) === 'class') {
            return 'class';
        }
        if ($this->resolve($name) !== 'App\\Api\\Contract\\Schema'
            || !in_array($member, self::SCHEMA_FACTORIES, true)) {
            $this->reject('only class literals and pure Schema builders are cacheable');
        }
        $this->nestedArguments($depth + 1);
        return $this->fluent('schema', $depth);
    }

    /** @param 'scalar'|'class'|'array'|'schema'|'contract' $kind */
    private function fluent(string $kind, int $depth): string
    {
        while ($this->accept('->')) {
            $method = $this->expectId(T_STRING);
            $allowed = $kind === 'schema' ? self::SCHEMA_METHODS
                : ($kind === 'contract' ? self::CONTRACT_METHODS : []);
            if (!in_array($method, $allowed, true)) {
                $this->reject('method call is not a pure declaration builder');
            }
            $this->nestedArguments($depth + 1);
        }
        return $kind;
    }

    private function nestedArguments(int $depth): void
    {
        $this->expect('(');
        $count = 0;
        while ($this->peek() !== null && $this->peek()['text'] !== ')') {
            if ($count > 0) {
                $this->expect(',');
                if (($this->peek()['text'] ?? null) === ')') break;
            }
            if (($this->peek()['id'] ?? null) === T_STRING && ($this->peek(1)['text'] ?? null) === ':') {
                $this->position += 2;
            }
            $this->expression($depth + 1);
            if (++$count > 64) $this->reject('too many builder arguments');
        }
        $this->expect(')');
    }

    private function qualifiedName(): string
    {
        $token = $this->peek();
        if ($token === null || !in_array($token['id'], [T_STRING, T_NAME_QUALIFIED,
            T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)) {
            $this->reject('a class name is required');
        }
        $name = '';
        while (($token = $this->peek()) !== null && in_array($token['id'], [T_STRING,
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)) {
            $name .= $token['text'];
            ++$this->position;
        }
        if (preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\z/D', $name) !== 1) {
            $this->reject('class name is invalid');
        }
        return $name;
    }

    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) return ltrim($name, '\\');
        if ($name === 'Route' && !isset($this->imports['Route'])) return 'App\\Routing\\Route';
        if ($name === 'Schema' && !isset($this->imports['Schema'])) return 'App\\Api\\Contract\\Schema';
        if ($name === 'OperationContract' && !isset($this->imports['OperationContract'])) {
            return 'App\\Api\\Contract\\OperationContract';
        }
        $parts = explode('\\', $name, 2);
        return isset($this->imports[$parts[0]])
            ? $this->imports[$parts[0]] . (isset($parts[1]) ? '\\' . $parts[1] : '') : $name;
    }

    private function expect(string $text): void
    {
        if (!$this->accept($text)) $this->reject("expected '{$text}'");
    }

    private function accept(string $text): bool
    {
        if (($this->peek()['text'] ?? null) !== $text) return false;
        ++$this->position;
        return true;
    }

    private function acceptId(int $id): bool
    {
        if (($this->peek()['id'] ?? null) !== $id) return false;
        ++$this->position;
        return true;
    }

    /** @param list<int> $extraIds */
    private function expectId(int $id, ?string $value = null, array $extraIds = []): string
    {
        $token = $this->peek();
        if ($token === null || !in_array($token['id'], [$id, ...$extraIds], true)
            || ($value !== null && $token['text'] !== $value)) {
            $this->reject('unexpected declaration token');
        }
        ++$this->position;
        return $token['text'];
    }

    /** @return array{text:string,id:int|null,line:int}|null */
    private function peek(int $offset = 0): ?array
    {
        return $this->tokens[$this->position + $offset] ?? null;
    }

    private function reject(string $reason): never
    {
        $line = $this->peek()['line'] ?? ($this->tokens[count($this->tokens) - 1]['line'] ?? 1);
        throw new RouteCacheException("Route file '{$this->source}' is not cacheable at line {$line}: {$reason}.");
    }
}
