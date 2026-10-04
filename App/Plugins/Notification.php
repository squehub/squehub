<?php

declare(strict_types=1);

namespace App\Plugins;

/** Application notification base; channels still receive the canonical type. */
abstract class Notification extends \App\Notifications\Notification
{
}
