<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A safe provider failure category. Provider response text can contain email
 * addresses and message content, so it never becomes an exception message.
 */
final class MailProviderException extends MailException
{
    public function __construct(private readonly string $category)
    {
        parent::__construct(match ($category) {
            'authentication' => 'Mail provider authentication failed.',
            'validation' => 'Mail provider rejected the message.',
            'rate_limit' => 'Mail provider rate limit was reached.',
            'server' => 'Mail provider is unavailable.',
            'network' => 'Mail provider connection failed.',
            'malformed' => 'Mail provider returned an invalid response.',
            default => 'Mail provider send failed.',
        });
    }

    public function category(): string { return $this->category; }
}
