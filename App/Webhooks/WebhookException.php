<?php

declare(strict_types=1);

namespace App\Webhooks;

use RuntimeException;

/** Safe subsystem failure; raw payloads, URLs, and credentials stay out of messages. */
class WebhookException extends RuntimeException
{
}
