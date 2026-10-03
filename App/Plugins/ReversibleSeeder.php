<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application-facing alias for a Seeder with an explicit rollback contract. */
class_alias(\App\Database\Seeding\ReversibleSeeder::class, __NAMESPACE__ . '\\ReversibleSeeder');
