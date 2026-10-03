<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application event trait backed by the canonical propagation state. */
trait StopsEventPropagation
{
    use \App\Events\StopsEventPropagation;
}
