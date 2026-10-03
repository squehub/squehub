<?php

declare(strict_types=1);

namespace App\Mail;

/** Receives one prepared message; connection and delivery mechanics stay here. */
interface MailTransport
{
    public function send(MailMessage $message): void;
}
