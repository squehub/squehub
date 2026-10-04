<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Mail\Mail as MailGateway;
use App\Mail\Mailer;
use App\Mail\MailMessage;

/** Modern Mail gateway; App\Core\Mail remains the separate legacy API. */
final class Mail
{
    public static function mailer(): Mailer { return MailGateway::manager(); }
    public static function send(MailMessage $message, ?string $via = null): void
    {
        self::mailer()->send($message, $via);
    }

    public static function queue(MailMessage $message, ?string $connection = null,
        string $queue = 'default', int $delay = 0, ?string $via = null,
        bool $afterCommit = false, ?string $transactionConnection = null): void
    {
        self::mailer()->queue($message, $connection, $queue, $delay, $via,
            $afterCommit, $transactionConnection);
    }
}
