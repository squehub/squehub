<?php

declare(strict_types=1);

namespace SqueHub\Tests\Unit;

use App\Packages\PackageDescriptor;
use App\Packages\PackageGraph;
use PHPUnit\Framework\TestCase;

/** The graph is a deterministic, Application-local view of Package metadata. */
final class PackageGraphTest extends TestCase
{
    public function testDiamondIsSortedAndSharedDependencyAppearsOnlyOnce(): void
    {
        $requirements = [
            'Shop' => ['Beta', 'Alpha'],
            'Inventory' => [],
            'Core' => [],
            'Beta' => ['Core'],
            'Alpha' => ['Core'],
        ];
        $first = $this->graph($requirements);
        $second = $this->graph(array_reverse($requirements, true));

        self::assertSame(['Alpha', 'Beta'], $first->dependenciesOf('Shop'));
        self::assertSame(['Alpha', 'Beta'], $first->dependentsOf('Core'));
        self::assertSame([], $first->dependenciesOf('Core'));
        self::assertSame([], $first->dependentsOf('Shop'));
        self::assertSame(
            ['Core', 'Alpha', 'Beta', 'Inventory', 'Shop'],
            $this->names($first->activationOrder())
        );
        self::assertSame($this->names($first->activationOrder()),
            $this->names($second->activationOrder()));
        self::assertSame($first->dependentsOf('Core'), $second->dependentsOf('Core'));
        self::assertFalse($first->isCyclic('Core'));
    }

    public function testDeepChainAndDisabledPackageHaveDistinctActivationStates(): void
    {
        $graph = $this->graph([
            'ChainA' => ['ChainB'],
            'ChainB' => ['ChainC'],
            'ChainC' => ['ChainD'],
            'ChainD' => ['ChainE'],
            'ChainE' => [],
            'Dormant' => [],
        ], ['Dormant' => 'disabled']);

        self::assertSame(['ChainE', 'ChainD', 'ChainC', 'ChainB', 'ChainA'],
            $this->names($graph->activationOrder()));
        self::assertSame(['ChainD'], $graph->dependentsOf('ChainE'));
        self::assertSame(['ChainB'], $graph->dependenciesOf('ChainA'));
        self::assertSame([], $graph->dependentsOf('Dormant'));

        $unrelated = $this->graph(['Other' => []]);
        self::assertSame(['Other'], $this->names($unrelated->activationOrder()));
        self::assertSame([], $unrelated->dependentsOf('ChainE'));
    }

    public function testOnlyMembersOfCycleAreIdentified(): void
    {
        $graph = $this->graph([
            'CycleA' => ['CycleB'],
            'CycleB' => ['CycleC'],
            'CycleC' => ['CycleA'],
            'Separate' => [],
            'UsesCycle' => ['CycleA'],
        ]);

        foreach (['CycleA', 'CycleB', 'CycleC'] as $name) {
            self::assertTrue($graph->isCyclic($name), $name);
        }
        self::assertSame(['CycleA', 'CycleB', 'CycleC', 'CycleA'],
            $graph->cyclePathFor('CycleA'));
        self::assertFalse($graph->isCyclic('UsesCycle'));
        self::assertFalse($graph->isCyclic('Separate'));
    }

    /**
     * @param array<string, list<string>> $requirements
     * @param array<string, string> $statuses
     */
    private function graph(array $requirements, array $statuses = []): PackageGraph
    {
        $descriptors = [];
        foreach ($requirements as $name => $dependencies) {
            $descriptors[$name] = new PackageDescriptor($name, '/fixture/' . $name,
                'Packages\\' . $name . '\\' . $name, '1.0.0', null, $dependencies,
                $statuses[$name] ?? 'enabled');
        }
        return new PackageGraph($descriptors);
    }

    /** @param list<PackageDescriptor> $descriptors
     *  @return list<string>
     */
    private function names(array $descriptors): array
    {
        return array_map(static fn (PackageDescriptor $descriptor): string => $descriptor->name(),
            $descriptors);
    }
}
