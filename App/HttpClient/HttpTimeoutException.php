<?php

declare(strict_types=1);

namespace App\HttpClient;

/** The connection or overall deadline expired without a complete response. */
final class HttpTimeoutException extends HttpConnectionException {}
