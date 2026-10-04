<?php

declare(strict_types=1);

namespace App\Plugins;

/**
 * Application model base. Inheritance preserves the canonical ORM's query,
 * hydration, casts, relations, persistence, and dirty-state behavior.
 */
abstract class Model extends \App\Database\Model
{
}
