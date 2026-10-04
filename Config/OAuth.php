<?php

declare(strict_types=1);

/** Named OIDC providers are opt-in. No credentials or endpoints ship by default. */
return [
    'transaction_ttl' => 600,
    'max_outstanding' => 8,
    'providers' => [],
];
