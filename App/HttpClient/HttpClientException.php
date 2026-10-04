<?php

declare(strict_types=1);

namespace App\HttpClient;

/** Safe public boundary for outbound HTTP failures; messages contain no URL or payload. */
class HttpClientException extends \RuntimeException {}
