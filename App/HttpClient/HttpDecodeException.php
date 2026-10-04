<?php

declare(strict_types=1);

namespace App\HttpClient;

/** The received body cannot be decoded according to the requested format. */
final class HttpDecodeException extends HttpClientException {}
