<?php

declare(strict_types=1);

namespace App\Broadcasting\Adapters;

use App\Broadcasting\BroadcastAdapter;
use App\Broadcasting\BroadcastMessage;

/** An Application-local outbox for deterministic tests, not a socket server. */
final class ArrayBroadcastAdapter implements BroadcastAdapter
{
    /** @var list<BroadcastMessage> */
    private array $messages = [];

    public function publish(BroadcastMessage $message): void { $this->messages[] = $message; }

    /** @return list<BroadcastMessage> */
    public function messages(): array { return $this->messages; }

    public function clear(): void { $this->messages = []; }
}
