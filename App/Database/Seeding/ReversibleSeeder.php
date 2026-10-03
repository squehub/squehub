<?php

declare(strict_types=1);

namespace App\Database\Seeding;

/**
 * Opts a Seeder into explicit reversal of effects that the Seeder owns.
 *
 * The runner has no execution history and cannot infer which records were
 * inserted by a prior run. Implementations must identify their own data and
 * check dependencies before removing it. No transaction is started for them.
 */
interface ReversibleSeeder
{
    /** Reverse only this Seeder's own effects; child Seeders are separate. */
    public function rollback(): void;
}
