<?php

declare(strict_types=1);

namespace App\HttpClient;

/** A network exchange did not produce an HTTP response. */
class HttpConnectionException extends HttpClientException {}
