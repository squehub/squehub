<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Optional read-only visibility for persistent drivers. The Sync driver has
 * no durable records to count, so inspection is a capability rather than a
 * requirement of every dispatch target.
 */
interface QueueStatusDriver
{
    public function status(string $queue): QueueStatus;
}
