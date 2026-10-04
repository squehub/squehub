<?php

declare(strict_types=1);

namespace App\Mail\Transports;

use App\Mail\MailMessage;
use App\Mail\MailTransport;

/** Process-local non-delivery outbox with independent snapshots for tests. */
final class ArrayMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    private array $outbox = [];

    public function send(MailMessage $message): void
    {
        $this->outbox[] = $message->snapshot();
    }

    /** @return list<MailMessage> Caller mutations cannot alter stored entries. */
    public function messages(): array
    {
        return array_map(static fn (MailMessage $message): MailMessage => $message->snapshot(), $this->outbox);
    }

    public function clear(): void { $this->outbox = []; }
}
