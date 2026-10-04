<?php

declare(strict_types=1);

namespace App\Database\Seeding;

use LogicException;

/** Application data setup with explicit, ordered child Seeder calls. */
abstract class Seeder
{
    private ?SeederRunner $runner = null;

    abstract public function run(): void;

    /** @internal Bound by SeederRunner after container resolution. */
    final public function useRunner(SeederRunner $runner): void
    {
        $this->runner = $runner;
    }

    /** @param list<class-string<Seeder>> $seeders */
    final protected function call(array $seeders): void
    {
        if ($this->runner === null) {
            throw new LogicException('A Seeder must be run through SeederRunner to call child Seeders.');
        }
        foreach ($seeders as $seeder) {
            $this->runner->call($seeder);
        }
    }
}
