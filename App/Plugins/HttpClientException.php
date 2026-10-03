<?php

declare(strict_types=1);

namespace App\Plugins;

/** Stable catch type for safe outbound transport and decoding failures. */
class_alias(\App\HttpClient\HttpClientException::class, __NAMESPACE__ . '\\HttpClientException');
