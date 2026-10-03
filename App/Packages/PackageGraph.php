<?php

declare(strict_types=1);

namespace App\Packages;

/**
 * One immutable, application-scoped view of Package dependency relationships.
 *
 * Edges are names from static metadata. Sorting them here makes graph queries
 * independent of directory enumeration and composer.json declaration order.
 */
final class PackageGraph
{
    /** @var array<string, PackageDescriptor> */
    private array $descriptors;

    /** @var array<string, list<string>> */
    private array $dependencies = [];

    /** @var array<string, list<string>> */
    private array $dependents = [];

    /** @var array<string, true> Names belonging to a dependency cycle. */
    private array $cyclic = [];

    /** @param array<string, PackageDescriptor> $descriptors */
    public function __construct(array $descriptors)
    {
        ksort($descriptors, SORT_STRING);
        $this->descriptors = $descriptors;
        foreach ($descriptors as $name => $descriptor) {
            $dependencies = $descriptor->dependencies();
            sort($dependencies, SORT_STRING);
            $this->dependencies[$name] = $dependencies;
            $this->dependents[$name] = [];
        }
        foreach ($this->dependencies as $name => $dependencies) {
            foreach ($dependencies as $dependency) {
                if (isset($this->dependents[$dependency])) {
                    $this->dependents[$dependency][] = $name;
                }
            }
        }
        foreach ($this->dependents as &$dependents) {
            sort($dependents, SORT_STRING);
        }
        unset($dependents);
        $this->markCycles();
    }

    /** @return array<string, PackageDescriptor> */
    public function descriptors(): array
    {
        return $this->descriptors;
    }

    /** @return list<string> Direct declared requirements. */
    public function dependenciesOf(string $name): array
    {
        return $this->dependencies[$name] ?? [];
    }

    /** @return list<string> Direct installed dependents, regardless of state. */
    public function dependentsOf(string $name): array
    {
        return $this->dependents[$name] ?? [];
    }

    public function isCyclic(string $name): bool
    {
        return isset($this->cyclic[$name]);
    }

    /**
     * Return the shortest cycle through this member, with a stable name-order
     * tie break. The repeated final name makes the closing edge explicit.
     *
     * @return list<string>
     */
    public function cyclePathFor(string $name): array
    {
        if (!$this->isCyclic($name)) { return []; }
        $queue = [$name];
        $seen = [$name => true];
        $parent = [];
        for ($head = 0; $head < count($queue); ++$head) {
            $current = $queue[$head];
            foreach ($this->dependenciesOf($current) as $dependency) {
                if ($dependency === $name) {
                    $path = [$current];
                    while ($path[count($path) - 1] !== $name) {
                        $path[] = $parent[$path[count($path) - 1]];
                    }
                    return [...array_reverse($path), $name];
                }
                if (!isset($this->descriptors[$dependency]) || isset($seen[$dependency])) { continue; }
                $seen[$dependency] = true;
                $parent[$dependency] = $current;
                $queue[] = $dependency;
            }
        }
        return [];
    }

    /** @return list<PackageDescriptor> Enabled Packages in stable dependency order. */
    public function activationOrder(): array
    {
        $seen = [];
        $ordered = [];
        $visit = function (string $name) use (&$visit, &$seen, &$ordered): void {
            if (isset($seen[$name])) { return; }
            if ($this->isCyclic($name)) {
                throw new PackageException('Package dependency cycle detected.');
            }
            $seen[$name] = true;
            foreach ($this->dependenciesOf($name) as $dependency) {
                if (($this->descriptors[$dependency] ?? null)?->status() === 'enabled') {
                    $visit($dependency);
                }
            }
            $ordered[] = $this->descriptors[$name];
        };
        foreach ($this->descriptors as $name => $descriptor) {
            if ($descriptor->status() === 'enabled') {
                $visit($name);
            }
        }
        return $ordered;
    }

    /** Mark strongly connected components in one pass, including self edges. */
    private function markCycles(): void
    {
        $nextIndex = 0;
        $indices = [];
        $lowLinks = [];
        $stack = [];
        $onStack = [];
        $visit = function (string $name) use (&$visit, &$nextIndex, &$indices,
            &$lowLinks, &$stack, &$onStack): void {
            $indices[$name] = $nextIndex;
            $lowLinks[$name] = $nextIndex;
            ++$nextIndex;
            $stack[] = $name;
            $onStack[$name] = true;
            foreach ($this->dependenciesOf($name) as $dependency) {
                if (!isset($this->descriptors[$dependency])) { continue; }
                if (!array_key_exists($dependency, $indices)) {
                    $visit($dependency);
                    $lowLinks[$name] = min($lowLinks[$name], $lowLinks[$dependency]);
                } elseif (isset($onStack[$dependency])) {
                    $lowLinks[$name] = min($lowLinks[$name], $indices[$dependency]);
                }
            }
            if ($lowLinks[$name] !== $indices[$name]) { return; }
            $component = [];
            do {
                $member = array_pop($stack);
                if (!is_string($member)) { break; }
                unset($onStack[$member]);
                $component[] = $member;
            } while ($member !== $name);
            if (count($component) > 1 || in_array($name, $this->dependenciesOf($name), true)) {
                foreach ($component as $member) { $this->cyclic[$member] = true; }
            }
        };
        foreach (array_keys($this->descriptors) as $name) {
            if (!array_key_exists($name, $indices)) { $visit($name); }
        }
    }
}
