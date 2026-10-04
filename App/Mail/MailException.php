<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/** A send or transport failure; messages never contain mail content or credentials. */
class MailException extends RuntimeException
{
}
