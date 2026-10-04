<?php

declare(strict_types=1);

namespace App\Mail;

/** Validated immutable address and optional display name for mail headers. */
final readonly class MailAddress
{
    public function __construct(private string $address, private ?string $name = null)
    {
        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\x00-\x1F\x7F]/', $address)) {
            throw new MailConfigurationException('Invalid mail address.');
        }
        // PHPMailer performs MIME encoding; controls are rejected here before
        // any untrusted display name can become part of a transport header.
        if ($name !== null && (strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name))) {
            throw new MailConfigurationException('Invalid mail display name.');
        }
    }

    public function address(): string { return $this->address; }
    public function name(): ?string { return $this->name; }
}
