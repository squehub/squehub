<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Http\Exception\HttpException;

/** An unauthenticated or malformed incoming delivery has one public error. */
final class InvalidWebhookException extends HttpException
{
    public function __construct()
    {
        parent::__construct(400, 'Invalid webhook.');
    }
}
