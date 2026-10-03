<?php

declare(strict_types=1);

namespace App\HttpClient;

/** An exchange produces a response or one safe transport exception. */
interface HttpTransport
{
    public function send(OutgoingRequest $request): HttpResponse;
}
