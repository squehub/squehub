<?php

declare(strict_types=1);

/** Small operational policy; request IDs themselves are always generated. */
return [
    'response_header' => true,
    'database' => true,
    // Null disables slow-query counting; use a finite non-negative number of milliseconds.
    'slow_query_ms' => null,
];
