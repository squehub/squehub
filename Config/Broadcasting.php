<?php

declare(strict_types=1);

/** Broadcasting is opt-in; Array and Null adapters need no socket service. */
return [
    'enabled' => false,
    'driver' => 'null',
    'max_payload_bytes' => 32768,
];
