<?php

declare(strict_types=1);

/** Outbound HTTP defaults contain no provider credentials or target URLs. */
return [
    'connect_timeout' => 5,
    'timeout' => 15,
    'verify_peer' => true,
    'max_redirects' => 5,
    'max_body_bytes' => 10485760,
    'max_request_bytes' => 10485760,
    'user_agent' => 'SqueHub/2',
];
