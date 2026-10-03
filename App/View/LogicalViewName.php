<?php

declare(strict_types=1);

namespace App\View;

use App\Packages\PackageName;

/** A validated logical View identity, with at most one explicit Package namespace. */
final readonly class LogicalViewName
{
    private const LOCAL_PATTERN = '/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/D';

    private function __construct(
        private string $name,
        private ?string $namespace,
        private string $local,
    ) {
    }

    public static function parse(string $name): ?self
    {
        if ($name === '') {
            return null;
        }

        $delimiter = strpos($name, '::');
        if ($delimiter === false) {
            return !str_contains($name, ':') && preg_match(self::LOCAL_PATTERN, $name) === 1
                ? new self($name, null, $name) : null;
        }

        if (strpos($name, '::', $delimiter + 2) !== false) {
            return null;
        }
        $namespace = substr($name, 0, $delimiter);
        $local = substr($name, $delimiter + 2);
        if (!PackageName::valid($namespace) || preg_match(self::LOCAL_PATTERN, $local) !== 1) {
            return null;
        }

        return new self($name, $namespace, $local);
    }

    public function name(): string { return $this->name; }

    public function namespace(): ?string { return $this->namespace; }

    public function local(): string { return $this->local; }

    /** Apply the established Components. lookup to the local name only. */
    public function component(): self
    {
        $local = 'Components.' . $this->local;
        $name = $this->namespace === null ? $local : $this->namespace . '::' . $local;
        return new self($name, $this->namespace, $local);
    }
}
