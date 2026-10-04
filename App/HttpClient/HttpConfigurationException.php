<?php

declare(strict_types=1);

namespace App\HttpClient;

/** Invalid request options are rejected before any transport operation. */
final class HttpConfigurationException extends HttpClientException {}
