<?php

declare(strict_types=1);

namespace App\Broadcasting;

/** Publishes a validated message; a provider adapter owns its network protocol. */
interface BroadcastAdapter
{
    public function publish(BroadcastMessage $message): void;
}
