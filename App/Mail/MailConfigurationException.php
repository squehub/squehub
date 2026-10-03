<?php

declare(strict_types=1);

namespace App\Mail;

/** Invalid deployment configuration or a message missing required send metadata. */
final class MailConfigurationException extends MailException
{
}
