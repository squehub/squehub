<?php

declare(strict_types=1);

namespace App\Broadcasting\Adapters;

use App\Broadcasting\BroadcastAdapter;
use App\Broadcasting\BroadcastMessage;

/** Discards a message only when the application explicitly selects this adapter. */
final class NullBroadcastAdapter implements BroadcastAdapter
{
    public function publish(BroadcastMessage $message): void
    {
    }
}
